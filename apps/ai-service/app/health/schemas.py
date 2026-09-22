"""Camera health collection schemas (CAM-013)."""

from dataclasses import dataclass, field
from datetime import UTC, datetime


@dataclass(frozen=True)
class FrameMetrics:
    """Per-frame image-quality signal (0.0..1.0, higher is better — same
    direction convention as FACE-006's FaceQualityResult.blur_score /
    brightness_score, computed here on the whole raw frame rather than an
    aligned face)."""

    brightness_score: float
    blur_score: float


@dataclass(frozen=True)
class CameraHealthSample:
    """One collected telemetry snapshot, matching the wire contract of
    POST /internal/v1/cameras/{cameraUuid}/health (docs/02-api-contract.md).
    No status field: the backend derives HealthStatus from these raw
    numbers itself (CameraHealthService) rather than trusting a
    self-reported value — same boundary as CAM-003's AiNode heartbeat."""

    camera_uuid: str
    fps: float | None
    latency_ms: int | None
    dropped_frames: int
    resolution: str | None
    brightness_score: float | None
    blur_score: float | None
    reconnect_count: int
    recorded_at: datetime = field(default_factory=lambda: datetime.now(UTC))
