"""Recognition-event delivery schemas (CAM-011)."""

from dataclasses import dataclass, field
from datetime import datetime


@dataclass(frozen=True)
class RecognitionEventPayload:
    """The wire contract sent to POST /internal/v1/events/recognition
    (docs/02-api-contract.md §12). Carries only what the AI node itself
    determined — classification (MATCH/REVIEW/UNKNOWN) and its evidence.

    No access decision (ALLOW/REVIEW/DENY) field exists here: that is
    computed by the backend's policy matcher, exclusively Module 05's
    concern. occurred_at is business time (when the frame was captured,
    carried through unchanged from RecognitionWorkerResult.observed_at) —
    never replaced by when this payload happened to be built or sent.
    """

    event_uuid: str
    camera_uuid: str
    ai_node_uuid: str
    track_id: str
    occurred_at: datetime
    classification: str
    candidate_person_uuid: str | None
    similarity: float | None
    distance: float | None
    model_code: str
    model_version: str
    quality_score: float | None
    liveness_status: str | None
    rejected_locally: bool
    reasons: list[str] = field(default_factory=list)


@dataclass(frozen=True)
class DeliveryOutcome:
    """Result of one deliver() call. skipped=True (delivered=False, no
    exception) means the caller correctly chose not to send anything —
    e.g. a cached result that isn't a new recognition occurrence — and is
    not itself a failure."""

    delivered: bool
    skipped: bool = False
    reason: str | None = None
