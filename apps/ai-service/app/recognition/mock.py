"""Deterministic mock recognition (FND-005).

Lets the frontend/backend be developed without real AI hardware. Each mock
outcome maps to a perception result; no real detection/embedding is performed.
"""

from app.schemas.recognition import (
    Classification,
    MockOutcome,
    RecognizeRequest,
    RecognizeResponse,
)

MODEL_NAME = "mock-face"
MODEL_VERSION = "0.0.0"

# Fixed sample UUID returned for a mock MATCH (not a real person record).
_MOCK_PERSON_UUID = "00000000-0000-0000-0000-000000000001"

_OUTCOMES: dict[MockOutcome, dict] = {
    MockOutcome.MATCH: {
        "classification": Classification.MATCH,
        "person_uuid": _MOCK_PERSON_UUID,
        "score": 0.98,
        "quality_ok": True,
        "liveness_ok": True,
    },
    MockOutcome.UNKNOWN: {
        "classification": Classification.UNKNOWN,
        "person_uuid": None,
        "score": 0.10,
        "quality_ok": True,
        "liveness_ok": True,
    },
    MockOutcome.LOW_CONFIDENCE: {
        "classification": Classification.REVIEW,
        "person_uuid": None,
        "score": 0.55,
        "quality_ok": True,
        "liveness_ok": True,
    },
    MockOutcome.LOW_QUALITY: {
        "classification": Classification.UNKNOWN,
        "person_uuid": None,
        "score": 0.0,
        "quality_ok": False,
        "liveness_ok": True,
    },
    MockOutcome.LIVENESS_FAIL: {
        "classification": Classification.REVIEW,
        "person_uuid": None,
        "score": 0.0,
        "quality_ok": True,
        "liveness_ok": False,
    },
}


def recognize(request: RecognizeRequest) -> RecognizeResponse:
    outcome = request.mock_outcome or MockOutcome.MATCH
    fields = _OUTCOMES[outcome]
    return RecognizeResponse(
        mode="mock",
        outcome=outcome,
        model_name=MODEL_NAME,
        model_version=MODEL_VERSION,
        **fields,
    )
