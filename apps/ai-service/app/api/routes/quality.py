"""Internal Face Quality Check Route (FACE-022, docs/02-api-contract.md §8).

Live-capture feedback for webcam enrollment and scanning:
Detection -> (exactly one face) Alignment -> Quality Evaluation.

No embedding is generated and nothing is stored: the response carries only face
locations and technical quality metrics, so the browser can draw the detection box and
show the capture indicators (face detected, brightness, sharpness, face size, pose).
"""

from typing import Annotated

from fastapi import APIRouter, File, HTTPException, UploadFile, status

from app.alignment.factory import get_face_aligner
from app.api.routes.enrollment import decode_image_bytes
from app.detection.factory import get_face_detector
from app.quality.factory import get_face_quality_evaluator
from app.schemas.enrollment import (
    EnrollmentBoundingBox,
    EnrollmentQualitySummary,
    FaceEnrollmentPipelineErrorResponse,
)
from app.schemas.quality_check import FaceQualityCheckResponse

router = APIRouter()


@router.post(
    "/check-quality",
    response_model=FaceQualityCheckResponse,
    responses={
        422: {"model": FaceEnrollmentPipelineErrorResponse},
        500: {"model": FaceEnrollmentPipelineErrorResponse},
    },
)
async def check_quality(file: Annotated[UploadFile, File()]) -> FaceQualityCheckResponse:
    """Detect faces and evaluate capture quality without producing an embedding."""
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
                "face_count": 0,
                "rejection_reasons": ["INVALID_IMAGE"],
            },
        ) from e

    image_height, image_width = int(image.shape[0]), int(image.shape[1])

    try:
        detections = get_face_detector().detect(image)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "success": False,
                "error_code": "DETECTION_ERROR",
                "message": f"Face detection runtime failure: {e}",
                "face_count": 0,
                "rejection_reasons": ["DETECTION_ERROR"],
            },
        ) from e

    faces = [
        EnrollmentBoundingBox(
            x1=d.bbox.x1,
            y1=d.bbox.y1,
            x2=d.bbox.x2,
            y2=d.bbox.y2,
            confidence=d.confidence,
        )
        for d in detections
    ]
    base = {
        "face_count": len(faces),
        "faces": faces,
        "image_width": image_width,
        "image_height": image_height,
    }

    if len(detections) == 0:
        return FaceQualityCheckResponse(**base, accepted=False, rejection_reasons=["NO_FACE"])

    if len(detections) > 1:
        return FaceQualityCheckResponse(
            **base, accepted=False, rejection_reasons=["MULTIPLE_FACES"]
        )

    detection = detections[0]
    try:
        aligned_face = get_face_aligner().align(image, detection)
    except Exception:
        return FaceQualityCheckResponse(
            **base, accepted=False, rejection_reasons=["ALIGNMENT_FAILED"]
        )

    try:
        quality_res = get_face_quality_evaluator().evaluate(aligned_face, detection)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "success": False,
                "error_code": "QUALITY_EVALUATION_ERROR",
                "message": f"Face quality evaluation runtime failure: {e}",
                "face_count": 1,
                "rejection_reasons": ["QUALITY_EVALUATION_ERROR"],
            },
        ) from e

    reasons = [r.value for r in quality_res.rejection_reasons]
    return FaceQualityCheckResponse(
        **base,
        accepted=quality_res.accepted,
        rejection_reasons=reasons,
        quality=EnrollmentQualitySummary(
            quality_score=quality_res.quality_score,
            blur_score=quality_res.blur_score,
            brightness_score=quality_res.brightness_score,
            face_size_score=quality_res.face_size_score,
            pose_score=quality_res.pose_score,
            yaw=quality_res.yaw,
            pitch=quality_res.pitch,
            roll=quality_res.roll,
            accepted=quality_res.accepted,
            rejection_reasons=reasons,
        ),
    )
