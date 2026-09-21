"""Schemas for internal face enrollment perception pipeline (FACE-009)."""

from typing import Any

from pydantic import BaseModel, Field


class EnrollmentQualitySummary(BaseModel):
    """Quality summary from evaluation."""

    quality_score: float = Field(..., ge=0.0, le=1.0)
    blur_score: float = Field(..., ge=0.0, le=1.0)
    brightness_score: float = Field(..., ge=0.0, le=1.0)
    face_size_score: float = Field(..., ge=0.0, le=1.0)
    pose_score: float = Field(..., ge=0.0, le=1.0)
    yaw: float | None = None
    pitch: float | None = None
    roll: float | None = None
    accepted: bool
    rejection_reasons: list[str] = Field(default_factory=list)


class EnrollmentBoundingBox(BaseModel):
    """Bounding box coordinates for detected face."""

    x1: float
    y1: float
    x2: float
    y2: float
    confidence: float = Field(..., ge=0.0, le=1.0)


class FaceEnrollmentPipelineSuccessResponse(BaseModel):
    """Successful enrollment perception pipeline response."""

    success: bool = True
    face_count: int = 1
    model_code: str
    model_version: str
    model_type: str = "FACE_EMBEDDING"
    embedding_dimension: int = 512
    embedding: list[float]
    quality: EnrollmentQualitySummary
    bounding_box: EnrollmentBoundingBox | None = None


class FaceEnrollmentPipelineErrorResponse(BaseModel):
    """Structured error response for failed perception pipeline."""

    success: bool = False
    error_code: str
    message: str
    face_count: int = 0
    rejection_reasons: list[str] = Field(default_factory=list)
    quality: EnrollmentQualitySummary | None = None
    details: dict[str, Any] | None = None
