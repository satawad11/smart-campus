"""Alignment schemas and data contracts (FACE-005)."""

from typing import Any

import numpy as np
from pydantic import BaseModel, ConfigDict, Field, model_validator

from app.detection.exceptions import InvalidImageError
from app.detection.schemas import AIModelType, LandmarkPoint


class FacialLandmarks5Point(BaseModel):
    """Canonical 5-point facial landmark geometry for face alignment."""

    left_eye: LandmarkPoint
    right_eye: LandmarkPoint
    nose: LandmarkPoint
    left_mouth: LandmarkPoint
    right_mouth: LandmarkPoint

    def to_list(self) -> list[LandmarkPoint]:
        """Return landmarks in canonical 5-point order."""
        return [
            self.left_eye,
            self.right_eye,
            self.nose,
            self.left_mouth,
            self.right_mouth,
        ]

    def to_numpy(self) -> np.ndarray:
        """Return (5, 2) float32 numpy array of landmark coordinates."""
        return np.array(
            [
                [self.left_eye.x, self.left_eye.y],
                [self.right_eye.x, self.right_eye.y],
                [self.nose.x, self.nose.y],
                [self.left_mouth.x, self.left_mouth.y],
                [self.right_mouth.x, self.right_mouth.y],
            ],
            dtype=np.float32,
        )

    @classmethod
    def from_list(cls, points: list[LandmarkPoint]) -> "FacialLandmarks5Point":
        """Create canonical 5-point geometry from an ordered list of LandmarkPoints."""
        if len(points) != 5:
            raise ValueError(f"Expected exactly 5 landmark points, got {len(points)}")
        return cls(
            left_eye=points[0],
            right_eye=points[1],
            nose=points[2],
            left_mouth=points[3],
            right_mouth=points[4],
        )


class AlignedFace(BaseModel):
    """Normalized, pose-aligned face output contract."""

    model_config = ConfigDict(arbitrary_types_allowed=True)

    image: Any  # numpy.ndarray (H, W, C)
    width: int = Field(..., gt=0)
    height: int = Field(..., gt=0)
    channels: int = Field(..., gt=0)
    format: str = "RGB"
    transform_matrix: list[list[float]] | None = None
    detector_name: str
    detector_version: str
    aligner_name: str
    aligner_version: str
    model_type: AIModelType = AIModelType.FACE_ALIGNMENT

    @model_validator(mode="after")
    def validate_image_array(self) -> "AlignedFace":
        if not isinstance(self.image, np.ndarray):
            raise InvalidImageError("AlignedFace image must be a numpy.ndarray")
        if self.image.ndim != 3:
            raise InvalidImageError(
                f"AlignedFace image must be 3-dimensional (H, W, C), got {self.image.ndim}"
            )
        h, w, c = self.image.shape
        if h != self.height or w != self.width or c != self.channels:
            raise ValueError(
                f"AlignedFace shape mismatch: array shape is ({h}, {w}, {c}), "
                f"expected ({self.height}, {self.width}, {self.channels})"
            )
        return self
