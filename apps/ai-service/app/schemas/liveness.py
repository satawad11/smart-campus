"""Schemas for Face Liveness / Presentation Attack Detection (PAD) (FACE-015)."""

from enum import StrEnum
from typing import Any

from pydantic import BaseModel, Field


class LivenessStatus(StrEnum):
    REAL = "REAL"
    SPOOF = "SPOOF"
    UNCERTAIN = "UNCERTAIN"


class LivenessMethod(StrEnum):
    PASSIVE_TEXTURE = "PASSIVE_TEXTURE"
    MULTI_CUE = "MULTI_CUE"
    MOCK = "MOCK"


class FaceLivenessSuccessResponse(BaseModel):
    """Successful liveness/PAD evaluation response."""

    success: bool = True
    liveness_score: float = Field(..., ge=0.0, le=1.0)
    status: LivenessStatus
    is_live: bool
    method: str
    confidence: float = Field(default=0.95, ge=0.0, le=1.0)
    rejection_reasons: list[str] = Field(default_factory=list)
    details: dict[str, Any] = Field(default_factory=dict)


class FaceLivenessErrorResponse(BaseModel):
    """Structured error response for failed liveness evaluation."""

    success: bool = False
    error_code: str
    message: str
    rejection_reasons: list[str] = Field(default_factory=list)
    details: dict[str, Any] | None = None
