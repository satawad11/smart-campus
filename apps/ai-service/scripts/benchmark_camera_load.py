#!/usr/bin/env python3
"""CAM-020 — 1/2/4/8-camera load benchmark.

Simulates N concurrent camera pipelines (VideoSource -> FrameSampler ->
Detector -> Tracker -> RecognitionWorkerPool) entirely with MOCK/SYNTHETIC
components — MockVideoSource (synthetic frames), MockFaceDetector,
MockFaceAligner/MockFaceQualityEvaluator/MockFaceEmbedder (Module 03), and a
fake in-process backend client (no real network, no real GPU, no real
camera). This measures the AI-node-side pipeline's own throughput/backpressure
behavior under concurrent camera load — it does NOT measure real RTSP
decode cost, real model inference latency, or real network conditions, and
must never be read as a substitute for physical-camera validation (see the
"Physical Validation" section of docs/CAM-020-LOAD-BENCHMARK.md, which
generate_report() below always marks PENDING, never failing).

Usage: python scripts/benchmark_camera_load.py [--duration 5] [--target-fps 10]
"""

from __future__ import annotations

import argparse
import statistics
import threading
import time
from dataclasses import dataclass, field
from datetime import UTC, datetime

from app.alignment.mock import MockFaceAligner
from app.detection.mock import MockFaceDetector
from app.embeddings.mock import MockFaceEmbedder
from app.orchestration import BackendRecognitionResponse, RecognitionWorker, RecognitionWorkerPool
from app.quality.mock import MockFaceQualityEvaluator
from app.sampling.sampler import FrameSampler
from app.streams.mock import MockVideoSource
from app.tracking.tracker import IoUTracker


class FakeBackendClient:
    """No real HTTP call — deterministic, near-zero-latency response, so
    this benchmark measures the AI node's own pipeline overhead rather than
    a real backend's response time."""

    def recognize(self, embedding, model_code, model_version):
        return BackendRecognitionResponse(
            classification="UNKNOWN",
            candidate_person_uuid=None,
            similarity=None,
            distance=None,
            reasons=[],
        )


@dataclass
class CameraStats:
    camera_uuid: str
    frames_read: int = 0
    frames_sampled: int = 0
    frames_dropped_by_rate: int = 0
    frames_evicted: int = 0
    recognitions_submitted: int = 0
    recognitions_dropped_backpressure: int = 0
    recognitions_completed: int = 0


@dataclass
class BenchmarkResult:
    camera_count: int
    duration_seconds: float
    target_fps: float
    per_camera: list[CameraStats] = field(default_factory=list)

    @property
    def total_frames_sampled(self) -> int:
        return sum(c.frames_sampled for c in self.per_camera)

    @property
    def total_recognitions_completed(self) -> int:
        return sum(c.recognitions_completed for c in self.per_camera)

    @property
    def total_recognitions_dropped(self) -> int:
        return sum(c.recognitions_dropped_backpressure for c in self.per_camera)

    @property
    def achieved_fps(self) -> float:
        return self.total_frames_sampled / self.duration_seconds

    @property
    def recognition_throughput_per_sec(self) -> float:
        return self.total_recognitions_completed / self.duration_seconds


def _run_one_camera(
    camera_uuid: str, duration_seconds: float, target_fps: float, stats: CameraStats
) -> None:
    source = MockVideoSource(num_frames=None, name=camera_uuid)
    source.open()
    sampler = FrameSampler(source, camera_uuid=camera_uuid, target_fps=target_fps, queue_size=5)
    detector = MockFaceDetector()
    tracker = IoUTracker()
    worker = RecognitionWorker(
        camera_uuid=camera_uuid,
        ai_node_uuid="benchmark-node",
        aligner=MockFaceAligner(),
        quality_evaluator=MockFaceQualityEvaluator(force_accepted=True),
        embedder=MockFaceEmbedder(),
        backend_client=FakeBackendClient(),
        model_code="arcface-r50-v1",
        model_version="1.0.0",
    )
    pool = RecognitionWorkerPool(worker, max_workers=2, queue_size=10)

    deadline = time.monotonic() + duration_seconds
    poll_interval = 1.0 / (target_fps * 20)  # avoid needless busy-spin CPU contention
    try:
        while time.monotonic() < deadline:
            sampled = sampler.sample()
            stats.frames_read = sampler.read_count
            if sampled is None:
                time.sleep(poll_interval)
                continue
            stats.frames_sampled += 1

            detections = detector.detect(sampled.frame.data)
            tracks = tracker.update(detections)
            if not tracks:
                continue

            accepted = pool.submit(sampled, detections[0], tracks[0])
            if accepted:
                stats.recognitions_submitted += 1
            else:
                stats.recognitions_dropped_backpressure += 1

            while True:
                result = pool.poll_result(timeout=0.0)
                if result is None:
                    break
                stats.recognitions_completed += 1
    finally:
        stats.frames_dropped_by_rate = sampler.dropped_count
        stats.frames_evicted = sampler.evicted_count
        # Grace-drain: let in-flight submissions finish before shutdown.
        drain_deadline = time.monotonic() + 2.0
        while time.monotonic() < drain_deadline:
            result = pool.poll_result(timeout=0.1)
            if result is not None:
                stats.recognitions_completed += 1
            elif pool.pending_count == 0:
                break
        pool.shutdown(wait=True)
        source.close()


def run_benchmark(camera_count: int, duration_seconds: float, target_fps: float) -> BenchmarkResult:
    result = BenchmarkResult(
        camera_count=camera_count, duration_seconds=duration_seconds, target_fps=target_fps
    )
    threads = []
    stats_list = [CameraStats(camera_uuid=f"bench-cam-{i}") for i in range(camera_count)]

    for stats in stats_list:
        t = threading.Thread(
            target=_run_one_camera, args=(stats.camera_uuid, duration_seconds, target_fps, stats)
        )
        threads.append(t)

    start = time.monotonic()
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    actual_duration = time.monotonic() - start

    result.duration_seconds = actual_duration
    result.per_camera = stats_list
    return result


def generate_report(results: list[BenchmarkResult]) -> str:
    lines = [
        "# CAM-020 — 1/2/4/8-Camera Load Benchmark",
        "",
        f"Generated: {datetime.now(UTC).isoformat()}",
        "",
        "## Data Source Labeling",
        "",
        "**MOCK/SYNTHETIC** — every result in this report was produced entirely with "
        "synthetic in-process components: `MockVideoSource` (procedurally generated frames, "
        "no real RTSP decode), `MockFaceDetector`/`MockFaceAligner`/`MockFaceQualityEvaluator`/"
        "`MockFaceEmbedder` (Module 03 deterministic mocks, no real model inference), and a "
        "fake near-zero-latency backend client (no real network call, no real pgvector search). "
        "**No RECORDED video file and no PHYSICAL camera were used.** This measures the AI-node "
        "pipeline's own concurrency/backpressure behavior (frame sampling throttle, tracker, "
        "worker-pool queueing) — never real RTSP decode cost, real inference latency, or real "
        "network/camera conditions.",
        "",
        "## Method",
        "",
        f"For each camera count N in {[r.camera_count for r in results]}, N independent "
        "simulated camera pipelines run concurrently (one Python thread each) for the same "
        "wall-clock duration, each driving its own "
        "MockVideoSource -> FrameSampler -> MockFaceDetector -> IoUTracker -> "
        "RecognitionWorkerPool (max_workers=2 per camera). Frame sampling target rate and "
        "queue/backpressure policy are the same CAM-007/CAM-009 code paths used in production, "
        "not benchmark-only reimplementations.",
        "",
        "## Results",
        "",
        "| Cameras | Duration (s) | Target FPS | Achieved FPS (sampled) | "
        "Recognitions Completed | Recognitions Dropped (backpressure) | "
        "Frames Dropped (rate throttle) |",
        "|---|---|---|---|---|---|---|",
    ]

    for r in results:
        lines.append(
            f"| {r.camera_count} | {r.duration_seconds:.2f} | {r.target_fps:.1f} | "
            f"{r.achieved_fps:.2f} | {r.total_recognitions_completed} | "
            f"{r.total_recognitions_dropped} | "
            f"{sum(c.frames_dropped_by_rate for c in r.per_camera)} |"
        )

    lines += [
        "",
        "## Per-Camera Breakdown",
        "",
    ]
    for r in results:
        lines.append(f"### {r.camera_count} camera(s)")
        lines.append("")
        lines.append(
            "| Camera | Frames Read | Frames Sampled | Rate-Dropped | Evicted | "
            "Submitted | Backpressure-Dropped | Completed |"
        )
        lines.append("|---|---|---|---|---|---|---|---|")
        for c in r.per_camera:
            lines.append(
                f"| {c.camera_uuid} | {c.frames_read} | {c.frames_sampled} | "
                f"{c.frames_dropped_by_rate} | {c.frames_evicted} | {c.recognitions_submitted} | "
                f"{c.recognitions_dropped_backpressure} | {c.recognitions_completed} |"
            )
        lines.append("")

    if len(results) >= 2:
        fps_values = [r.achieved_fps for r in results]
        lines += [
            "## Scaling Observation",
            "",
            f"Achieved sampled-FPS across camera counts: "
            f"{', '.join(f'{r.camera_count}c={r.achieved_fps:.1f}' for r in results)}. "
            f"Mean per-run achieved FPS: {statistics.mean(fps_values):.2f}.",
            "",
        ]

    lines += [
        "## Physical Validation",
        "",
        "**PENDING.** This benchmark has not been run against a real RTSP camera stream or a "
        "recorded video file, and no physical AI-node hardware (GPU, real network conditions) "
        "was exercised. Real-world throughput will differ — likely be lower — due to actual "
        "RTSP decode cost, real model inference time, and real network latency to the backend. "
        "This is intentionally marked PENDING rather than failing: physical-camera and "
        "recorded-video validation require hardware/environment not available in this "
        "container-based development environment and are deferred to pilot deployment "
        "(SEC-018/SEC-020).",
        "",
    ]

    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description="CAM-020 camera load benchmark")
    parser.add_argument("--duration", type=float, default=5.0, help="Seconds per camera-count run")
    parser.add_argument(
        "--target-fps", type=float, default=10.0, help="Per-camera target sample FPS"
    )
    parser.add_argument(
        "--camera-counts", type=int, nargs="+", default=[1, 2, 4, 8], help="Camera counts to test"
    )
    parser.add_argument(
        "--output", type=str, default=None, help="Path to write the markdown report"
    )
    args = parser.parse_args()

    results = []
    for count in args.camera_counts:
        print(
            f"Running benchmark: {count} camera(s), {args.duration}s "
            f"@ {args.target_fps} target fps..."
        )
        result = run_benchmark(count, args.duration, args.target_fps)
        print(
            f"  achieved_fps={result.achieved_fps:.2f} "
            f"recognitions_completed={result.total_recognitions_completed} "
            f"recognitions_dropped={result.total_recognitions_dropped}"
        )
        results.append(result)

    report = generate_report(results)
    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            f.write(report)
        print(f"Report written to {args.output}")
    else:
        print(report)


if __name__ == "__main__":
    main()
