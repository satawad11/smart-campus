"""Client for the backend's internal recognition-event ingestion endpoint
(CAM-011). Calls POST /internal/v1/events/recognition (CAM-004 ai_node.token
auth) with the already-computed classification — never sends a raw frame or
embedding on this path, and never logs the bearer token.
"""

from abc import ABC, abstractmethod

from app.delivery.exceptions import EventDeliveryError, EventRejectedError
from app.delivery.schemas import RecognitionEventPayload

# Invalid or conflicting content: retrying the same payload can never succeed.
# 401/403 (credential rotation), 429 and 5xx stay retryable.
PERMANENT_REJECTION_STATUSES = (400, 409, 422)


class EventDeliveryClient(ABC):
    """Abstract interface — production uses HttpEventDeliveryClient; tests
    inject a fake, matching the project's "no real network dependency in
    tests" convention (same pattern as CAM-009's RecognitionBackendClient)."""

    @abstractmethod
    def deliver(self, payload: RecognitionEventPayload) -> None: ...

    def upload_snapshot(self, event_uuid: str, jpeg_bytes: bytes) -> None:  # noqa: B027
        """EVT-025: attach a face crop to an already-delivered event. Optional
        for a client; the default sends nothing."""
        return None


class HttpEventDeliveryClient(EventDeliveryClient):
    """Real backend client. Imports httpx lazily (module load must not fail
    if it isn't installed in a given deployment — same convention as
    app.orchestration.backend_client)."""

    def __init__(self, base_url: str, node_token: str, timeout_seconds: float = 5.0):
        self._base_url = base_url.rstrip("/")
        self._node_token = node_token
        self._timeout_seconds = timeout_seconds

    def deliver(self, payload: RecognitionEventPayload) -> None:
        try:
            import httpx  # noqa: PLC0415 (intentionally lazy)
        except ImportError as exc:
            raise EventDeliveryError("httpx is not installed") from exc

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/events/recognition",
                json={
                    "event_uuid": payload.event_uuid,
                    "camera_uuid": payload.camera_uuid,
                    "ai_node_uuid": payload.ai_node_uuid,
                    "track_id": payload.track_id,
                    "occurred_at": payload.occurred_at.isoformat(),
                    "classification": payload.classification,
                    "candidate_person_uuid": payload.candidate_person_uuid,
                    "similarity": payload.similarity,
                    "distance": payload.distance,
                    "model_code": payload.model_code,
                    "model_version": payload.model_version,
                    "quality_score": payload.quality_score,
                    "liveness_status": payload.liveness_status,
                    "rejected_locally": payload.rejected_locally,
                    "reasons": payload.reasons,
                },
                headers={"Authorization": f"Bearer {self._node_token}"},
                timeout=self._timeout_seconds,
            )
        except Exception as exc:  # noqa: BLE001 — normalize any transport failure
            raise EventDeliveryError(f"Event delivery call failed: {exc}") from exc

        if response.status_code in PERMANENT_REJECTION_STATUSES:
            try:
                error_code = (response.json().get("error") or {}).get("code")
            except Exception:  # noqa: BLE001 — body is informational only
                error_code = None
            detail = f" {error_code}" if error_code else ""
            raise EventRejectedError(
                f"Event rejected by backend: HTTP {response.status_code}{detail}",
                response.status_code,
                error_code,
            )
        if response.status_code not in (200, 201, 202):
            raise EventDeliveryError(f"Event delivery call returned HTTP {response.status_code}")

    def upload_snapshot(self, event_uuid: str, jpeg_bytes: bytes) -> None:
        """POST /internal/v1/events/{event_uuid}/snapshot (EVT-025) as
        multipart/form-data. Raises EventDeliveryError on any failure; the
        caller drops the crop — it is never retried from disk."""
        try:
            import httpx  # noqa: PLC0415 (intentionally lazy)
        except ImportError as exc:
            raise EventDeliveryError("httpx is not installed") from exc

        try:
            response = httpx.post(
                f"{self._base_url}/internal/v1/events/{event_uuid}/snapshot",
                files={"image": ("face.jpg", jpeg_bytes, "image/jpeg")},
                headers={
                    "Authorization": f"Bearer {self._node_token}",
                    "Accept": "application/json",
                },
                timeout=self._timeout_seconds,
            )
        except Exception as exc:  # noqa: BLE001 — normalize any transport failure
            raise EventDeliveryError(f"Snapshot upload failed: {type(exc).__name__}") from exc

        if response.status_code not in (200, 201):
            raise EventDeliveryError(f"Snapshot upload returned HTTP {response.status_code}")
