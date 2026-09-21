"""Base face detector interface (FACE-004)."""

from abc import ABC, abstractmethod
from typing import Any

import numpy as np

from app.detection.exceptions import InvalidImageError
from app.detection.schemas import AIModelType, FaceDetection


class FaceDetector(ABC):
    """Abstract interface for face detection providers."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Provider name identifier."""
        pass

    @property
    @abstractmethod
    def version(self) -> str:
        """Detector/model version string."""
        pass

    @property
    def model_type(self) -> AIModelType:
        """Canonical AI model type for registry integration."""
        return AIModelType.FACE_DETECTION

    @abstractmethod
    def detect(self, image: np.ndarray) -> list[FaceDetection]:
        """Detect faces in an input image.

        Args:
            image: numpy.ndarray of shape (H, W, C) or (H, W).

        Returns:
            List of FaceDetection objects (empty list if zero faces detected).

        Raises:
            InvalidImageError: If image is None, empty, or has an invalid shape.
            DetectorNotReadyError: If detector backend is uninitialized/unready.
            DetectionRuntimeError: If runtime execution fails.
        """
        pass

    def validate_image(self, image: Any) -> np.ndarray:
        """Validate input image array against standard requirements.

        Args:
            image: Image candidate.

        Returns:
            Validated numpy ndarray.

        Raises:
            InvalidImageError: If image fails validation.
        """
        if image is None:
            raise InvalidImageError("Input image cannot be None")

        if not isinstance(image, np.ndarray):
            raise InvalidImageError(
                f"Input image must be a numpy.ndarray, got {type(image).__name__}"
            )

        if image.size == 0:
            raise InvalidImageError("Input image array is empty (size 0)")

        if image.ndim == 2:
            height, width = image.shape
            channels = 1
        elif image.ndim == 3:
            height, width, channels = image.shape
            if channels not in (1, 3, 4):
                raise InvalidImageError(
                    f"Input image has unsupported channel count: {channels} (expected 1, 3, or 4)"
                )
        else:
            raise InvalidImageError(
                f"Input image must have 2 or 3 dimensions, got {image.ndim} dimensions"
            )

        if height <= 0 or width <= 0:
            raise InvalidImageError(
                f"Input image dimensions must be positive, got height={height}, width={width}"
            )

        return image
