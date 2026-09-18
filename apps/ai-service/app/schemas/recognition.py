"""Recognition request/response schemas (FND-005).

The AI service returns *perception* results only. Classification values follow
PROJECT_RULES.md §10 (MATCH / REVIEW / UNKNOWN). Access decisions are made by
the backend policy engine, never here.
"""

from enum import StrEnum

from pydantic import BaseModel, ConfigDict, Field


class MockOutcome(StrEnum):
    """Mock perception outcomes (development blueprint §13)."""

    MATCH = "MATCH"
    UNKNOWN = "UNKNOWN"
    LOW_CONFIDENCE = "LOW_CONFIDENCE"
    LOW_QUALITY = "LOW_QUALITY"
    LIVENESS_FAIL = "LIVENESS_FAIL"


class Classification(StrEnum):
    """Recognition classification (PROJECT_RULES.md §10)."""

    MATCH = "MATCH"
    REVIEW = "REVIEW"
    UNKNOWN = "UNKNOWN"


class RecognizeRequest(BaseModel):
    image_ref: str | None = Field(
        default=None,
        description="Reference to an image (id/path/URL). Ignored in mock mode.",
    )
    mock_outcome: MockOutcome | None = Field(
        default=None,
        description="Force a specific mock outcome (development/testing only).",
    )


class RecognizeResponse(BaseModel):
    # Allow model_name/model_version without clashing with pydantic's
    # protected "model_" namespace.
    model_config = ConfigDict(protected_namespaces=())

    mode: str
    outcome: MockOutcome
    classification: Classification
    person_uuid: str | None = None
    score: float
    quality_ok: bool
    liveness_ok: bool
    model_name: str
    model_version: str
