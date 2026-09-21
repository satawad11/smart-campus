"""Face embedding result schemas and data contracts (FACE-007)."""

import math
from typing import Literal

import numpy as np
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

from app.detection.schemas import AIModelType


class FaceEmbeddingResult(BaseModel):
    """Normalized biometric face embedding vector result contract."""

    model_config = ConfigDict(extra="forbid")

    vector: list[float]
    dimension: int = Field(..., gt=0)
    normalized: bool = True
    model_type: Literal[AIModelType.FACE_EMBEDDING] = AIModelType.FACE_EMBEDDING
    model_code: str
    model_version: str
    provider: str

    @field_validator("vector")
    @classmethod
    def validate_vector_elements(cls, v: list[float]) -> list[float]:
        if not v:
            raise ValueError("Embedding vector cannot be empty")
        for i, val in enumerate(v):
            if not math.isfinite(val):
                raise ValueError(f"Vector element at index {i} is not finite: {val}")
        return v

    @model_validator(mode="after")
    def validate_dimension_and_norm(self) -> "FaceEmbeddingResult":
        if len(self.vector) != self.dimension:
            raise ValueError(
                f"Embedding vector dimension mismatch: got {len(self.vector)} elements, "
                f"expected {self.dimension}"
            )
        if self.normalized:
            norm = float(np.linalg.norm(self.vector))
            if norm <= 1e-6:
                raise ValueError("Normalized embedding vector cannot have zero norm")
            if abs(norm - 1.0) > 1e-3:
                raise ValueError(
                    f"Vector is marked as normalized but has L2 norm {norm:.6f} (expected ~1.0)"
                )
        return self
