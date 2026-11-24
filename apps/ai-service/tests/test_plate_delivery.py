"""VEH-009: plate-read delivery keeps reads through outages and never re-stamps them."""

from __future__ import annotations

import os
import tempfile
from datetime import UTC, datetime

import pytest

from app.delivery.buffer import OfflineEventBuffer
from app.delivery.exceptions import EventDeliveryError, EventRejectedError
from app.delivery.plate import (
    BufferedPlateEventDeliveryService,
    HttpPlateEventDeliveryClient,
    PlateEventBuffer,
    PlateEventDeliveryClient,
    PlateEventPayload,
    new_plate_payload,
)
from app.delivery.schemas import RecognitionEventPayload

CAPTURED = datetime(2026, 11, 16, 7, 45, 12, tzinfo=UTC)


def _payload(**overrides) -> PlateEventPayload:
    data = dict(
        camera_uuid="11111111-1111-1111-1111-111111111111",
        ai_node_uuid="22222222-2222-2222-2222-222222222222",
        track_id="veh-1",
        occurred_at=CAPTURED,
        plate_text="1กข 1234",
        province_text="กรุงเทพมหานคร",
        ocr_score=0.96,
        model_code="lpd-yunet+ppocr-th",
        model_version="eval",
        detection_score=0.9,
        frames_voted=4,
    )
    data.update(overrides)
    return new_plate_payload(**data)


class FakeClient(PlateEventDeliveryClient):
    def __init__(self, outcomes: list[Exception | None]):
        self.outcomes = list(outcomes)
        self.sent: list[PlateEventPayload] = []

    def deliver(self, payload: PlateEventPayload) -> None:
        self.sent.append(payload)
        outcome = self.outcomes.pop(0) if self.outcomes else None
        if outcome is not None:
            raise outcome


class Clock:
    def __init__(self) -> None:
        self.now = 1000.0

    def __call__(self) -> float:
        return self.now


def _buffer(clock: Clock, path: str = ":memory:") -> PlateEventBuffer:
    return PlateEventBuffer(db_path=path, clock_fn=clock, random_fn=lambda: 0.5)


def test_payload_wire_shape_has_no_type_vehicle_person_or_decision() -> None:
    body = _payload().to_json()

    assert body["occurred_at"] == CAPTURED.isoformat()
    assert body["plate_text"] == "1กข 1234"
    for forbidden in ("event_type", "vehicle_uuid", "person_uuid", "decision"):
        assert forbidden not in body


def test_new_payload_mints_unique_uuid_and_rejects_bad_score() -> None:
    assert _payload().event_uuid != _payload().event_uuid
    with pytest.raises(ValueError):
        _payload(ocr_score=1.5)


def test_success_is_delivered_without_buffering() -> None:
    clock = Clock()
    buffer = _buffer(clock)
    service = BufferedPlateEventDeliveryService(FakeClient([None]), buffer)

    assert service.deliver(_payload()).delivered is True
    assert buffer.size == 0


def test_outage_buffers_then_retry_sends_same_uuid_and_capture_time() -> None:
    clock = Clock()
    buffer = _buffer(clock)
    client = FakeClient([EventDeliveryError("down"), EventDeliveryError("down"), None])
    service = BufferedPlateEventDeliveryService(client, buffer)
    payload = _payload()

    assert service.deliver(payload).reason == "buffered_offline"
    assert buffer.size == 1

    result = service.retry_pending()  # still down: backoff advances, kept
    assert (result.delivered, result.still_pending) == (0, 1)

    clock.now += 10_000
    result = service.retry_pending()
    assert (result.delivered, result.still_pending) == (1, 0)

    resent = client.sent[-1]
    assert resent.event_uuid == payload.event_uuid
    assert resent.occurred_at == CAPTURED
    assert resent == payload


def test_permanent_refusal_is_quarantined_not_retried() -> None:
    clock = Clock()
    buffer = _buffer(clock)
    service = BufferedPlateEventDeliveryService(
        FakeClient([EventRejectedError("no", 422, "ALPR_DISABLED")]), buffer
    )

    assert service.deliver(_payload()).reason == "rejected"
    assert buffer.size == 0
    assert buffer.rejected_count == 1


def test_buffer_survives_restart_and_keeps_plate_and_face_queues_apart() -> None:
    clock = Clock()
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, "events.db")
        plates = _buffer(clock, path)
        payload = _payload()
        plates.enqueue(payload)
        plates.enqueue(payload)  # idempotent on event_uuid
        plates.close()

        reopened = _buffer(clock, path)
        due = reopened.due()
        assert len(due) == 1 and due[0].payload == payload

        faces = OfflineEventBuffer(db_path=path, clock_fn=clock)
        assert faces.size == 0
        faces.enqueue(
            RecognitionEventPayload(
                event_uuid="33333333-3333-3333-3333-333333333333",
                camera_uuid=payload.camera_uuid,
                ai_node_uuid=payload.ai_node_uuid,
                track_id="face-1",
                occurred_at=CAPTURED,
                classification="UNKNOWN",
                candidate_person_uuid=None,
                similarity=None,
                distance=None,
                model_code="yunet-sface",
                model_version="1",
                quality_score=None,
                liveness_status=None,
                rejected_locally=False,
            )
        )
        assert faces.size == 1 and reopened.size == 1
        assert isinstance(faces.due()[0].payload, RecognitionEventPayload)
        reopened.close()
        faces.close()


def test_http_client_maps_statuses(monkeypatch: pytest.MonkeyPatch) -> None:
    import httpx

    calls: list[dict] = []

    class Response:
        def __init__(self, status: int, body: dict | None = None):
            self.status_code = status
            self._body = body or {}

        def json(self) -> dict:
            return self._body

    responses = [
        Response(201),
        Response(422, {"error": {"code": "PLATE_RECOGNITION_DISABLED"}}),
        Response(503),
    ]

    def fake_post(url, json, headers, timeout):  # noqa: A002 — mirrors httpx.post
        calls.append({"url": url, "json": json, "headers": headers})
        return responses.pop(0)

    monkeypatch.setattr(httpx, "post", fake_post)
    client = HttpPlateEventDeliveryClient("http://backend/", "tok")

    client.deliver(_payload())
    assert calls[0]["url"] == "http://backend/internal/v1/events/plate"
    assert calls[0]["headers"]["Authorization"] == "Bearer tok"

    with pytest.raises(EventRejectedError) as rejected:
        client.deliver(_payload())
    assert rejected.value.error_code == "PLATE_RECOGNITION_DISABLED"

    with pytest.raises(EventDeliveryError) as retryable:
        client.deliver(_payload())
    assert not isinstance(retryable.value, EventRejectedError)
