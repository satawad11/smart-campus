"""Recognition endpoint (FND-005).

Returns perception results only. In this task only AI_MODE=mock is supported;
real detection/embedding/matching pipelines are later tasks.
"""

from fastapi import APIRouter, HTTPException

from app.core.config import settings
from app.recognition import mock
from app.schemas.recognition import RecognizeRequest, RecognizeResponse

router = APIRouter()


@router.post("/recognize", response_model=RecognizeResponse)
def recognize(request: RecognizeRequest) -> RecognizeResponse:
    if settings.ai_mode == "mock":
        return mock.recognize(request)

    # Real pipelines (detection/quality/liveness/alignment/embedding/search)
    # are out of scope for FND-005.
    raise HTTPException(
        status_code=503,
        detail=f"AI_MODE '{settings.ai_mode}' is not implemented yet",
    )
