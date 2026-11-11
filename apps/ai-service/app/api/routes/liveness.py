"""Internal Face Liveness / PAD Route (FACE-015, docs/02-api-contract.md §8)."""

from typing import Annotated

from fastapi import APIRouter, File, HTTPException, UploadFile, status

from app.api.routes.enrollment import decode_image_bytes
from app.core.config import settings
from app.detection.exceptions import DetectorNotReadyError
from app.detection.factory import get_face_detector
from app.liveness.factory import get_face_liveness_detector
from app.liveness.unavailable import LivenessModelUnavailableError
from app.schemas.liveness import (
    FaceLivenessErrorResponse,
    FaceLivenessSuccessResponse,
    LivenessStatus,
)

router = APIRouter()


@router.post(
    "/liveness",
    response_model=FaceLivenessSuccessResponse,
    responses={
        422: {"model": FaceLivenessErrorResponse},
        500: {"model": FaceLivenessErrorResponse},
        503: {"model": FaceLivenessErrorResponse},
    },
)
async def check_liveness(
    file: Annotated[UploadFile, File()],
) -> FaceLivenessSuccessResponse:
    """Evaluate image for Presentation Attack Detection (PAD)."""
    raw_bytes = await file.read()

    try:
        image = decode_image_bytes(raw_bytes)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "success": False,
                "error_code": "INVALID_IMAGE",
                "message": f"Malformed or unreadable image file: {e}",
                "rejection_reasons": ["INVALID_IMAGE"],
            },
        ) from e

    detector = get_face_liveness_detector()
    try:
        # MiniFASNet scores a crop around the face, so locate the (largest) face first.
        bounding_box = None
        if settings.ai_mode.strip().lower() != "mock":
            faces = get_face_detector().detect(image)
            if faces:
                box = faces[0].bbox
                bounding_box = (box.x1, box.y1, box.x2, box.y2)
        result = detector.evaluate(image, bounding_box)
    except (LivenessModelUnavailableError, DetectorNotReadyError) as e:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail={
                "success": False,
                "error_code": "PAD_MODEL_UNAVAILABLE",
                "message": str(e),
                "rejection_reasons": ["PAD_UNAVAILABLE"],
            },
        ) from e
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "success": False,
                "error_code": "LIVENESS_EVALUATION_FAILED",
                "message": f"Liveness perception evaluation failed: {e}",
                "rejection_reasons": ["PROCESSING_ERROR"],
            },
        ) from e

    return FaceLivenessSuccessResponse(
        success=True,
        liveness_score=result.score,
        status=LivenessStatus(result.status),
        is_live=result.is_live,
        method=result.method,
        confidence=0.95,
        rejection_reasons=result.rejection_reasons,
        details=result.details,
    )
