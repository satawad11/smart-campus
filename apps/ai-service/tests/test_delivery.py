"""Tests for recognition-event delivery (CAM-011).

No real network dependency — httpx.post is monkeypatched, matching
test_backend_client.py's convention.
"""

from datetime import UTC, datetime

import pytest

from app.delivery.client import EventDeliveryClient, HttpEventDeliveryClient
from app.delivery.exceptions import EventDeliveryError
from app.delivery.schemas import RecognitionEventPayload
from app.delivery.service import EventDeliveryService, to_event_payload
from app.orchestration.schemas import RecognitionWorkerResult


class FakeResponse:
    def __init__(self, status_code: int):
        self.status_code = status_code


class FakeDeliveryClient(EventDeliveryClient):
    def __init__(self, error: Exception | None = None):
        self.error = error
        self.delivered: list[RecognitionEventPayload] = []

    def deliver(self, payload: RecognitionEventPayload) -> None:
        if self.error is not None:
            raise self.error
        self.delivered.append(payload)


def make_result(
    from_cache: bool = False,
    classification: str = "MATCH",
    event_uuid: str = "event-1",
) -> RecognitionWorkerResult:
    return RecognitionWorkerResult(
        event_uuid=event_uuid,
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        track_id="track-1",
        observed_at=datetime.now(UTC),
        classification=classification,
        candidate_person_uuid="person-uuid-1" if classification == "MATCH" else None,
        similarity=0.95 if classification == "MATCH" else None,
        distance=0.05 if classification == "MATCH" else None,
        model_code="arcface-r50-v1",
        model_version="1.0.0",
        quality_score=0.9,
        liveness_status="REAL",
        rejected_locally=False,
        from_cache=from_cache,
    )


class TestToEventPayload:
    def test_maps_all_fields_and_carries_no_access_decision(self) -> None:
        result = make_result()
        payload = to_event_payload(result)

        assert payload.event_uuid == result.event_uuid
        assert payload.camera_uuid == result.camera_uuid
        assert payload.ai_node_uuid == result.ai_node_uuid
        assert payload.track_id == result.track_id
        assert payload.occurred_at == result.observed_at
        assert payload.classification == "MATCH"
        assert payload.candidate_person_uuid == "person-uuid-1"

        payload_fields = set(payload.__dataclass_fields__.keys())
        for forbidden in ("access_decision", "allow", "deny", "door_state"):
            assert forbidden not in payload_fields


class TestEventDeliveryService:
    def test_fresh_result_is_delivered(self) -> None:
        client = FakeDeliveryClient()
        service = EventDeliveryService(client)

        outcome = service.deliver(make_result(from_cache=False))

        assert outcome.delivered is True
        assert outcome.skipped is False
        assert len(client.delivered) == 1
        assert client.delivered[0].event_uuid == "event-1"

    def test_cached_result_is_skipped_not_delivered(self) -> None:
        client = FakeDeliveryClient()
        service = EventDeliveryService(client)

        outcome = service.deliver(make_result(from_cache=True))

        assert outcome.delivered is False
        assert outcome.skipped is True
        assert outcome.reason == "from_cache"
        assert len(client.delivered) == 0

    def test_delivery_failure_propagates_to_the_caller(self) -> None:
        client = FakeDeliveryClient(error=EventDeliveryError("backend down"))
        service = EventDeliveryService(client)

        with pytest.raises(EventDeliveryError):
            service.deliver(make_result(from_cache=False))

    def test_unknown_classification_delivered_like_any_other(self) -> None:
        client = FakeDeliveryClient()
        service = EventDeliveryService(client)

        outcome = service.deliver(make_result(from_cache=False, classification="UNKNOWN"))

        assert outcome.delivered is True
        assert client.delivered[0].classification == "UNKNOWN"
        assert client.delivered[0].candidate_person_uuid is None


class TestHttpEventDeliveryClient:
    def test_successful_delivery_makes_no_exception(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            assert url.endswith("/internal/v1/events/recognition")
            assert headers["Authorization"] == "Bearer test-token"
            assert json["event_uuid"] == "event-1"
            assert "occurred_at" in json
            return FakeResponse(201)

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpEventDeliveryClient(base_url="http://backend", node_token="test-token")
        client.deliver(to_event_payload(make_result()))

    def test_non_2xx_status_raises_delivery_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            return FakeResponse(422)

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpEventDeliveryClient(base_url="http://backend", node_token="test-token")
        with pytest.raises(EventDeliveryError):
            client.deliver(to_event_payload(make_result()))

    def test_transport_failure_raises_delivery_error(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            raise ConnectionError("network unreachable")

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpEventDeliveryClient(base_url="http://backend", node_token="test-token")
        with pytest.raises(EventDeliveryError):
            client.deliver(to_event_payload(make_result()))

    def test_error_message_never_contains_bearer_token(self, monkeypatch) -> None:
        def fake_post(url, json, headers, timeout):
            raise ConnectionError("boom")

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpEventDeliveryClient(base_url="http://backend", node_token="super-secret-token")
        with pytest.raises(EventDeliveryError) as exc_info:
            client.deliver(to_event_payload(make_result()))
        assert "super-secret-token" not in str(exc_info.value)

    def test_no_access_decision_ever_sent_in_the_request_body(self, monkeypatch) -> None:
        captured = {}

        def fake_post(url, json, headers, timeout):
            captured.update(json)
            return FakeResponse(200)

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpEventDeliveryClient(base_url="http://backend", node_token="test-token")
        client.deliver(to_event_payload(make_result()))

        for forbidden in ("access_decision", "allow", "deny", "door_state"):
            assert forbidden not in captured
