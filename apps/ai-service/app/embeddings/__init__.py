"""Biometric embedding generation module exports (FACE-007)."""

from app.embeddings.base import FaceEmbedder
from app.embeddings.exceptions import (
    EmbedderNotReadyError,
    EmbeddingDimensionMismatchError,
    EmbeddingError,
    EmbeddingRuntimeError,
    InvalidEmbeddingInputError,
    InvalidEmbeddingVectorError,
)
from app.embeddings.factory import get_face_embedder
from app.embeddings.mock import MockFaceEmbedder
from app.embeddings.onnx_embedder import ONNXFaceEmbedder
from app.embeddings.schemas import FaceEmbeddingResult

__all__ = [
    "FaceEmbedder",
    "MockFaceEmbedder",
    "ONNXFaceEmbedder",
    "get_face_embedder",
    "FaceEmbeddingResult",
    "EmbeddingError",
    "EmbedderNotReadyError",
    "InvalidEmbeddingInputError",
    "EmbeddingRuntimeError",
    "EmbeddingDimensionMismatchError",
    "InvalidEmbeddingVectorError",
]
