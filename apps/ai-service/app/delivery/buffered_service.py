"""Delivery service with durable offline fallback (CAM-012).

Wraps EventDeliveryService (CAM-011): a fresh result is still delivered
immediately when possible. Only on delivery failure does this layer take
over — it enqueues the event in the durable OfflineEventBuffer instead of
letting EventDeliveryError propagate, and retry_pending() is called
periodically (by the AI node's own scheduling loop, outside this module's
scope) to drain the buffer with backoff.
"""

from dataclasses import dataclass

from app.delivery.buffer import OfflineEventBuffer
from app.delivery.client import EventDeliveryClient
from app.delivery.exceptions import EventDeliveryError, EventRejectedError
from app.delivery.schemas import DeliveryOutcome
from app.delivery.service import to_event_payload
from app.orchestration.schemas import RecognitionWorkerResult


@dataclass(frozen=True)
class RetryBatchResult:
    delivered: int
    still_pending: int


class BufferedEventDeliveryService:
    def __init__(self, client: EventDeliveryClient, buffer: OfflineEventBuffer):
        self._client = client
        self._buffer = buffer

    def deliver(self, result: RecognitionWorkerResult) -> DeliveryOutcome:
        if result.from_cache:
            return DeliveryOutcome(delivered=False, skipped=True, reason="from_cache")

        payload = to_event_payload(result)
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
        """Attempt delivery of due-for-retry buffered events. A delivered
        event is removed; a still-failing one has its backoff advanced and
        stays in the buffer — never dropped."""
        due = self._buffer.due(limit=limit)
        delivered = 0
        for buffered in due:
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
