"""Base face aligner interface and validation (FACE-005)."""

import math
from abc import ABC, abstractmethod
from typing import Any

import numpy as np

from app.alignment.exceptions import InvalidLandmarksError
from app.alignment.schemas import AlignedFace, FacialLandmarks5Point
from app.detection.exceptions import InvalidImageError
from app.detection.schemas import AIModelType, FaceDetection, LandmarkPoint


class FaceDetectorInputError(InvalidLandmarksError):
    """Raised when detection input does not contain valid detection object."""

    pass


class FaceAligner(ABC):
    """Abstract interface for face alignment providers."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Aligner provider identifier."""
        pass

    @property
    @abstractmethod
    def version(self) -> str:
        """Aligner implementation version string."""
        pass

    @property
    def model_type(self) -> AIModelType:
        """Canonical AI model registry type."""
        return AIModelType.FACE_ALIGNMENT

    @property
    def target_size(self) -> tuple[int, int]:
        """Target alignment dimensions (width, height). Defaults to (112, 112)."""
        return (112, 112)

    @property
    def output_format(self) -> str:
        """Canonical image channel ordering."""
        return "RGB"

    @abstractmethod
    def align(self, image: np.ndarray, detection: FaceDetection) -> AlignedFace:
        """Align detected face to normalized canonical pose and dimensions.

        Args:
            image: Source decoded image array (H, W, C).
            detection: FaceDetection containing bounding box and 5-point landmarks.

        Returns:
            AlignedFace object containing normalized face crop and metadata.

        Raises:
            InvalidImageError: If source image is invalid or empty.
            InvalidLandmarksError: If detection landmarks are missing or degenerate.
            AlignerNotReadyError: If aligner backend is not ready.
            AlignmentRuntimeError: If geometric transformation fails.
        """
        pass

    def validate_inputs(
        self, image: Any, detection: Any
    ) -> tuple[np.ndarray, FacialLandmarks5Point]:
        """Validate input image and detection landmarks.

        Guarantees that:
        - Image is non-empty numpy ndarray.
        - Detection is valid FaceDetection.
        - Detection contains exactly 5 valid, finite, non-degenerate landmarks in canonical order:
          (left_eye, right_eye, nose, left_mouth, right_mouth).

        Args:
            image: Input image array.
            detection: Detection result.

        Returns:
            Tuple of (validated_image, validated_5point_landmarks).
        """
        if image is None:
            raise InvalidImageError("Input image cannot be None")

        if not isinstance(image, np.ndarray):
            raise InvalidImageError(
                f"Input image must be a numpy.ndarray, got {type(image).__name__}"
            )

        if image.size == 0 or image.ndim < 2:
            raise InvalidImageError("Input image is empty or has invalid dimension")

        if not isinstance(detection, FaceDetection):
            raise InvalidLandmarksError(
                f"Expected FaceDetection instance, got {type(detection).__name__}"
            )

        if detection.landmarks is None:
            raise InvalidLandmarksError(
                "FaceDetection does not contain facial landmarks required for alignment"
            )

        raw_landmarks = detection.landmarks
        if len(raw_landmarks) != 5:
            raise InvalidLandmarksError(
                f"Face alignment requires exactly 5 landmarks, got {len(raw_landmarks)}"
            )

        for i, pt in enumerate(raw_landmarks):
            if not isinstance(pt, LandmarkPoint):
                raise InvalidLandmarksError(
                    f"Landmark at index {i} is not a valid LandmarkPoint: {pt}"
                )
            if not (math.isfinite(pt.x) and math.isfinite(pt.y)):
                raise InvalidLandmarksError(
                    f"Landmark at index {i} contains non-finite coordinate: x={pt.x}, y={pt.y}"
                )

        # Check for collapsed / degenerate geometry (e.g. all points identical, zero distance)
        pts = np.array([[p.x, p.y] for p in raw_landmarks], dtype=np.float32)
        eye_distance = float(np.linalg.norm(pts[0] - pts[1]))
        if eye_distance < 1e-4:
            raise InvalidLandmarksError(
                "Degenerate facial geometry: inter-ocular eye distance is too small "
                f"({eye_distance})"
            )

        # Variance check across all points
        if float(np.var(pts)) < 1e-4:
            raise InvalidLandmarksError(
                "Degenerate facial geometry: landmark points have near-zero spatial variance"
            )

        landmarks_5pt = FacialLandmarks5Point.from_list(raw_landmarks)
        return image, landmarks_5pt
