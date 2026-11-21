"""Plate-read delivery with durable offline buffer (VEH-009, ACR-VEH-01).

Sends one result per vehicle pass to POST /internal/v1/events/plate (VEH-010) with the node's
bearer token. Same guarantees as face events (CAM-011/CAM-012):

- event_uuid is fixed when the read is first produced, so a retry is idempotent on the backend;
- occurred_at is the capture time, never the send or retry time;
- a transport failure, 401/403, 429 or 5xx puts the read in the durable SQLite buffer and it is
  retried with backoff — never dropped;
- a permanent refusal (400/409/422, e.g. ALPR_DISABLED or PLATE_RECOGNITION_DISABLED) is
  quarantined for inspection instead of being retried forever.

The node sends what it read (raw text and scores) and nothing else: no event type, vehicle,
person or decision. The backend normalises the plate and decides the type.
"""

from __future__ import annotations

import uuid
from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from datetime import datetime

from app.delivery.buffer import OfflineEventBuffer
from app.delivery.buffered_service import RetryBatchResult
from app.delivery.client import PERMANENT_REJECTION_STATUSES
from app.delivery.exceptions import EventDeliveryError, EventRejectedError
from app.delivery.schemas import DeliveryOutcome

PLATE_EVENTS_PATH = "/internal/v1/events/plate"


@dataclass(frozen=True)
class PlateEventPayload:
    """Wire contract of POST /internal/v1/events/plate (docs/02-api-contract.md §25.3)."""

    event_uuid: str
    camera_uuid: str
    ai_node_uuid: str
    track_id: str
    occurred_at: datetime
    plate_text: str
    province_text: str | None
    ocr_score: float
    detection_score: float | None
    frames_voted: int | None
    model_code: str
    model_version: str
    reasons: list[str] = field(default_factory=list)

    def to_json(self) -> dict:
        return {
            "event_uuid": self.event_uuid,
            "camera_uuid": self.camera_uuid,
            "ai_node_uuid": self.ai_node_uuid,
            "track_id": self.track_id,
            "occurred_at": self.occurred_at.isoformat(),
            "plate_text": self.plate_text,
            "province_text": self.province_text,
            "ocr_score": self.ocr_score,
            "detection_score": self.detection_score,
            "frames_voted": self.frames_voted,
            "model_code": self.model_code,
            "model_version": self.model_version,
            "reasons": list(self.reasons),
        }


def new_plate_payload(
    *,
    camera_uuid: str,
    ai_node_uuid: str,
    track_id: str,
    occurred_at: datetime,
    plate_text: str,
    province_text: str | None,
    ocr_score: float,
    model_code: str,
    model_version: str,
    detection_score: float | None = None,
    frames_voted: int | None = None,
    reasons: list[str] | None = None,
) -> PlateEventPayload:
    """Build a payload for a fresh read. The event_uuid is minted here, once, and travels
    with the read through every retry."""
    if not 0.0 <= ocr_score <= 1.0:
        raise ValueError("ocr_score must be between 0 and 1")
    return PlateEventPayload(
        event_uuid=str(uuid.uuid4()),
        camera_uuid=camera_uuid,
        ai_node_uuid=ai_node_uuid,
        track_id=track_id,
        occurred_at=occurred_at,
        plate_text=plate_text,
        province_text=province_text,
        ocr_score=ocr_score,
        detection_score=detection_score,
        frames_voted=frames_voted,
        model_code=model_code,
        model_version=model_version,
        reasons=list(reasons or []),
    )


class PlateEventBuffer(OfflineEventBuffer):
    """Durable buffer for plate reads: same file and behaviour as the face-event buffer,
    separate tables so the two queues never mix."""

    PAYLOAD_CLS = PlateEventPayload
    PENDING_TABLE = "pending_plate_events"
    REJECTED_TABLE = "rejected_plate_events"


class PlateEventDeliveryClient(ABC):
    @abstractmethod
    def deliver(self, payload: PlateEventPayload) -> None: ...


class HttpPlateEventDeliveryClient(PlateEventDeliveryClient):
    """Real backend client; httpx is imported lazily (same convention as the face client)."""

    def __init__(self, base_url: str, node_token: str, timeout_seconds: float = 5.0):
        self._base_url = base_url.rstrip("/")
        self._node_token = node_token
        self._timeout_seconds = timeout_seconds

    def deliver(self, payload: PlateEventPayload) -> None:
        try:
            import httpx
        except ImportError as exc:  # pragma: no cover - depends on the deployment
            raise EventDeliveryError("httpx is not installed") from exc

        try:
            response = httpx.post(
                f"{self._base_url}{PLATE_EVENTS_PATH}",
                json=payload.to_json(),
                headers={"Authorization": f"Bearer {self._node_token}"},
                timeout=self._timeout_seconds,
            )
        except Exception as exc:  # noqa: BLE001 — normalise any transport failure
            raise EventDeliveryError(f"Plate event delivery failed: {exc}") from exc

        if response.status_code in PERMANENT_REJECTION_STATUSES:
            try:
                error_code = (response.json().get("error") or {}).get("code")
            except Exception:  # noqa: BLE001 — body is informational only
                error_code = None
            detail = f" {error_code}" if error_code else ""
            raise EventRejectedError(
                f"Plate event rejected by backend: HTTP {response.status_code}{detail}",
                response.status_code,
                error_code,
            )
        if response.status_code not in (200, 201, 202):
            raise EventDeliveryError(f"Plate event delivery returned HTTP {response.status_code}")


class BufferedPlateEventDeliveryService:
    """Deliver now if possible, otherwise buffer durably; retry_pending() drains the buffer."""

    def __init__(self, client: PlateEventDeliveryClient, buffer: PlateEventBuffer):
        self._client = client
        self._buffer = buffer

    def deliver(self, payload: PlateEventPayload) -> DeliveryOutcome:
        try:
            self._client.deliver(payload)
        except EventRejectedError as exc:
            self._buffer.reject(payload, exc.status_code, exc.error_code)
            return DeliveryOutcome(delivered=False, reason="rejected")
        except EventDeliveryError:
            self._buffer.enqueue(payload)
            return DeliveryOutcome(delivered=False, reason="buffered_offline")
        return DeliveryOutcome(delivered=True)

    def retry_pending(self, limit: int = 50) -> RetryBatchResult:
        delivered = 0
        for buffered in self._buffer.due(limit=limit):
            try:
                self._client.deliver(buffered.payload)
            except EventRejectedError as exc:
                self._buffer.reject(buffered.payload, exc.status_code, exc.error_code)
                continue
            except EventDeliveryError:
                self._buffer.mark_failed(buffered.payload.event_uuid)
                continue
            self._buffer.mark_delivered(buffered.payload.event_uuid)
            delivered += 1

        return RetryBatchResult(delivered=delivered, still_pending=self._buffer.size)
