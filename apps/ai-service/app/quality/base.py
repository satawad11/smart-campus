"""Base face quality evaluator interface and validation (FACE-006)."""

from abc import ABC, abstractmethod
from typing import Any

import numpy as np

from app.alignment.schemas import AlignedFace
from app.detection.schemas import AIModelType, FaceDetection
from app.quality.exceptions import InvalidQualityInputError
from app.quality.schemas import FaceQualityResult, QualityThresholds


class FaceQualityEvaluator(ABC):
    """Abstract interface for face quality evaluation providers."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Evaluator provider identifier."""
        pass

    @property
    @abstractmethod
    def version(self) -> str:
        """Evaluator version string."""
        pass

    @property
    def model_type(self) -> AIModelType:
        """Canonical AI model registry type."""
        return AIModelType.FACE_QUALITY

    @property
    @abstractmethod
    def thresholds(self) -> QualityThresholds:
        """Configured quality thresholds and weights."""
        pass

    @abstractmethod
    def evaluate(
        self,
        aligned_face: AlignedFace | np.ndarray,
        detection: FaceDetection | None = None,
    ) -> FaceQualityResult:
        """Evaluate technical image quality of an aligned or detected face.

        Args:
            aligned_face: AlignedFace object or raw numpy image array.
            detection: Optional source FaceDetection with bounding box and landmarks.

        Returns:
            FaceQualityResult with individual metrics, overall score, and acceptance status.

        Raises:
            InvalidQualityInputError: If input image or detection data is invalid or non-finite.
            QualityEvaluatorNotReadyError: If evaluator backend is not ready.
            QualityEvaluationRuntimeError: If metric calculation fails.
        """
        pass

    def extract_and_validate_image(
        self, aligned_face: Any, detection: Any | None = None
    ) -> np.ndarray:
        """Extract and validate the face image array.

        Args:
            aligned_face: AlignedFace instance or numpy ndarray.
            detection: Optional FaceDetection instance.

        Returns:
            Validated numpy ndarray.

        Raises:
            InvalidQualityInputError: If input fails validation.
        """
        if aligned_face is None:
            raise InvalidQualityInputError("Input aligned_face cannot be None")

        if isinstance(aligned_face, AlignedFace):
            image = aligned_face.image
        elif isinstance(aligned_face, np.ndarray):
            image = aligned_face
        else:
            raise InvalidQualityInputError(
                f"Input must be an AlignedFace or numpy.ndarray, got {type(aligned_face).__name__}"
            )

        if not isinstance(image, np.ndarray):
            raise InvalidQualityInputError(
                f"Image must be a numpy.ndarray, got {type(image).__name__}"
            )

        if image.size == 0:
            raise InvalidQualityInputError("Input image is empty (size 0)")

        if image.ndim not in (2, 3):
            raise InvalidQualityInputError(
                f"Input image must have 2 or 3 dimensions, got {image.ndim}"
            )

        if image.shape[0] <= 0 or image.shape[1] <= 0:
            raise InvalidQualityInputError("Input image has non-positive spatial dimensions")

        if np.issubdtype(image.dtype, np.floating) and not np.all(np.isfinite(image)):
            raise InvalidQualityInputError("Input floating-point image contains NaN or Inf values")

        if detection is not None and not isinstance(detection, FaceDetection):
            raise InvalidQualityInputError(
                f"Expected FaceDetection instance, got {type(detection).__name__}"
            )

        return image
