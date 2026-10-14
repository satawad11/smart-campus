"""Unit tests for HttpAiNodeClient and NodeOrchestrator (CAM-003, CAM-014, CAM-015).

Tests verify:
- HttpAiNodeClient check-in, heartbeat, and config sync calls
- Dynamic camera addition, removal, and ROI updates
- Frame processing through sampler, detector, tracker, recognition, and delivery
- Durable offline buffering upon backend delivery failure
- Clean shutdown closing streams and stopping worker threads
"""

import time

import pytest

from app.delivery.client import EventDeliveryClient
from app.delivery.exceptions import EventDeliveryError
from app.delivery.schemas import RecognitionEventPayload
from app.health.client import CameraHealthClient
from app.health.schemas import CameraHealthSample
from app.orchestration.backend_client import BackendRecognitionResponse, RecognitionBackendClient
from app.orchestration.node_client import AiNodeClient, AiNodeClientError, HttpAiNodeClient
from app.orchestration.orchestrator import (
    NodeOrchestrator,
    _build_authenticated_rtsp_url,
)


class FakeResponse:
    def __init__(self, status_code: int, payload: dict):
        self.status_code = status_code
        self._payload = payload

    def json(self) -> dict:
        return self._payload


class FakeNodeClient(AiNodeClient):
    def __init__(self, cameras: list[dict] | None = None):
        self.check_in_called = False
        self.heartbeat_called = False
        self.heartbeats: list[dict] = []
        self.cameras = cameras or []

    def check_in(self, **kwargs) -> dict:
        self.check_in_called = True
        return {"success": True, "message": "Checked in"}

    def heartbeat(self, **kwargs) -> dict:
        self.heartbeat_called = True
        self.heartbeats.append(kwargs)
        return {"success": True, "message": "Heartbeat recorded"}

    def get_config(self, ai_node_uuid: str) -> dict:
        return {
            "ai_node_uuid": ai_node_uuid,
            "cameras": self.cameras,
        }


class FakeDeliveryClient(EventDeliveryClient):
    def __init__(self, fail: bool = False):
        self.fail = fail
        self.delivered_events: list[RecognitionEventPayload] = []

    def deliver(self, payload: RecognitionEventPayload) -> None:
        if self.fail:
            raise EventDeliveryError("Backend offline")
        self.delivered_events.append(payload)


class FakeRecognitionClient(RecognitionBackendClient):
    def recognize(self, embedding, model_code, model_version):
        return BackendRecognitionResponse(
            classification="MATCH",
            candidate_person_uuid="person-123",
            similarity=0.92,
            distance=0.08,
            reasons=["HIGH_SIMILARITY_MATCH"],
        )


class FakeHealthClient(CameraHealthClient):
    def __init__(self):
        self.reports: list[CameraHealthSample] = []

    def report(self, sample: CameraHealthSample) -> None:
        self.reports.append(sample)


class TestHttpAiNodeClient:
    def test_check_in_sends_payload_with_auth(self, monkeypatch):
        called_url = None
        called_headers = None
        called_json = None

        def fake_post(url, json, headers, timeout):
            nonlocal called_url, called_headers, called_json
            called_url = url
            called_headers = headers
            called_json = json
            return FakeResponse(200, {"success": True, "message": "Checked in"})

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpAiNodeClient("http://backend:8000", "test-token")
        res = client.check_in(hostname="node-1", ip_address="10.0.0.5")

        assert called_url == "http://backend:8000/internal/v1/ai-nodes/register"
        assert called_headers["Authorization"] == "Bearer test-token"
        assert called_json["hostname"] == "node-1"
        assert called_json["ip_address"] == "10.0.0.5"
        assert res["success"] is True

    def test_heartbeat_bounds_numeric_ranges(self, monkeypatch):
        called_json = None

        def fake_post(url, json, headers, timeout):
            nonlocal called_json
            called_json = json
            return FakeResponse(200, {"success": True, "message": "Heartbeat recorded"})

        import httpx

        monkeypatch.setattr(httpx, "post", fake_post)

        client = HttpAiNodeClient("http://backend:8000", "test-token")
        client.heartbeat(cpu_usage=150.0, ram_usage=-10.0, active_camera_count=2, queue_depth=5)

        assert called_json["cpu_usage"] == 100.0  # Clamped to 100
        assert called_json["ram_usage"] == 0.0  # Clamped to 0
        assert called_json["active_camera_count"] == 2
        assert called_json["queue_depth"] == 5

    def test_get_config_returns_camera_data(self, monkeypatch):
        def fake_get(url, headers, timeout):
            assert url.endswith("/internal/v1/ai-nodes/node-uuid-1/config")
            return FakeResponse(
                200,
                {
                    "success": True,
                    "data": {
                        "ai_node_uuid": "node-uuid-1",
                        "cameras": [{"camera_uuid": "cam-1", "name": "Main Gate"}],
                    },
                },
            )

        import httpx

        monkeypatch.setattr(httpx, "get", fake_get)

        client = HttpAiNodeClient("http://backend:8000", "test-token")
        config = client.get_config("node-uuid-1")

        assert config["ai_node_uuid"] == "node-uuid-1"
        assert len(config["cameras"]) == 1
        assert config["cameras"][0]["camera_uuid"] == "cam-1"

    def test_client_error_on_non_200(self, monkeypatch):
        def fake_get(url, headers, timeout):
            return FakeResponse(401, {"error": "Unauthorized"})

        import httpx

        monkeypatch.setattr(httpx, "get", fake_get)

        client = HttpAiNodeClient("http://backend:8000", "bad-token")
        with pytest.raises(AiNodeClientError):
            client.get_config("node-uuid-1")


class TestRtspUrlAssembly:
    def test_injects_credentials_when_not_in_url(self):
        url = _build_authenticated_rtsp_url("rtsp://192.168.1.100:554/stream", "admin", "secret123")
        assert url == "rtsp://admin:secret123@192.168.1.100:554/stream"

    def test_preserves_url_when_already_has_credentials(self):
        url = _build_authenticated_rtsp_url(
            "rtsp://existing:pass@192.168.1.100:554/stream", "admin", "secret123"
        )
        assert url == "rtsp://existing:pass@192.168.1.100:554/stream"

    def test_preserves_url_when_credentials_null(self):
        url = _build_authenticated_rtsp_url("rtsp://192.168.1.100:554/stream", None, None)
        assert url == "rtsp://192.168.1.100:554/stream"


def _wait_for(predicate, timeout: float = 5.0, interval: float = 0.02) -> None:
    """Poll until predicate() is true or the timeout passes (the caller asserts)."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline and not predicate():
        time.sleep(interval)


class TestNodeOrchestrator:
    @pytest.fixture
    def mock_camera_config(self):
        return {
            "camera_uuid": "cam-uuid-101",
            "camera_code": "CAM-01",
            "name": "East Gate",
            "rtsp_url": "mock://test-stream",
            "username": None,
            "password": None,
            "face_detection_enabled": True,
            "face_recognition_enabled": True,
            "liveness_enabled": True,
            "snapshot_enabled": True,
            "status": "ACTIVE",
            "roi": [
                {"x": 0.1, "y": 0.1},
                {"x": 0.9, "y": 0.1},
                {"x": 0.9, "y": 0.9},
                {"x": 0.1, "y": 0.9},
            ],
        }

    def test_orchestrator_initialization_and_status(self, mock_camera_config):
        fake_node_client = FakeNodeClient(cameras=[mock_camera_config])
        fake_delivery = FakeDeliveryClient()
        fake_rec = FakeRecognitionClient()
        fake_health = FakeHealthClient()

        orchestrator = NodeOrchestrator(
            base_url="http://fake-backend",
            node_uuid="node-uuid-test",
            node_token="token-test",
            ai_mode="mock",
            event_buffer_path=":memory:",
            node_client=fake_node_client,
            backend_client=fake_rec,
            delivery_client=fake_delivery,
            health_client=fake_health,
            heartbeat_interval=0.1,
            config_sync_interval=0.2,
            retry_interval=0.1,
            camera_health_interval=0.1,
        )

        assert not orchestrator.is_running
        status = orchestrator.get_status_summary()
        assert status["status"] == "STOPPED"
        assert status["node_uuid"] == "node-uuid-test"
        assert status["buffer_pending_count"] == 0

    def test_orchestrator_start_syncs_cameras_and_heartbeats(self, mock_camera_config):
        fake_node_client = FakeNodeClient(cameras=[mock_camera_config])
        fake_delivery = FakeDeliveryClient()
        fake_rec = FakeRecognitionClient()
        fake_health = FakeHealthClient()

        orchestrator = NodeOrchestrator(
            base_url="http://fake-backend",
            node_uuid="node-uuid-test",
            node_token="token-test",
            ai_mode="mock",
            event_buffer_path=":memory:",
            node_client=fake_node_client,
            backend_client=fake_rec,
            delivery_client=fake_delivery,
            health_client=fake_health,
            heartbeat_interval=0.05,
            config_sync_interval=0.1,
            retry_interval=0.05,
            camera_health_interval=0.05,
        )

        try:
            orchestrator.start()
            assert orchestrator.is_running
            assert fake_node_client.check_in_called is True
            assert orchestrator.active_camera_count == 1

            # Allow loops to tick
            time.sleep(0.15)
            assert fake_node_client.heartbeat_called is True

            status = orchestrator.get_status_summary()
            assert status["status"] == "RUNNING"
            assert status["active_camera_count"] == 1
            assert len(status["cameras"]) == 1
            assert status["cameras"][0]["code"] == "CAM-01"
        finally:
            orchestrator.stop(timeout=1.0)
            assert not orchestrator.is_running
            assert orchestrator.active_camera_count == 0

    def test_orchestrator_removes_disabled_cameras(self, mock_camera_config):
        fake_node_client = FakeNodeClient(cameras=[mock_camera_config])
        orchestrator = NodeOrchestrator(
            node_uuid="node-uuid-test",
            ai_mode="mock",
            event_buffer_path=":memory:",
            node_client=fake_node_client,
            delivery_client=FakeDeliveryClient(),
            backend_client=FakeRecognitionClient(),
            health_client=FakeHealthClient(),
        )

        try:
            orchestrator.start()
            assert orchestrator.active_camera_count == 1

            # Backend changes camera status to INACTIVE
            disabled_config = dict(mock_camera_config)
            disabled_config["status"] = "INACTIVE"
            fake_node_client.cameras = [disabled_config]

            orchestrator.sync_config()
            assert orchestrator.active_camera_count == 0
        finally:
            orchestrator.stop(timeout=1.0)

    def test_orchestrator_offline_buffering_and_retry(self, mock_camera_config):
        fake_node_client = FakeNodeClient(cameras=[mock_camera_config])
        # Delivery client fails initially
        failing_delivery = FakeDeliveryClient(fail=True)
        orchestrator = NodeOrchestrator(
            node_uuid="node-uuid-test",
            ai_mode="mock",
            event_buffer_path=":memory:",
            node_client=fake_node_client,
            delivery_client=failing_delivery,
            backend_client=FakeRecognitionClient(),
            health_client=FakeHealthClient(),
            retry_interval=0.05,
            buffer_initial_backoff_seconds=0.01,
        )

        try:
            orchestrator.start()
            # Wait (bounded) for the camera runner to process frames: a fixed
            # 0.2 s sleep was flaky on slower machines (~20% failures).
            _wait_for(lambda: orchestrator.pending_buffer_count >= 1)

            # Events failed delivery and were enqueued in the offline buffer
            pending_count = orchestrator.pending_buffer_count
            assert pending_count >= 1

            # Restore network connectivity
            failing_delivery.fail = False

            # Allow retry worker to drain
            _wait_for(lambda: len(failing_delivery.delivered_events) >= 1)
            assert len(failing_delivery.delivered_events) >= 1
            assert orchestrator.pending_buffer_count < pending_count
        finally:
            orchestrator.stop(timeout=1.0)


class SlowRecognitionClient(FakeRecognitionClient):
    """Finishes each recognition only after the camera has already failed."""

    def recognize(self, embedding, model_code, model_version):
        time.sleep(0.3)
        return super().recognize(embedding, model_code, model_version)


def test_results_finished_after_the_camera_fails_are_still_delivered(monkeypatch):
    """Regression (AI-node kit rehearsal 2026-10-14): when frame reads failed,
    the camera loop skipped collecting finished recognitions, so an event
    completed after a camera drop was stranded in the worker pool."""
    import app.orchestration.orchestrator as orch_module
    from app.streams.mock import MockVideoSource

    # One frame, then the stream is gone for good.
    monkeypatch.setattr(
        orch_module,
        "MockVideoSource",
        lambda num_frames=None, name="cam": MockVideoSource(num_frames=1, name=name),
    )
    delivery = FakeDeliveryClient()
    camera = {
        "camera_uuid": "cam-drop-1",
        "camera_code": "CAM-DROP",
        "name": "Drops after one frame",
        "rtsp_url": "mock://drop",
        "status": "ACTIVE",
        "face_detection_enabled": True,
    }
    orchestrator = NodeOrchestrator(
        node_uuid="node-uuid-test",
        ai_mode="mock",
        event_buffer_path=":memory:",
        node_client=FakeNodeClient(cameras=[camera]),
        delivery_client=delivery,
        backend_client=SlowRecognitionClient(),
        health_client=FakeHealthClient(),
    )

    try:
        orchestrator.start()
        _wait_for(lambda: len(delivery.delivered_events) >= 1, timeout=5.0)
        assert len(delivery.delivered_events) >= 1
    finally:
        orchestrator.stop(timeout=1.0)
