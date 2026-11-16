"""Schemas for the internal face quality check route (FACE-022)."""

from pydantic import BaseModel, Field

from app.schemas.enrollment import EnrollmentBoundingBox, EnrollmentQualitySummary


class FaceQualityCheckResponse(BaseModel):
    """Detection + quality feedback for one captured frame. Never carries an embedding."""

    success: bool = True
    face_count: int = Field(..., ge=0)
    faces: list[EnrollmentBoundingBox] = Field(default_factory=list)
    image_width: int = Field(..., ge=1)
    image_height: int = Field(..., ge=1)
    accepted: bool
    rejection_reasons: list[str] = Field(default_factory=list)
    # Present only when exactly one face was detected and aligned.
    quality: EnrollmentQualitySummary | None = None
