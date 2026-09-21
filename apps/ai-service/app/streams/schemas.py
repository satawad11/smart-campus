"""VideoSource schemas and data contracts (CAM-005)."""

from dataclasses import dataclass, field
from datetime import UTC, datetime
from enum import StrEnum
from typing import Any


class SourceState(StrEnum):
    """Lifecycle state of a VideoSource."""

    CLOSED = "CLOSED"
    OPEN = "OPEN"
    ERROR = "ERROR"


@dataclass(frozen=True)
class Frame:
    """One captured video frame.

    `data` is a raw pixel array (e.g. numpy.ndarray, shape (H, W, C)) — kept
    as a plain dataclass rather than a Pydantic model, matching how the rest
    of this service already passes frame arrays as plain values outside
    Pydantic (see app.detection.FaceDetector.detect).

    Time semantics: `captured_at` is when the frame was captured by the
    source (business/observation time). It must never be silently replaced
    by whenever a downstream consumer happens to process it — that
    distinction matters for later modules (CAM-007+, event delivery,
    reporting).
    """

    data: Any
    width: int
    height: int
    sequence_number: int
    source_name: str
    captured_at: datetime = field(default_factory=lambda: datetime.now(UTC))
