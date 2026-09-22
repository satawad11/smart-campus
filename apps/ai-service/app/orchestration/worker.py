"""Recognition worker orchestration (CAM-009/CAM-010).

Composes the existing Module 03 pipeline (detection is done upstream by the
tracker/sampler already; this module runs alignment -> quality -> [liveness]
-> embedding) and calls the backend's internal recognize endpoint. No AI
algorithm is reimplemented here — only orchestration. No access decision is
ever produced.
"""

import queue
import threading
import uuid
from collections.abc import Callable
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from datetime import UTC, datetime

from app.alignment.base import FaceAligner
from app.caching.cache import RecognitionCache
from app.detection.schemas import FaceDetection
from app.embeddings.base import FaceEmbedder
from app.liveness.base import FaceLivenessDetector
from app.orchestration.backend_client import RecognitionBackendClient, RecognitionBackendError
from app.orchestration.schemas import RecognitionWorkerResult
from app.quality.base import FaceQualityEvaluator
from app.sampling.schemas import SampledFrame
from app.tracking.schemas import Track


class RecognitionWorker:
    """Synchronous per-(frame, track) recognition pipeline for one camera.

    Avoids re-running recognition on every frame of an already-classified,
    still-tracked face via a RecognitionCache (CAM-010): a cache hit returns
    the previously computed result (result.from_cache=True) without
    re-running any pipeline stage. The cache itself owns TTL expiry, model/
    version-change invalidation, and stale-track-reuse protection — see
    app.caching.cache.RecognitionCache.
    """

    def __init__(
        self,
        camera_uuid: str,
        ai_node_uuid: str,
        aligner: FaceAligner,
        quality_evaluator: FaceQualityEvaluator,
        embedder: FaceEmbedder,
        backend_client: RecognitionBackendClient,
        model_code: str,
        model_version: str,
        liveness_detector: FaceLivenessDetector | None = None,
        cache: RecognitionCache | None = None,
        id_factory: Callable[[], str] | None = None,
    ):
        self._camera_uuid = camera_uuid
        self._ai_node_uuid = ai_node_uuid
        self._aligner = aligner
        self._quality_evaluator = quality_evaluator
        self._embedder = embedder
        self._backend_client = backend_client
        self._model_code = model_code
        self._model_version = model_version
        self._liveness_detector = liveness_detector
        self._id_factory = id_factory or (lambda: str(uuid.uuid4()))
        self._cache = cache if cache is not None else RecognitionCache()

    @property
    def cache(self) -> RecognitionCache:
        return self._cache

    def should_process(self, track: Track) -> bool:
        """True if there is no valid (unexpired, same-model, same-track-
        generation) cached result for this track — i.e. the pipeline would
        actually run on the next process() call."""
        cached = self._cache.get(self._camera_uuid, track, self._model_code, self._model_version)
        return cached is None

    def process(
        self, sampled_frame: SampledFrame, detection: FaceDetection, track: Track
    ) -> RecognitionWorkerResult:
        cached = self._cache.get(self._camera_uuid, track, self._model_code, self._model_version)
        if cached is not None:
            return replace(cached, from_cache=True)

        observed_at = sampled_frame.frame.captured_at
        event_uuid = self._id_factory()

        aligned = self._aligner.align(sampled_frame.frame.data, detection)

        quality = self._quality_evaluator.evaluate(aligned, detection)
        if not quality.accepted:
            result = RecognitionWorkerResult(
                event_uuid=event_uuid,
                camera_uuid=self._camera_uuid,
                ai_node_uuid=self._ai_node_uuid,
                track_id=track.track_id,
                observed_at=observed_at,
                classification="UNKNOWN",
                candidate_person_uuid=None,
                similarity=None,
                distance=None,
                model_code=self._model_code,
                model_version=self._model_version,
                quality_score=quality.quality_score,
                liveness_status=None,
                rejected_locally=True,
                reasons=[str(r) for r in quality.rejection_reasons] or ["LOW_QUALITY"],
            )
            self._cache.put(self._camera_uuid, track, result, self._model_code, self._model_version)
            return result

        liveness_status = None
        if self._liveness_detector is not None:
            liveness = self._liveness_detector.evaluate(sampled_frame.frame.data)
            liveness_status = liveness.status
            if not liveness.is_live:
                result = RecognitionWorkerResult(
                    event_uuid=event_uuid,
                    camera_uuid=self._camera_uuid,
                    ai_node_uuid=self._ai_node_uuid,
                    track_id=track.track_id,
                    observed_at=observed_at,
                    classification="UNKNOWN",
                    candidate_person_uuid=None,
                    similarity=None,
                    distance=None,
                    model_code=self._model_code,
                    model_version=self._model_version,
                    quality_score=quality.quality_score,
                    liveness_status=liveness_status,
                    rejected_locally=True,
                    reasons=liveness.rejection_reasons or ["LIVENESS_FAILED"],
                )
                self._cache.put(
                    self._camera_uuid, track, result, self._model_code, self._model_version
                )
                return result

        embedding = self._embedder.generate(aligned)

        try:
            backend_result = self._backend_client.recognize(
                embedding=embedding.vector,
                model_code=embedding.model_code,
                model_version=embedding.model_version,
            )
        except RecognitionBackendError as exc:
            # Not cached: a transient backend outage should not pin this
            # track to UNKNOWN for the cache TTL — retry on the next frame.
            return RecognitionWorkerResult(
                event_uuid=event_uuid,
                camera_uuid=self._camera_uuid,
                ai_node_uuid=self._ai_node_uuid,
                track_id=track.track_id,
                observed_at=observed_at,
                classification="UNKNOWN",
                candidate_person_uuid=None,
                similarity=None,
                distance=None,
                model_code=embedding.model_code,
                model_version=embedding.model_version,
                quality_score=quality.quality_score,
                liveness_status=liveness_status,
                rejected_locally=True,
                reasons=[f"BACKEND_UNAVAILABLE: {exc}"],
            )

        result = RecognitionWorkerResult(
            event_uuid=event_uuid,
            camera_uuid=self._camera_uuid,
            ai_node_uuid=self._ai_node_uuid,
            track_id=track.track_id,
            observed_at=observed_at,
            classification=backend_result.classification,
            candidate_person_uuid=backend_result.candidate_person_uuid,
            similarity=backend_result.similarity,
            distance=backend_result.distance,
            model_code=embedding.model_code,
            model_version=embedding.model_version,
            quality_score=quality.quality_score,
            liveness_status=liveness_status,
            rejected_locally=False,
            reasons=backend_result.reasons,
        )
        self._cache.put(self._camera_uuid, track, result, self._model_code, self._model_version)
        return result


class RecognitionWorkerPool:
    """Bounded worker-pool wrapper around RecognitionWorker.

    submit() is non-blocking: if the input queue is full, the new work item
    is dropped (not blocked on, not silently queued without limit) —
    matching the project-wide backpressure policy (prefer dropping frames
    over unbounded growth; this queue holds pending recognition *work*, not
    already-created durable events, which is a different reliability class
    owned later by CAM-011/012).
    """

    def __init__(self, worker: RecognitionWorker, max_workers: int = 2, queue_size: int = 20):
        self._worker = worker
        self._queue: queue.Queue = queue.Queue(maxsize=queue_size)
        self._executor = ThreadPoolExecutor(max_workers=max_workers)
        self._results: queue.Queue = queue.Queue()
        self._dropped_count = 0
        self._lock = threading.Lock()
        self._stopped = False

    @property
    def dropped_count(self) -> int:
        return self._dropped_count

    @property
    def pending_count(self) -> int:
        return self._queue.qsize()

    def submit(self, sampled_frame: SampledFrame, detection: FaceDetection, track: Track) -> bool:
        """Returns True if accepted, False if dropped (queue full)."""
        try:
            self._queue.put_nowait((sampled_frame, detection, track))
        except queue.Full:
            with self._lock:
                self._dropped_count += 1
            return False

        self._executor.submit(self._run_one)
        return True

    def _run_one(self) -> None:
        try:
            sampled_frame, detection, track = self._queue.get_nowait()
        except queue.Empty:
            return

        try:
            result = self._worker.process(sampled_frame, detection, track)
            if result is not None:
                self._results.put(result)
        finally:
            self._queue.task_done()

    def poll_result(self, timeout: float = 0.0) -> RecognitionWorkerResult | None:
        try:
            return self._results.get(timeout=timeout) if timeout > 0 else self._results.get_nowait()
        except queue.Empty:
            return None

    def shutdown(self, wait: bool = True) -> None:
        self._stopped = True
        self._executor.shutdown(wait=wait)


def utcnow() -> datetime:
    return datetime.now(UTC)
