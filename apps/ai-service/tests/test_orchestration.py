"""Tests for recognition worker orchestration (CAM-009).

All AI pipeline stages use the existing deterministic Module 03 mock
providers (never real ONNX/network dependency). The backend call is a fake
RecognitionBackendClient — no real HTTP/network dependency.
"""

import queue
import time

import numpy as np
import pytest

from app.alignment.mock import MockFaceAligner
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint
from app.embeddings.mock import MockFaceEmbedder
from app.liveness.mock import MockFaceLivenessDetector
from app.orchestration import (
    BackendRecognitionResponse,
    RecognitionBackendError,
    RecognitionWorker,
    RecognitionWorkerPool,
)
from app.quality.mock import MockFaceQualityEvaluator
from app.quality.schemas import FaceQualityResult, QualityRejectionReason
from app.sampling.schemas import SampledFrame
from app.streams.schemas import Frame
from app.tracking.schemas import Track


def make_detection(confidence: float = 0.95) -> FaceDetection:
    # 5-point landmarks (canonical order) are required by FaceAligner.align().
    landmarks = [
        LandmarkPoint(x=6.0, y=7.0),  # left eye
        LandmarkPoint(x=14.0, y=7.0),  # right eye
        LandmarkPoint(x=10.0, y=11.0),  # nose
        LandmarkPoint(x=7.0, y=15.0),  # left mouth
        LandmarkPoint(x=13.0, y=15.0),  # right mouth
    ]
    return FaceDetection(
        bbox=BoundingBox(x1=0, y1=0, x2=20, y2=20),
        confidence=confidence,
        landmarks=landmarks,
        detector_name="mock_detector",
        detector_version="1.0.0",
    )


def make_sampled_frame(sequence: int = 0) -> SampledFrame:
    frame = Frame(
        data=np.zeros((48, 64, 3), dtype=np.uint8),
        width=64,
        height=48,
        sequence_number=sequence,
        source_name="cam-1",
    )
    return SampledFrame(frame=frame, camera_uuid="camera-uuid-1", sampled_sequence=sequence)


def make_track(track_id: str = "track-1", hits: int = 1) -> Track:
    return Track(
        track_id=track_id,
        bbox=BoundingBox(x1=0, y1=0, x2=20, y2=20),
        confidence=0.95,
        age=hits - 1,
        hits=hits,
        missed_frames=0,
    )


class FakeBackendClient:
    """Deterministic fake — no real HTTP call."""

    def __init__(
        self,
        response: BackendRecognitionResponse | None = None,
        error: Exception | None = None,
    ):
        self.response = response or BackendRecognitionResponse(
            classification="MATCH",
            candidate_person_uuid="person-uuid-1",
            similarity=0.95,
            distance=0.05,
            reasons=["HIGH_SIMILARITY_MATCH"],
        )
        self.error = error
        self.calls: list[tuple[list[float], str, str]] = []

    def recognize(self, embedding, model_code, model_version):
        self.calls.append((embedding, model_code, model_version))
        if self.error is not None:
            raise self.error
        return self.response


def make_worker(
    backend_client=None,
    quality_evaluator=None,
    liveness_detector=None,
    recheck_interval_hits: int = 30,
    id_factory=None,
) -> tuple[RecognitionWorker, FakeBackendClient]:
    client = backend_client or FakeBackendClient()
    worker = RecognitionWorker(
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        aligner=MockFaceAligner(),
        quality_evaluator=quality_evaluator or MockFaceQualityEvaluator(force_accepted=True),
        embedder=MockFaceEmbedder(model_code="arcface-r50-v1", model_version="1.0.0"),
        backend_client=client,
        model_code="arcface-r50-v1",
        model_version="1.0.0",
        liveness_detector=liveness_detector,
        recheck_interval_hits=recheck_interval_hits,
        id_factory=id_factory,
    )
    return worker, client


class TestHappyPath:
    def test_match_result_contains_all_required_fields(self) -> None:
        worker, client = make_worker()
        result = worker.process(make_sampled_frame(), make_detection(), make_track())

        assert result is not None
        assert result.camera_uuid == "camera-uuid-1"
        assert result.ai_node_uuid == "node-uuid-1"
        assert result.track_id == "track-1"
        assert result.classification == "MATCH"
        assert result.candidate_person_uuid == "person-uuid-1"
        assert result.similarity == pytest.approx(0.95)
        assert result.distance == pytest.approx(0.05)
        assert result.model_code == "arcface-r50-v1"
        assert result.model_version == "1.0.0"
        assert result.quality_score is not None
        assert result.rejected_locally is False
        assert result.event_uuid
        assert result.observed_at is not None
        assert result.processed_at is not None

    def test_no_access_decision_fields_exist_on_result(self) -> None:
        worker, _ = make_worker()
        result = worker.process(make_sampled_frame(), make_detection(), make_track())
        result_fields = set(result.__dataclass_fields__.keys())
        for forbidden in ("access_decision", "allow", "deny", "door_state"):
            assert forbidden not in result_fields

    def test_backend_receives_the_locally_computed_embedding(self) -> None:
        worker, client = make_worker()
        worker.process(make_sampled_frame(), make_detection(), make_track())
        assert len(client.calls) == 1
        embedding, model_code, model_version = client.calls[0]
        assert len(embedding) == 512
        assert model_code == "arcface-r50-v1"


class TestLocalQualityRejection:
    def test_low_quality_never_calls_backend(self) -> None:
        rejected = FaceQualityResult(
            quality_score=0.1,
            blur_score=0.1,
            brightness_score=0.5,
            face_size_score=0.5,
            pose_score=0.5,
            accepted=False,
            rejection_reasons=[QualityRejectionReason.IMAGE_BLUR],
            evaluator_name="mock",
            evaluator_version="1.0.0",
        )
        evaluator = MockFaceQualityEvaluator(custom_result=rejected)
        worker, client = make_worker(quality_evaluator=evaluator)

        result = worker.process(make_sampled_frame(), make_detection(), make_track())

        assert result is not None
        assert result.classification == "UNKNOWN"
        assert result.rejected_locally is True
        assert "IMAGE_BLUR" in result.reasons or len(result.reasons) > 0
        assert len(client.calls) == 0  # never reached the backend

    def test_quality_rejection_stays_within_canonical_classification_set(self) -> None:
        rejected = FaceQualityResult(
            quality_score=0.1,
            blur_score=0.1,
            brightness_score=0.5,
            face_size_score=0.5,
            pose_score=0.5,
            accepted=False,
            rejection_reasons=[],
            evaluator_name="mock",
            evaluator_version="1.0.0",
        )
        worker, _ = make_worker(quality_evaluator=MockFaceQualityEvaluator(custom_result=rejected))
        result = worker.process(make_sampled_frame(), make_detection(), make_track())
        assert result.classification in ("MATCH", "REVIEW", "UNKNOWN")


class TestLocalLivenessRejection:
    def test_spoof_liveness_never_calls_backend(self) -> None:
        liveness = MockFaceLivenessDetector(default_score=0.1)  # -> SPOOF
        worker, client = make_worker(liveness_detector=liveness)

        result = worker.process(make_sampled_frame(), make_detection(), make_track())

        assert result.classification == "UNKNOWN"
        assert result.rejected_locally is True
        assert result.liveness_status == "SPOOF"
        assert len(client.calls) == 0

    def test_live_face_proceeds_to_backend(self) -> None:
        liveness = MockFaceLivenessDetector(default_score=0.95)  # -> REAL
        worker, client = make_worker(liveness_detector=liveness)

        result = worker.process(make_sampled_frame(), make_detection(), make_track())

        assert result.rejected_locally is False
        assert result.liveness_status == "REAL"
        assert len(client.calls) == 1


class TestBackendFailure:
    def test_backend_error_produces_unknown_without_raising(self) -> None:
        worker, client = make_worker(
            backend_client=FakeBackendClient(error=RecognitionBackendError("timeout"))
        )
        result = worker.process(make_sampled_frame(), make_detection(), make_track())
        assert result.classification == "UNKNOWN"
        assert result.rejected_locally is True
        assert any("BACKEND_UNAVAILABLE" in r for r in result.reasons)


class TestTrackDedup:
    def test_same_stable_track_is_not_reprocessed_every_frame(self) -> None:
        worker, client = make_worker(recheck_interval_hits=5)

        first = worker.process(make_sampled_frame(), make_detection(), make_track(hits=1))
        assert first is not None

        # Still within the recheck interval -> skipped.
        skipped = worker.process(make_sampled_frame(), make_detection(), make_track(hits=2))
        assert skipped is None
        assert len(client.calls) == 1

    def test_track_is_reprocessed_after_recheck_interval_elapses(self) -> None:
        worker, client = make_worker(recheck_interval_hits=3)

        worker.process(make_sampled_frame(), make_detection(), make_track(hits=1))
        worker.process(make_sampled_frame(), make_detection(), make_track(hits=2))
        result = worker.process(make_sampled_frame(), make_detection(), make_track(hits=4))

        assert result is not None
        assert len(client.calls) == 2

    def test_different_tracks_are_independently_deduped(self) -> None:
        worker, client = make_worker(recheck_interval_hits=5)

        worker.process(make_sampled_frame(), make_detection(), make_track(track_id="a", hits=1))
        worker.process(make_sampled_frame(), make_detection(), make_track(track_id="b", hits=1))

        assert len(client.calls) == 2

    def test_should_process_reflects_dedup_state(self) -> None:
        worker, _ = make_worker(recheck_interval_hits=5)
        track = make_track(hits=1)
        assert worker.should_process(track) is True
        worker.process(make_sampled_frame(), make_detection(), track)
        assert worker.should_process(make_track(hits=2)) is False
        assert worker.should_process(make_track(hits=6)) is True


class TestEventUuidStability:
    def test_event_uuid_assigned_once_per_processed_attempt(self) -> None:
        worker, _ = make_worker(recheck_interval_hits=1)
        r1 = worker.process(make_sampled_frame(), make_detection(), make_track(hits=1))
        r2 = worker.process(make_sampled_frame(), make_detection(), make_track(hits=2))
        assert r1.event_uuid != r2.event_uuid  # distinct attempts -> distinct events

    def test_event_uuid_uses_injected_id_factory(self) -> None:
        counter = {"n": 0}

        def factory() -> str:
            counter["n"] += 1
            return f"event-{counter['n']}"

        worker, _ = make_worker(id_factory=factory)
        result = worker.process(make_sampled_frame(), make_detection(), make_track())
        assert result.event_uuid == "event-1"


class TestWorkerPoolBackpressure:
    def test_bounded_queue_drops_when_full(self) -> None:
        # A worker whose processing blocks until released, so the queue
        # backs up deterministically rather than racing real timing.
        release = queue.Queue()

        class BlockingBackend:
            def recognize(self, embedding, model_code, model_version):
                release.get()  # blocks until the test lets it through
                return BackendRecognitionResponse(
                    classification="UNKNOWN",
                    candidate_person_uuid=None,
                    similarity=None,
                    distance=None,
                    reasons=[],
                )

        worker = RecognitionWorker(
            camera_uuid="cam-1",
            ai_node_uuid="node-1",
            aligner=MockFaceAligner(),
            quality_evaluator=MockFaceQualityEvaluator(force_accepted=True),
            embedder=MockFaceEmbedder(),
            backend_client=BlockingBackend(),
            model_code="arcface-r50-v1",
            model_version="1.0.0",
            recheck_interval_hits=0,
        )
        pool = RecognitionWorkerPool(worker, max_workers=1, queue_size=1)

        try:
            accepted1 = pool.submit(make_sampled_frame(), make_detection(), make_track("t1"))
            time.sleep(0.2)  # let the single worker thread pick up t1 and start blocking
            accepted2 = pool.submit(make_sampled_frame(), make_detection(), make_track("t2"))
            accepted3 = pool.submit(make_sampled_frame(), make_detection(), make_track("t3"))

            assert accepted1 is True
            assert accepted2 is True  # fills the size-1 queue
            assert accepted3 is False  # queue full -> dropped
            assert pool.dropped_count == 1
        finally:
            release.put(None)
            release.put(None)
            pool.shutdown(wait=True)

    def test_results_are_retrievable_after_processing(self) -> None:
        worker, _ = make_worker(recheck_interval_hits=0)
        pool = RecognitionWorkerPool(worker, max_workers=2, queue_size=5)
        try:
            pool.submit(make_sampled_frame(), make_detection(), make_track("t1"))
            result = None
            for _ in range(50):
                result = pool.poll_result(timeout=0.05)
                if result is not None:
                    break
            assert result is not None
            assert result.track_id == "t1"
        finally:
            pool.shutdown(wait=True)
