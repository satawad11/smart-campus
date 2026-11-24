"""Recognition-event delivery orchestration (CAM-011).

Composes a completed RecognitionWorkerResult into the wire-contract
RecognitionEventPayload and makes a single delivery attempt via an injected
EventDeliveryClient. Does not retry and does not buffer on failure — durable
offline storage/retry on backend-unavailable is CAM-012's concern; this
module raises EventDeliveryError and lets the caller decide.
"""

from app.delivery.client import EventDeliveryClient
from app.delivery.schemas import DeliveryOutcome, RecognitionEventPayload
from app.orchestration.schemas import RecognitionWorkerResult


def to_event_payload(result: RecognitionWorkerResult) -> RecognitionEventPayload:
    return RecognitionEventPayload(
        event_uuid=result.event_uuid,
        camera_uuid=result.camera_uuid,
        ai_node_uuid=result.ai_node_uuid,
        track_id=result.track_id,
        occurred_at=result.observed_at,
        classification=result.classification,
        candidate_person_uuid=result.candidate_person_uuid,
        similarity=result.similarity,
        distance=result.distance,
        model_code=result.model_code,
        model_version=result.model_version,
        quality_score=result.quality_score,
        liveness_status=result.liveness_status,
        rejected_locally=result.rejected_locally,
        reasons=result.reasons,
    )


class EventDeliveryService:
    """Delivers newly-computed recognition results as events.

    A cache hit (result.from_cache=True) carries the same event_uuid as the
    attempt that first produced it — re-delivering it would be a redundant,
    idempotent-but-wasteful retransmission of an event already sent, so it
    is skipped rather than sent again.
    """

    def __init__(self, client: EventDeliveryClient):
        self._client = client

    def deliver(self, result: RecognitionWorkerResult) -> DeliveryOutcome:
        if result.from_cache:
            return DeliveryOutcome(delivered=False, skipped=True, reason="from_cache")
        if result.suppress_delivery:
            return DeliveryOutcome(delivered=False, skipped=True, reason="not_improved")

        payload = to_event_payload(result)
        self._client.deliver(payload)
        return DeliveryOutcome(delivered=True)
