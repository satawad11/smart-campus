"""EVT-025 (ACR-EVT-24) — event face crop on the AI node.

The node cuts a small JPEG of the face for an UNKNOWN/REVIEW result and sends
it once, after the event itself was accepted. Never a full frame, never in the
event JSON, never cached, never written to the offline buffer.
"""

import json
from dataclasses import replace
from datetime import UTC, datetime

import numpy as np
import pytest

from app.alignment.mock import MockFaceAligner
from app.delivery.buffer import OfflineEventBuffer
from app.delivery.buffered_service import BufferedEventDeliveryService
from app.delivery.client import EventDeliveryClient, HttpEventDeliveryClient
from app.delivery.exceptions import EventDeliveryError
from app.delivery.face_crop import CROP_MAX_SIDE, encode_face_crop
from app.delivery.schemas import RecognitionEventPayload
from app.delivery.service import EventDeliveryService, to_event_payload
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint
from app.embeddings.mock import MockFaceEmbedder
from app.orchestration import BackendRecognitionResponse, RecognitionWorker
from app.orchestration.schemas import RecognitionWorkerResult
from app.quality.mock import MockFaceQualityEvaluator
from app.sampling.schemas import SampledFrame
from app.streams.schemas import Frame
from app.tracking.schemas import Track

cv2 = pytest.importorskip("cv2")

JPEG_MAGIC = b"\xff\xd8\xff"


def frame(width: int = 640, height: int = 480) -> np.ndarray:
    rng = np.random.default_rng(1)
    return rng.integers(0, 255, size=(height, width, 3), dtype=np.uint8)


def decoded_size(jpeg: bytes) -> tuple[int, int]:
    img = cv2.imdecode(np.frombuffer(jpeg, dtype=np.uint8), cv2.IMREAD_COLOR)
    return img.shape[1], img.shape[0]


class TestEncodeFaceCrop:
    def test_returns_a_jpeg_of_the_face_region_with_margin(self) -> None:
        jpeg = encode_face_crop(frame(), (100, 100, 200, 220))
        assert jpeg is not None and jpeg.startswith(JPEG_MAGIC)
        # 100x120 box + 20% margin each side = 140x168
        assert decoded_size(jpeg) == (140, 168)

    def test_large_faces_are_scaled_down_never_a_full_frame(self) -> None:
        jpeg = encode_face_crop(frame(1920, 1080), (0, 0, 1900, 1070))
        assert jpeg is not None
        width, height = decoded_size(jpeg)
        assert max(width, height) <= CROP_MAX_SIDE

    def test_tiny_or_invalid_regions_yield_none(self) -> None:
        assert encode_face_crop(frame(), (10, 10, 20, 20)) is None
        assert encode_face_crop(None, (0, 0, 100, 100)) is None

    def test_crop_is_clamped_to_the_frame(self) -> None:
        jpeg = encode_face_crop(frame(200, 200), (150, 150, 260, 260))
        assert jpeg is not None
        width, height = decoded_size(jpeg)
        assert width <= 200 and height <= 200


def make_detection() -> FaceDetection:
    return FaceDetection(
        bbox=BoundingBox(x1=100, y1=100, x2=200, y2=220),
        confidence=0.95,
        landmarks=[
            LandmarkPoint(x=130, y=140),
            LandmarkPoint(x=170, y=140),
            LandmarkPoint(x=150, y=165),
            LandmarkPoint(x=135, y=190),
            LandmarkPoint(x=165, y=190),
        ],
        detector_name="mock_detector",
        detector_version="1.0.0",
    )


def make_sampled() -> SampledFrame:
    f = Frame(data=frame(), width=640, height=480, sequence_number=0, source_name="cam-1")
    return SampledFrame(frame=f, camera_uuid="camera-uuid-1", sampled_sequence=0)


def make_track() -> Track:
    return Track(
        track_id="track-1",
        bbox=BoundingBox(x1=100, y1=100, x2=200, y2=220),
        confidence=0.95,
        age=0,
        hits=1,
        missed_frames=0,
    )


class StaticBackend:
    def __init__(self, classification: str):
        self.classification = classification

    def recognize(self, embedding, model_code, model_version):
        match = self.classification == "MATCH"
        return BackendRecognitionResponse(
            classification=self.classification,
            candidate_person_uuid="person-1" if match else None,
            similarity=0.9 if match else None,
            distance=0.1 if match else None,
            reasons=[],
        )


def make_worker(classification: str) -> RecognitionWorker:
    return RecognitionWorker(
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        aligner=MockFaceAligner(),
        quality_evaluator=MockFaceQualityEvaluator(force_accepted=True),
        embedder=MockFaceEmbedder(model_code="arcface-r50-v1", model_version="1.0.0"),
        backend_client=StaticBackend(classification),
        model_code="arcface-r50-v1",
        model_version="1.0.0",
    )


class TestWorkerAttachesCrop:
    @pytest.mark.parametrize("classification", ["UNKNOWN", "REVIEW"])
    def test_unknown_and_review_results_carry_a_crop(self, classification: str) -> None:
        result = make_worker(classification).process(make_sampled(), make_detection(), make_track())
        assert result.face_crop_jpeg is not None
        assert result.face_crop_jpeg.startswith(JPEG_MAGIC)

    def test_match_results_carry_no_crop(self) -> None:
        result = make_worker("MATCH").process(make_sampled(), make_detection(), make_track())
        assert result.face_crop_jpeg is None

    def test_cache_never_holds_the_crop_and_cached_hits_have_none(self) -> None:
        worker = make_worker("UNKNOWN")
        first = worker.process(make_sampled(), make_detection(), make_track())
        assert first.face_crop_jpeg is not None

        again = worker.process(make_sampled(), make_detection(), make_track())
        assert again.from_cache is True
        assert again.face_crop_jpeg is None

    def test_crop_is_not_in_the_result_repr(self) -> None:
        result = make_worker("UNKNOWN").process(make_sampled(), make_detection(), make_track())
        assert "face_crop_jpeg" not in repr(result)


def make_result(crop: bytes | None = b"\xff\xd8\xff-crop") -> RecognitionWorkerResult:
    return RecognitionWorkerResult(
        event_uuid="event-1",
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        track_id="track-1",
        observed_at=datetime.now(UTC),
        classification="UNKNOWN",
        candidate_person_uuid=None,
        similarity=None,
        distance=None,
        model_code="arcface-r50-v1",
        model_version="1.0.0",
        quality_score=0.9,
        liveness_status="REAL",
        rejected_locally=False,
        face_crop_jpeg=crop,
    )


class RecordingClient(EventDeliveryClient):
    def __init__(self, deliver_error=None, upload_error=None):
        self.deliver_error = deliver_error
        self.upload_error = upload_error
        self.calls: list[tuple] = []

    def deliver(self, payload: RecognitionEventPayload) -> None:
        self.calls.append(("deliver", payload.event_uuid))
        if self.deliver_error is not None:
            raise self.deliver_error

    def upload_snapshot(self, event_uuid: str, jpeg_bytes: bytes) -> None:
        self.calls.append(("upload", event_uuid, jpeg_bytes))
        if self.upload_error is not None:
            raise self.upload_error


class TestDeliveryUploadsCrop:
    def test_event_json_never_contains_the_crop(self) -> None:
        payload = to_event_payload(make_result())
        assert not hasattr(payload, "face_crop_jpeg")

    def test_crop_is_uploaded_once_after_the_event_is_accepted(self) -> None:
        client = RecordingClient()
        outcome = EventDeliveryService(client).deliver(make_result())
        assert outcome.delivered is True
        assert client.calls == [("deliver", "event-1"), ("upload", "event-1", b"\xff\xd8\xff-crop")]

    def test_no_crop_means_no_upload(self) -> None:
        client = RecordingClient()
        EventDeliveryService(client).deliver(make_result(crop=None))
        assert client.calls == [("deliver", "event-1")]

    def test_upload_failure_never_fails_the_event(self) -> None:
        client = RecordingClient(upload_error=EventDeliveryError("HTTP 503"))
        outcome = EventDeliveryService(client).deliver(make_result())
        assert outcome.delivered is True

    def test_buffered_service_uploads_after_successful_delivery(self) -> None:
        client = RecordingClient()
        service = BufferedEventDeliveryService(client, OfflineEventBuffer())
        assert service.deliver(make_result()).delivered is True
        assert ("upload", "event-1", b"\xff\xd8\xff-crop") in client.calls

    def test_offline_event_is_buffered_without_its_crop(self) -> None:
        client = RecordingClient(deliver_error=EventDeliveryError("down"))
        buffer = OfflineEventBuffer()
        service = BufferedEventDeliveryService(client, buffer)

        outcome = service.deliver(make_result())

        assert outcome.reason == "buffered_offline"
        assert not any(call[0] == "upload" for call in client.calls)
        with buffer._lock:  # noqa: SLF001 — inspect what reached the node's disk
            rows = buffer._conn.execute("SELECT payload_json FROM pending_events").fetchall()  # noqa: SLF001
        assert len(rows) == 1
        assert "face_crop" not in rows[0][0]
        assert "crop" not in json.loads(rows[0][0])

        # Retrying later sends the event only: the crop is gone.
        client.deliver_error = None
        service.retry_pending()
        assert not any(call[0] == "upload" for call in client.calls)


class FakeResponse:
    def __init__(self, status_code: int):
        self.status_code = status_code


class TestHttpUploadSnapshot:
    def test_posts_multipart_jpeg_to_the_event_snapshot_endpoint(self, monkeypatch) -> None:
        import httpx

        seen = {}

        def fake_post(url, **kwargs):
            seen["url"] = url
            seen.update(kwargs)
            return FakeResponse(201)

        monkeypatch.setattr(httpx, "post", fake_post)
        HttpEventDeliveryClient("http://backend/", "secret-token").upload_snapshot(
            "event-1", b"jpeg"
        )

        assert seen["url"] == "http://backend/internal/v1/events/event-1/snapshot"
        assert seen["files"] == {"image": ("face.jpg", b"jpeg", "image/jpeg")}
        assert "json" not in seen
        assert seen["headers"]["Authorization"] == "Bearer secret-token"

    def test_rejection_raises_without_leaking_the_token(self, monkeypatch) -> None:
        import httpx

        monkeypatch.setattr(httpx, "post", lambda url, **kw: FakeResponse(422))
        with pytest.raises(EventDeliveryError) as exc:
            HttpEventDeliveryClient("http://backend", "secret-token").upload_snapshot("e", b"x")
        assert "secret-token" not in str(exc.value)

    def test_transport_failure_raises_delivery_error(self, monkeypatch) -> None:
        import httpx

        def boom(url, **kw):
            raise RuntimeError("secret-token unreachable")

        monkeypatch.setattr(httpx, "post", boom)
        with pytest.raises(EventDeliveryError) as exc:
            HttpEventDeliveryClient("http://backend", "secret-token").upload_snapshot("e", b"x")
        assert "secret-token" not in str(exc.value)


def test_replace_keeps_crop_out_of_equality() -> None:
    a = make_result(crop=b"one")
    assert a == replace(a, face_crop_jpeg=b"two")
