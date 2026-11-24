"""CCTV recognition behaviour of the AI node worker.

- A track that was rejected locally or was not a MATCH is retried after a short
  interval instead of being pinned to UNKNOWN for the whole cache TTL.
- A retry is only delivered when it improves on what the track already delivered.
- Liveness policy: enforce (only REAL is recognised), advisory (PAD is reported but
  does not hide the identity; small faces are not scored), off.
- The node uses the CCTV quality gate from settings, not the enrollment gate.
"""

# ruff: noqa: I001
import pytest

# app.orchestration must load before app.caching (pre-existing import cycle).
from app.orchestration import BackendRecognitionResponse, RecognitionWorker
from app.alignment.mock import MockFaceAligner
from app.caching.cache import RecognitionCache
from app.core.config import settings
from app.delivery.buffered_service import BufferedEventDeliveryService
from app.delivery.service import EventDeliveryService
from app.embeddings.mock import MockFaceEmbedder
from app.liveness.base import FaceLivenessDetector, LivenessResult
from app.orchestration.orchestrator import cctv_quality_thresholds
from app.quality.mock import MockFaceQualityEvaluator
from app.quality.schemas import FaceQualityResult, QualityRejectionReason
from tests.test_orchestration import (
    FakeBackendClient,
    FakeClock,
    make_detection,
    make_sampled_frame,
    make_track,
)


def response(classification: str, person: str | None = None, sim: float = 0.5):
    return BackendRecognitionResponse(
        classification=classification,
        candidate_person_uuid=person,
        similarity=sim,
        distance=1 - sim,
        reasons=[],
    )


class SequenceBackend(FakeBackendClient):
    def __init__(self, responses):
        super().__init__()
        self._responses = list(responses)

    def recognize(self, embedding, model_code, model_version):
        self.calls.append((embedding, model_code, model_version))
        return self._responses.pop(0) if len(self._responses) > 1 else self._responses[0]


class FixedLiveness(FaceLivenessDetector):
    def __init__(self, status: str = "REAL", error: Exception | None = None):
        self.status = status
        self.error = error
        self.calls = 0

    def evaluate(self, image, bounding_box=None) -> LivenessResult:
        self.calls += 1
        if self.error is not None:
            raise self.error
        return LivenessResult(
            score={"REAL": 0.9, "UNCERTAIN": 0.5, "SPOOF": 0.1}[self.status],
            is_live=self.status == "REAL",
            method="TEST",
            status=self.status,
            rejection_reasons=[] if self.status == "REAL" else ["PRESENTATION_ATTACK_SUSPECTED"],
        )


class RejectingQuality(MockFaceQualityEvaluator):
    def evaluate(self, aligned_face, detection=None) -> FaceQualityResult:
        return FaceQualityResult(
            quality_score=0.2,
            blur_score=0.1,
            brightness_score=0.5,
            face_size_score=0.5,
            pose_score=0.9,
            accepted=False,
            rejection_reasons=[QualityRejectionReason.IMAGE_BLUR],
            evaluator_name="t",
            evaluator_version="1",
        )


def worker(
    backend=None,
    quality=None,
    liveness=None,
    liveness_mode="enforce",
    liveness_min_face_px=0.0,
    retry=1.0,
    clock=None,
):
    backend = backend or FakeBackendClient()
    cache = RecognitionCache(ttl_seconds=30.0, clock_fn=clock or FakeClock())
    w = RecognitionWorker(
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        aligner=MockFaceAligner(),
        quality_evaluator=quality or MockFaceQualityEvaluator(force_accepted=True),
        embedder=MockFaceEmbedder(model_code="arcface-r50-v1", model_version="1.0.0"),
        backend_client=backend,
        model_code="arcface-r50-v1",
        model_version="1.0.0",
        liveness_detector=liveness,
        cache=cache,
        liveness_mode=liveness_mode,
        liveness_min_face_px=liveness_min_face_px,
        retry_ttl_seconds=retry,
    )
    return w, backend


def run(w, hits=1, track_id="track-1"):
    return w.process(make_sampled_frame(hits), make_detection(), make_track(track_id, hits=hits))


class TestRetryAndDelivery:
    def test_unknown_track_is_retried_after_the_retry_interval(self) -> None:
        clock = FakeClock()
        w, backend = worker(backend=SequenceBackend([response("UNKNOWN")]), clock=clock)
        run(w, hits=1)
        assert w.should_process(make_track(hits=2)) is False
        clock.advance(1.5)
        assert w.should_process(make_track(hits=3)) is True
        run(w, hits=3)
        assert len(backend.calls) == 2

    def test_match_is_kept_for_the_full_ttl(self) -> None:
        clock = FakeClock()
        w, _ = worker(backend=SequenceBackend([response("MATCH", "p1", 0.7)]), clock=clock)
        run(w, hits=1)
        clock.advance(10)
        assert w.should_process(make_track(hits=5)) is False

    def test_quality_reject_is_retried_not_pinned(self) -> None:
        clock = FakeClock()
        w, _ = worker(quality=RejectingQuality(), clock=clock)
        first = run(w, hits=1)
        # no identity evidence: never posted as UNKNOWN_PERSON, but retried
        assert first.rejected_locally and first.suppress_delivery
        clock.advance(1.5)
        assert w.should_process(make_track(hits=4)) is True

    def test_repeat_without_improvement_is_not_delivered_but_improvement_is(self) -> None:
        clock = FakeClock()
        backend = SequenceBackend(
            [response("UNKNOWN"), response("UNKNOWN"), response("MATCH", "p1", 0.6)]
        )
        w, _ = worker(backend=backend, clock=clock)
        first = run(w, hits=1)
        clock.advance(1.5)
        second = run(w, hits=3)
        clock.advance(1.5)
        third = run(w, hits=5)
        assert first.suppress_delivery is False
        assert second.suppress_delivery is True
        assert third.classification == "MATCH" and third.suppress_delivery is False

    def test_review_with_a_different_candidate_is_delivered(self) -> None:
        clock = FakeClock()
        backend = SequenceBackend([response("REVIEW", "p1", 0.4), response("REVIEW", "p2", 0.4)])
        w, _ = worker(backend=backend, clock=clock)
        run(w, hits=1)
        clock.advance(1.5)
        assert run(w, hits=3).suppress_delivery is False

    def test_other_tracks_are_independent(self) -> None:
        w, _ = worker(backend=SequenceBackend([response("UNKNOWN")]))
        run(w, track_id="a")
        assert run(w, track_id="b").suppress_delivery is False

    @pytest.mark.parametrize("service_cls", [EventDeliveryService, BufferedEventDeliveryService])
    def test_delivery_services_skip_suppressed_results(self, service_cls) -> None:
        w, _ = worker(backend=SequenceBackend([response("UNKNOWN")]), retry=None)
        result = run(w)

        class Boom:
            def deliver(self, payload):  # pragma: no cover - must not be called
                raise AssertionError("suppressed result must not be sent")

        from dataclasses import replace

        args = (Boom(),) if service_cls is EventDeliveryService else (Boom(), None)
        outcome = service_cls(*args).deliver(replace(result, suppress_delivery=True))
        assert outcome.skipped and outcome.reason == "not_improved"

    def test_invalid_settings_are_rejected(self) -> None:
        with pytest.raises(ValueError):
            worker(liveness_mode="sometimes")
        with pytest.raises(ValueError):
            worker(retry=0)


class TestLivenessPolicy:
    def test_enforce_blocks_uncertain(self) -> None:
        w, backend = worker(liveness=FixedLiveness("UNCERTAIN"), liveness_mode="enforce")
        result = run(w)
        assert result.rejected_locally and backend.calls == []

    def test_advisory_recognises_uncertain_and_reports_status(self) -> None:
        w, backend = worker(
            backend=SequenceBackend([response("MATCH", "p1", 0.7)]),
            liveness=FixedLiveness("UNCERTAIN"),
            liveness_mode="advisory",
        )
        result = run(w)
        assert result.classification == "MATCH" and result.liveness_status == "UNCERTAIN"
        assert len(backend.calls) == 1

    def test_advisory_still_reports_spoof_for_the_backend(self) -> None:
        w, _ = worker(liveness=FixedLiveness("SPOOF"), liveness_mode="advisory")
        result = run(w)
        assert result.liveness_status == "SPOOF" and result.rejected_locally is False

    def test_advisory_skips_faces_below_the_minimum_size(self) -> None:
        pad = FixedLiveness("SPOOF")
        w, _ = worker(liveness=pad, liveness_mode="advisory", liveness_min_face_px=80)
        result = run(w)  # the test face box is 20 px
        assert pad.calls == 0 and result.liveness_status is None

    def test_advisory_tolerates_a_pad_failure(self) -> None:
        pad = FixedLiveness(error=RuntimeError("x"))
        w, backend = worker(liveness=pad, liveness_mode="advisory")
        result = run(w)
        assert result.liveness_status is None and len(backend.calls) == 1

    def test_enforce_propagates_a_pad_failure(self) -> None:
        w, _ = worker(liveness=FixedLiveness(error=RuntimeError("x")), liveness_mode="enforce")
        with pytest.raises(RuntimeError):
            run(w)

    def test_off_never_runs_pad(self) -> None:
        pad = FixedLiveness("SPOOF")
        w, _ = worker(liveness=pad, liveness_mode="off")
        run(w)
        assert pad.calls == 0


class TestCctvQualityGate:
    def test_thresholds_come_from_settings(self, monkeypatch) -> None:
        monkeypatch.setattr(settings, "cctv_min_face_px", 30.0)
        monkeypatch.setattr(settings, "cctv_max_yaw_degrees", 50.0)
        t = cctv_quality_thresholds()
        assert t.min_face_size_score == pytest.approx(0.30)
        assert t.max_yaw_degrees == 50.0

    def test_cctv_gate_is_looser_than_enrollment(self) -> None:
        from app.quality.schemas import QualityThresholds

        cctv, enroll = cctv_quality_thresholds(), QualityThresholds()
        assert cctv.min_face_size_score < enroll.min_face_size_score
        assert cctv.min_blur_score < enroll.min_blur_score
        assert cctv.max_yaw_degrees > enroll.max_yaw_degrees


class TestDetectionDownscale:
    class FakeYuNet:
        def __init__(self):
            self.sizes = []

        def setInputSize(self, size):  # noqa: N802 (OpenCV API)
            self.sizes.append(size)

        def detect(self, image):
            import numpy as np

            # one face at (100, 50) 40x40 in the detector's input coordinates
            row = [100, 50, 40, 40, 110, 60, 130, 60, 120, 70, 112, 80, 128, 80, 0.9]
            return 1, np.array([row], dtype=np.float32)

    def make(self, max_width):
        from app.detection.yunet import YuNetFaceDetector

        detector = YuNetFaceDetector(model_path=None, max_input_width=max_width)
        detector._detector = self.FakeYuNet()
        return detector

    def test_wide_frame_is_detected_downscaled_and_mapped_back(self) -> None:
        import numpy as np

        detector = self.make(960)
        faces = detector.detect(np.zeros((1080, 1920, 3), dtype=np.uint8))
        assert detector._detector.sizes == [(960, 540)]
        box = faces[0].bbox
        assert (box.x1, box.y1, box.x2, box.y2) == pytest.approx((200, 100, 280, 180))
        assert faces[0].landmarks[0].x == pytest.approx(220)

    def test_narrow_frame_is_not_resized(self) -> None:
        import numpy as np

        detector = self.make(1280)
        faces = detector.detect(np.zeros((480, 640, 3), dtype=np.uint8))
        assert detector._detector.sizes == [(640, 480)]
        assert faces[0].bbox.x1 == pytest.approx(100)

    def test_factory_uses_the_setting(self, monkeypatch) -> None:
        from app.detection.factory import get_face_detector

        monkeypatch.setattr(settings, "detector_max_input_width", 1024)
        assert get_face_detector(provider="onnx").max_input_width == 1024
