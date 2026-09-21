"""Frame sampler schemas (CAM-007)."""

from dataclasses import dataclass, field
from datetime import UTC, datetime

from app.streams.schemas import Frame


@dataclass(frozen=True)
class SampledFrame:
    """A frame the sampler decided to keep for downstream processing.

    Time semantics (deliberately kept distinct, never conflated):
      - frame.captured_at: when the source captured the frame (business/
        observation time). Never replaced or derived from an index.
      - sampled_at: when the sampler decided to keep it (wall clock).

    Identity: camera_uuid is attached here (the sampler's caller supplies
    it) — VideoSource itself is camera-agnostic (CAM-005). sampled_sequence
    is the sampler's own monotonic counter of KEPT frames only, distinct
    from frame.sequence_number (the source's raw read counter, which also
    counts frames that were dropped and never reached here).
    """

    frame: Frame
    camera_uuid: str
    sampled_sequence: int
    sampled_at: datetime = field(default_factory=lambda: datetime.now(UTC))
