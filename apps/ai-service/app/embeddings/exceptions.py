"""Biometric embedding generation domain exceptions (FACE-007)."""


class EmbeddingError(Exception):
    """Base exception for all face embedding domain errors."""

    pass


class EmbedderNotReadyError(EmbeddingError):
    """Raised when the face embedder provider or model artifact is uninitialized or missing."""

    pass


class InvalidEmbeddingInputError(EmbeddingError):
    """Raised when input image data is invalid, empty, or has an unsupported shape."""

    pass


class EmbeddingRuntimeError(EmbeddingError):
    """Raised when runtime embedding inference or tensor execution fails."""

    pass


class EmbeddingDimensionMismatchError(EmbeddingError):
    """Raised when generated embedding vector dimension differs from configured model dimension."""

    pass


class InvalidEmbeddingVectorError(EmbeddingError):
    """Raised when generated embedding vector is zero-norm, NaN, infinite, or degenerate."""

    pass
