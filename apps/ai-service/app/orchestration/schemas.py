"""Recognition worker orchestration schemas (CAM-009)."""

from dataclasses import dataclass, field
from datetime import UTC, datetime


@dataclass(frozen=True)
class BackendRecognitionResponse:
    """Normalized result of calling the backend's internal
    /internal/v1/face/recognize (candidate search + FACE-013 classification).
    Mirrors FaceRecognitionClassificationResult's safe fields — no raw
    vector, no internal id, no access decision."""

    classification: str  # "MATCH" | "REVIEW" | "UNKNOWN"
    candidate_person_uuid: str | None
    similarity: float | None
    distance: float | None
    reasons: list[str]


@dataclass(frozen=True)
class RecognitionWorkerResult:
    """One completed (or locally-rejected) recognition attempt for a track.

    Time semantics kept distinct: observed_at is when the underlying camera
    frame was captured (business time, carried through unchanged from
    SampledFrame/Frame); processed_at is when this worker finished. Neither
    is ever replaced by the other.

    classification stays within the canonical MATCH/REVIEW/UNKNOWN set even
    for a locally-rejected attempt (quality/liveness failure never reaches
    the backend) — such cases are UNKNOWN with rejected_locally=True and the
    specific reasons recorded, never a fabricated 4th classification value.
    No access decision (ALLOW/REVIEW/DENY) is ever produced here — that is
    Module 05's exclusive concern.
    """

    event_uuid: str
    camera_uuid: str
    ai_node_uuid: str
    track_id: str
    observed_at: datetime
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
    processed_at: datetime = field(default_factory=lambda: datetime.now(UTC))
