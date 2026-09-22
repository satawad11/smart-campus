# CAM-020 — 1/2/4/8-Camera Load Benchmark

Generated: 2026-09-22T15:24:14.491191+00:00

## Data Source Labeling

**MOCK/SYNTHETIC** — every result in this report was produced entirely with synthetic in-process components: `MockVideoSource` (procedurally generated frames, no real RTSP decode), `MockFaceDetector`/`MockFaceAligner`/`MockFaceQualityEvaluator`/`MockFaceEmbedder` (Module 03 deterministic mocks, no real model inference), and a fake near-zero-latency backend client (no real network call, no real pgvector search). **No RECORDED video file and no PHYSICAL camera were used.** This measures the AI-node pipeline's own concurrency/backpressure behavior (frame sampling throttle, tracker, worker-pool queueing) — never real RTSP decode cost, real inference latency, or real network/camera conditions.

## Method

For each camera count N in [1, 2, 4, 8], N independent simulated camera pipelines run concurrently (one Python thread each) for the same wall-clock duration, each driving its own MockVideoSource -> FrameSampler -> MockFaceDetector -> IoUTracker -> RecognitionWorkerPool (max_workers=2 per camera). Frame sampling target rate and queue/backpressure policy are the same CAM-007/CAM-009 code paths used in production, not benchmark-only reimplementations.

## Results

| Cameras | Duration (s) | Target FPS | Achieved FPS (sampled) | Recognitions Completed | Recognitions Dropped (backpressure) | Frames Dropped (rate throttle) |
|---|---|---|---|---|---|---|
| 1 | 5.11 | 10.0 | 9.59 | 49 | 0 | 949 |
| 2 | 5.11 | 10.0 | 19.19 | 98 | 0 | 1873 |
| 4 | 5.11 | 10.0 | 38.36 | 196 | 0 | 3783 |
| 8 | 5.12 | 10.0 | 76.55 | 392 | 0 | 7616 |

## Per-Camera Breakdown

### 1 camera(s)

| Camera | Frames Read | Frames Sampled | Rate-Dropped | Evicted | Submitted | Backpressure-Dropped | Completed |
|---|---|---|---|---|---|---|---|
| bench-cam-0 | 998 | 49 | 949 | 44 | 49 | 0 | 49 |

### 2 camera(s)

| Camera | Frames Read | Frames Sampled | Rate-Dropped | Evicted | Submitted | Backpressure-Dropped | Completed |
|---|---|---|---|---|---|---|---|
| bench-cam-0 | 985 | 49 | 936 | 44 | 49 | 0 | 49 |
| bench-cam-1 | 986 | 49 | 937 | 44 | 49 | 0 | 49 |

### 4 camera(s)

| Camera | Frames Read | Frames Sampled | Rate-Dropped | Evicted | Submitted | Backpressure-Dropped | Completed |
|---|---|---|---|---|---|---|---|
| bench-cam-0 | 995 | 49 | 946 | 44 | 49 | 0 | 49 |
| bench-cam-1 | 995 | 49 | 946 | 44 | 49 | 0 | 49 |
| bench-cam-2 | 996 | 49 | 947 | 44 | 49 | 0 | 49 |
| bench-cam-3 | 993 | 49 | 944 | 44 | 49 | 0 | 49 |

### 8 camera(s)

| Camera | Frames Read | Frames Sampled | Rate-Dropped | Evicted | Submitted | Backpressure-Dropped | Completed |
|---|---|---|---|---|---|---|---|
| bench-cam-0 | 1004 | 49 | 955 | 44 | 49 | 0 | 49 |
| bench-cam-1 | 1003 | 49 | 954 | 44 | 49 | 0 | 49 |
| bench-cam-2 | 1000 | 49 | 951 | 44 | 49 | 0 | 49 |
| bench-cam-3 | 999 | 49 | 950 | 44 | 49 | 0 | 49 |
| bench-cam-4 | 1003 | 49 | 954 | 44 | 49 | 0 | 49 |
| bench-cam-5 | 1001 | 49 | 952 | 44 | 49 | 0 | 49 |
| bench-cam-6 | 999 | 49 | 950 | 44 | 49 | 0 | 49 |
| bench-cam-7 | 999 | 49 | 950 | 44 | 49 | 0 | 49 |

## Scaling Observation

Achieved sampled-FPS across camera counts: 1c=9.6, 2c=19.2, 4c=38.4, 8c=76.5. Mean per-run achieved FPS: 35.92.

## Physical Validation

**PENDING.** This benchmark has not been run against a real RTSP camera stream or a recorded video file, and no physical AI-node hardware (GPU, real network conditions) was exercised. Real-world throughput will differ — likely be lower — due to actual RTSP decode cost, real model inference time, and real network latency to the backend. This is intentionally marked PENDING rather than failing: physical-camera and recorded-video validation require hardware/environment not available in this container-based development environment and are deferred to pilot deployment (SEC-018/SEC-020).
