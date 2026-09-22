"""Recognition-event delivery module exports (CAM-011/CAM-012)."""

from app.delivery.buffer import BufferedEvent, OfflineEventBuffer
from app.delivery.buffered_service import BufferedEventDeliveryService, RetryBatchResult
from app.delivery.client import EventDeliveryClient, HttpEventDeliveryClient
from app.delivery.exceptions import EventDeliveryError
from app.delivery.schemas import DeliveryOutcome, RecognitionEventPayload
from app.delivery.service import EventDeliveryService, to_event_payload

__all__ = [
    "EventDeliveryClient",
    "HttpEventDeliveryClient",
    "EventDeliveryError",
    "RecognitionEventPayload",
    "DeliveryOutcome",
    "EventDeliveryService",
    "to_event_payload",
    "OfflineEventBuffer",
    "BufferedEvent",
    "BufferedEventDeliveryService",
    "RetryBatchResult",
]
