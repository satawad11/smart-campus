"""Detection schemas and data contracts (FACE-004)."""

import math
from enum import StrEnum

from pydantic import BaseModel, Field, field_validator, model_validator


class AIModelType(StrEnum):
    """Canonical AI model types matching repository contracts."""

    FACE_DETECTION = "FACE_DETECTION"
    FACE_ALIGNMENT = "FACE_ALIGNMENT"
    FACE_QUALITY = "FACE_QUALITY"
    FACE_RECOGNITION = "FACE_RECOGNITION"
    FACE_EMBEDDING = "FACE_EMBEDDING"
    LIVENESS = "LIVENESS"
    OTHER = "OTHER"


class LandmarkPoint(BaseModel):
    """2D facial landmark point."""

    x: float
    y: float

    @field_validator("x", "y")
    @classmethod
    def validate_finite(cls, v: float) -> float:
        if not math.isfinite(v):
            raise ValueError("Landmark coordinates must be finite numbers")
        return float(v)


class BoundingBox(BaseModel):
    """Axis-aligned 2D bounding box representing detected face location.

    Invariants:
        - Coordinates must be finite floating-point numbers.
        - x2 > x1, y2 > y1 (strictly positive width, height, and area).
    """

    x1: float
    y1: float
    x2: float
    y2: float

    @field_validator("x1", "y1", "x2", "y2")
    @classmethod
    def validate_finite(cls, v: float) -> float:
        if not math.isfinite(v):
            raise ValueError("BoundingBox coordinates must be finite numbers")
        return float(v)

    @model_validator(mode="after")
    def validate_coordinates(self) -> "BoundingBox":
        if self.x2 <= self.x1:
            raise ValueError(f"x2 ({self.x2}) must be strictly greater than x1 ({self.x1})")
        if self.y2 <= self.y1:
            raise ValueError(f"y2 ({self.y2}) must be strictly greater than y1 ({self.y1})")
        return self

    @property
    def width(self) -> float:
        return self.x2 - self.x1

    @property
    def height(self) -> float:
        return self.y2 - self.y1

    @property
    def area(self) -> float:
        return self.width * self.height

    def clamp(self, max_width: float, max_height: float) -> "BoundingBox | None":
        """Return a new BoundingBox clamped to [0, max_width] and [0, max_height].

        If clamping collapses the bounding box to non-positive width/height,
        returns None so callers can reject/discard it deterministically.
        """
        clamped_x1 = max(0.0, min(float(max_width), self.x1))
        clamped_y1 = max(0.0, min(float(max_height), self.y1))
        clamped_x2 = max(0.0, min(float(max_width), self.x2))
        clamped_y2 = max(0.0, min(float(max_height), self.y2))

        if clamped_x2 <= clamped_x1 or clamped_y2 <= clamped_y1:
            return None

        return BoundingBox(x1=clamped_x1, y1=clamped_y1, x2=clamped_x2, y2=clamped_y2)


class FaceDetection(BaseModel):
    """Face detection result."""

    bbox: BoundingBox
    confidence: float = Field(..., ge=0.0, le=1.0)
    landmarks: list[LandmarkPoint] | None = None
    detector_name: str
    detector_version: str
    model_type: AIModelType = AIModelType.FACE_DETECTION
