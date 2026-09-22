"""Client for the backend's internal camera-health ingestion endpoint
(CAM-013). Calls POST /internal/v1/cameras/{cameraUuid}/health (CAM-004
ai_node.token auth). No status field is ever sent — the backend derives
HealthStatus itself (CameraHealthService)."""

from abc import ABC, abstractmethod

from app.health.exceptions import CameraHealthDeliveryError
from app.health.schemas import CameraHealthSample


class CameraHealthClient(ABC):
    """Abstract interface — production uses HttpCameraHealthClient; tests
    inject a fake, matching the project's "no real network dependency in
    tests" convention (same pattern as CAM-009's RecognitionBackendClient)."""

    @abstractmethod
    def report(self, sample: CameraHealthSample) -> None: ...


class HttpCameraHealthClient(CameraHealthClient):
    """Real backend client. Imports httpx lazily, same convention as
    app.orchestration.backend_client."""

    def __init__(self, base_url: str, node_token: str, timeout_seconds: float = 5.0):
        self._base_url = base_url.rstrip("/")
        self._node_token = node_token
        self._timeout_seconds = timeout_seconds

    def report(self, sample: CameraHealthSample) -> None:
        try:
            import httpx  # noqa: PLC0415 (intentionally lazy)
        except ImportError as exc:
            raise CameraHealthDeliveryError("httpx is not installed") from exc

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/cameras/{sample.camera_uuid}/health",
                json={
                    "fps": sample.fps,
                    "latency_ms": sample.latency_ms,
                    "dropped_frames": sample.dropped_frames,
                    "resolution": sample.resolution,
                    "brightness_score": sample.brightness_score,
                    "blur_score": sample.blur_score,
                    "reconnect_count": sample.reconnect_count,
                    "recorded_at": sample.recorded_at.isoformat(),
                },
                headers={"Authorization": f"Bearer {self._node_token}"},
                timeout=self._timeout_seconds,
            )
        except Exception as exc:  # noqa: BLE001 — normalize any transport failure
            raise CameraHealthDeliveryError(f"Camera health call failed: {exc}") from exc

        if response.status_code not in (200, 201, 202):
            raise CameraHealthDeliveryError(
                f"Camera health call returned HTTP {response.status_code}"
            )
