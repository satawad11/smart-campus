"""Internal Face Enrollment Perception Route (FACE-009).

Orchestrates the canonical perception pipeline:
Detection -> Alignment -> Quality Evaluation -> Embedding Generation.
"""

import io
from typing import Annotated

import numpy as np
from fastapi import APIRouter, File, Form, HTTPException, UploadFile, status

from app.alignment.factory import get_face_aligner
from app.core.config import settings
from app.detection.factory import get_face_detector
from app.embeddings.factory import get_face_embedder
from app.quality.factory import get_face_quality_evaluator
from app.schemas.enrollment import (
    EnrollmentBoundingBox,
    EnrollmentQualitySummary,
    FaceEnrollmentPipelineErrorResponse,
    FaceEnrollmentPipelineSuccessResponse,
)

router = APIRouter()


def decode_image_bytes(raw_bytes: bytes) -> np.ndarray:
    """Decode raw image bytes into an RGB uint8 numpy array.

    Supports OpenCV, PIL, and deterministic fallback in mock mode.
    """
    if not raw_bytes:
        raise ValueError("Empty image bytes")

    # Try OpenCV
    try:
        import cv2

        nparr = np.frombuffer(raw_bytes, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if img is not None:
            return cv2.cvtColor(img, cv2.COLOR_BGR2RGB)
    except Exception:
        pass

    # Try PIL
    try:
        from PIL import Image

        pil_img = Image.open(io.BytesIO(raw_bytes)).convert("RGB")
        return np.asarray(pil_img, dtype=np.uint8)
    except Exception:
        pass

    # In mock mode, generate a deterministic synthetic RGB array from payload hash/length
    if settings.ai_mode == "mock" and len(raw_bytes) > 0:
        # Generate reproducible 224x224 RGB image based on content
        seed_val = sum(raw_bytes[: min(100, len(raw_bytes))]) % 256
        arr = np.full((224, 224, 3), fill_value=seed_val, dtype=np.uint8)
        return arr

    raise ValueError("Failed to decode image bytes into valid image array")


@router.post(
    "/enroll",
    response_model=FaceEnrollmentPipelineSuccessResponse,
    responses={
        422: {"model": FaceEnrollmentPipelineErrorResponse},
        500: {"model": FaceEnrollmentPipelineErrorResponse},
    },
)
async def enroll_pipeline(
    file: Annotated[UploadFile, File()],
    pose: Annotated[str, Form()] = "FRONT",
    source: Annotated[str, Form()] = "UPLOAD",
) -> FaceEnrollmentPipelineSuccessResponse:
    """Process a face enrollment image through detection, alignment, quality, and embedding."""
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

    # 1. Detection Stage
    detector = get_face_detector()
    try:
        detections = detector.detect(image)
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

    face_count = len(detections)
    if face_count == 0:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "success": False,
                "error_code": "NO_FACE",
                "message": "No face detected in enrollment image.",
                "face_count": 0,
                "rejection_reasons": ["NO_FACE"],
            },
        )

    if face_count > 1:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "success": False,
                "error_code": "MULTIPLE_FACES",
                "message": (
                    f"Multiple faces ({face_count}) detected. "
                    "Exactly one face is required for enrollment."
                ),
                "face_count": face_count,
                "rejection_reasons": ["MULTIPLE_FACES"],
            },
        )

    detection = detections[0]

    # 2. Alignment Stage
    aligner = get_face_aligner()
    try:
        aligned_face = aligner.align(image, detection)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "success": False,
                "error_code": "ALIGNMENT_FAILED",
                "message": f"Face alignment failed: {e}",
                "face_count": 1,
                "rejection_reasons": ["ALIGNMENT_FAILED"],
            },
        ) from e

    # 3. Quality Evaluation Stage
    quality_evaluator = get_face_quality_evaluator()
    try:
        quality_res = quality_evaluator.evaluate(aligned_face, detection)
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

    quality_summary = EnrollmentQualitySummary(
        quality_score=quality_res.quality_score,
        blur_score=quality_res.blur_score,
        brightness_score=quality_res.brightness_score,
        face_size_score=quality_res.face_size_score,
        pose_score=quality_res.pose_score,
        yaw=quality_res.yaw,
        pitch=quality_res.pitch,
        roll=quality_res.roll,
        accepted=quality_res.accepted,
        rejection_reasons=[r.value for r in quality_res.rejection_reasons],
    )

    if not quality_res.accepted:
        raise HTTPException(
            status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
            detail={
                "success": False,
                "error_code": "FACE_QUALITY_REJECTED",
                "message": "Face quality criteria not met for biometric enrollment.",
                "face_count": 1,
                "rejection_reasons": [r.value for r in quality_res.rejection_reasons],
                "quality": quality_summary.model_dump(),
            },
        )

    # 4. Embedding Generation Stage
    embedder = get_face_embedder()
    try:
        embedding_res = embedder.generate(aligned_face)
    except Exception as e:
        raise HTTPException(
            status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
            detail={
                "success": False,
                "error_code": "EMBEDDING_GENERATION_FAILED",
                "message": f"Biometric embedding generation failure: {e}",
                "face_count": 1,
                "rejection_reasons": ["EMBEDDING_GENERATION_FAILED"],
            },
        ) from e

    bbox = EnrollmentBoundingBox(
        x1=detection.bbox.x1,
        y1=detection.bbox.y1,
        x2=detection.bbox.x2,
        y2=detection.bbox.y2,
        confidence=detection.confidence,
    )

    return FaceEnrollmentPipelineSuccessResponse(
        success=True,
        face_count=1,
        model_code=embedding_res.model_code,
        model_version=embedding_res.model_version,
        model_type=embedding_res.model_type.value,
        embedding_dimension=embedding_res.dimension,
        embedding=embedding_res.vector,
        quality=quality_summary,
        bounding_box=bbox,
    )
