"""Quality evaluation schemas, contracts, and rejection reasons (FACE-006)."""

import math
from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field, field_validator

from app.detection.schemas import AIModelType


class QualityRejectionReason(StrEnum):
    """Machine-readable quality rejection reason codes matching blueprint."""

    IMAGE_BLUR = "IMAGE_BLUR"
    IMAGE_TOO_DARK = "IMAGE_TOO_DARK"
    IMAGE_TOO_BRIGHT = "IMAGE_TOO_BRIGHT"
    FACE_TOO_SMALL = "FACE_TOO_SMALL"
    BAD_POSE = "BAD_POSE"
    LOW_RESOLUTION = "LOW_RESOLUTION"
    LOW_QUALITY = "LOW_QUALITY"
    INVALID_IMAGE = "INVALID_IMAGE"


class QualityThresholds(BaseModel):
    """Configurable thresholds and weights for quality evaluation."""

    min_blur_score: float = Field(0.35, ge=0.0, le=1.0)
    min_brightness_score: float = Field(0.30, ge=0.0, le=1.0)
    min_face_size_score: float = Field(0.30, ge=0.0, le=1.0)
    min_pose_score: float = Field(0.40, ge=0.0, le=1.0)
    max_yaw_degrees: float = Field(30.0, ge=0.0, le=90.0)
    max_pitch_degrees: float = Field(25.0, ge=0.0, le=90.0)
    max_roll_degrees: float = Field(25.0, ge=0.0, le=180.0)
    min_quality_score: float = Field(0.50, ge=0.0, le=1.0)
    # Mean luminance (0..1) below/above which the frame is too dark/bright (CAM-022)
    min_luminance: float = Field(0.20, ge=0.0, le=1.0)
    max_luminance: float = Field(0.85, ge=0.0, le=1.0)

    # Weighted contribution to overall quality score
    weight_blur: float = Field(0.35, ge=0.0, le=1.0)
    weight_brightness: float = Field(0.25, ge=0.0, le=1.0)
    weight_pose: float = Field(0.25, ge=0.0, le=1.0)
    weight_face_size: float = Field(0.15, ge=0.0, le=1.0)


class FaceQualityResult(BaseModel):
    """Quality evaluation result data contract."""

    model_config = ConfigDict(extra="forbid")

    quality_score: float = Field(..., ge=0.0, le=1.0)
    blur_score: float = Field(..., ge=0.0, le=1.0)
    brightness_score: float = Field(..., ge=0.0, le=1.0)
    face_size_score: float = Field(..., ge=0.0, le=1.0)
    pose_score: float = Field(..., ge=0.0, le=1.0)

    yaw: float | None = None
    pitch: float | None = None
    roll: float | None = None

    accepted: bool
    rejection_reasons: list[QualityRejectionReason] = Field(default_factory=list)

    evaluator_name: str
    evaluator_version: str
    model_type: AIModelType = AIModelType.FACE_QUALITY

    @field_validator(
        "quality_score",
        "blur_score",
        "brightness_score",
        "face_size_score",
        "pose_score",
    )
    @classmethod
    def validate_finite_scores(cls, v: float) -> float:
        if not math.isfinite(v):
            raise ValueError("Quality score metrics must be finite numbers")
        return float(v)

    @field_validator("yaw", "pitch", "roll")
    @classmethod
    def validate_optional_angles(cls, v: float | None) -> float | None:
        if v is not None and not math.isfinite(v):
            raise ValueError("Pose angles must be finite numbers")
        return float(v) if v is not None else None
