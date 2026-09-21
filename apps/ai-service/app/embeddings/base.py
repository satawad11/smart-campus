"""Base face embedder interface and vector normalization (FACE-007)."""

from abc import ABC, abstractmethod
from typing import Any

import numpy as np

from app.alignment.schemas import AlignedFace
from app.detection.schemas import AIModelType
from app.embeddings.exceptions import (
    EmbeddingDimensionMismatchError,
    InvalidEmbeddingInputError,
    InvalidEmbeddingVectorError,
)
from app.embeddings.schemas import FaceEmbeddingResult


class FaceEmbedder(ABC):
    """Abstract interface for biometric face embedding providers."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Provider name identifier."""
        pass

    @property
    @abstractmethod
    def version(self) -> str:
        """Embedder model/implementation version string."""
        pass

    @property
    def model_type(self) -> AIModelType:
        """Canonical AI model registry type."""
        return AIModelType.FACE_EMBEDDING

    @property
    def embedding_dimension(self) -> int:
        """Target output vector dimension (e.g. 512)."""
        return 512

    @abstractmethod
    def generate(self, aligned_face: AlignedFace | np.ndarray) -> FaceEmbeddingResult:
        """Generate normalized biometric embedding vector from aligned face.

        Args:
            aligned_face: AlignedFace instance or numpy image array (H, W, C).

        Returns:
            FaceEmbeddingResult.

        Raises:
            InvalidEmbeddingInputError: If input image is None, empty, or invalid.
            EmbedderNotReadyError: If model session or weights are uninitialized.
            EmbeddingDimensionMismatchError: If output dimension differs from configured model.
            InvalidEmbeddingVectorError: If vector contains NaN, Inf, or zero-norm.
            EmbeddingRuntimeError: If runtime tensor inference fails.
        """
        pass

    def extract_and_validate_image(self, aligned_face: Any) -> np.ndarray:
        """Extract and validate input image array.

        Args:
            aligned_face: AlignedFace instance or numpy ndarray.

        Returns:
            Validated numpy ndarray.

        Raises:
            InvalidEmbeddingInputError: If validation fails.
        """
        if aligned_face is None:
            raise InvalidEmbeddingInputError("Input aligned_face cannot be None")

        if isinstance(aligned_face, AlignedFace):
            image = aligned_face.image
        elif isinstance(aligned_face, np.ndarray):
            image = aligned_face
        else:
            raise InvalidEmbeddingInputError(
                f"Input must be an AlignedFace or numpy.ndarray, got {type(aligned_face).__name__}"
            )

        if not isinstance(image, np.ndarray):
            raise InvalidEmbeddingInputError(
                f"Image must be a numpy.ndarray, got {type(image).__name__}"
            )

        if image.size == 0:
            raise InvalidEmbeddingInputError("Input image is empty (size 0)")

        if image.ndim not in (2, 3):
            raise InvalidEmbeddingInputError(
                f"Input image must have 2 or 3 dimensions, got {image.ndim}"
            )

        if image.shape[0] <= 0 or image.shape[1] <= 0:
            raise InvalidEmbeddingInputError("Input image has non-positive spatial dimensions")

        if np.issubdtype(image.dtype, np.floating) and not np.all(np.isfinite(image)):
            raise InvalidEmbeddingInputError(
                "Input floating-point image contains NaN or Inf values"
            )

        return image

    def normalize_vector(
        self, raw_vector: Any, expected_dimension: int | None = None
    ) -> list[float]:
        """Validate and L2-normalize an embedding vector.

        Args:
            raw_vector: 1D array-like or sequence of numeric values.
            expected_dimension: Expected length (defaults to self.embedding_dimension).

        Returns:
            Normalized list of Python floats with unit L2 norm.

        Raises:
            EmbeddingDimensionMismatchError: If length differs from expected.
            InvalidEmbeddingVectorError: If vector contains NaN, Inf, or is zero-norm.
        """
        target_dim = expected_dimension or self.embedding_dimension

        try:
            vec = np.asarray(raw_vector, dtype=np.float32).flatten()
        except Exception as exc:
            raise InvalidEmbeddingVectorError(
                f"Failed to convert embedding vector to float array: {exc}"
            ) from exc

        if len(vec) != target_dim:
            raise EmbeddingDimensionMismatchError(
                f"Generated embedding vector length {len(vec)} does not match "
                f"expected dimension {target_dim}"
            )

        if not np.all(np.isfinite(vec)):
            raise InvalidEmbeddingVectorError(
                "Embedding vector contains non-finite values (NaN or Inf)"
            )

        norm = float(np.linalg.norm(vec))
        if norm <= 1e-7:
            raise InvalidEmbeddingVectorError(
                "Cannot L2-normalize degenerate zero-norm embedding vector"
            )

        normalized_vec = vec / norm
        return [float(x) for x in normalized_vec]
