"""SFace face embedder (ACR-AI-01).

OpenCV Zoo ``face_recognition_sface_2021dec.onnx`` (Apache-2.0), run through OpenCV's
``cv2.FaceRecognizerSF`` on the 112x112 face from the standard aligner (same ArcFace
5-point template as OpenCV's own ``alignCrop``). The aligned face is RGB; SFace takes BGR.

SFace produces 128 values. They are L2-normalised and zero-padded to the platform's
512-d storage (``face_profiles.embedding vector(512)``). Padding with zeros keeps the norm
and every cosine similarity exactly, so the backend's index and search are unchanged.
"""

import os

import cv2
import numpy as np

from app.alignment.schemas import AlignedFace
from app.embeddings.base import FaceEmbedder
from app.embeddings.exceptions import (
    EmbedderNotReadyError,
    EmbeddingRuntimeError,
    InvalidEmbeddingVectorError,
)
from app.embeddings.schemas import FaceEmbeddingResult

SFACE_NATIVE_DIMENSION = 128
STORAGE_DIMENSION = 512


def pad_embedding(vector: np.ndarray, dimension: int = STORAGE_DIMENSION) -> np.ndarray:
    """Zero-pad a 1-D embedding to ``dimension`` (cosine similarity is unchanged)."""
    flat = np.asarray(vector, dtype=np.float64).reshape(-1)
    if flat.size > dimension:
        raise InvalidEmbeddingVectorError(
            f"Embedding has {flat.size} values, more than the {dimension}-d storage"
        )
    padded = np.zeros(dimension, dtype=np.float64)
    padded[: flat.size] = flat
    return padded


class SFaceEmbedder(FaceEmbedder):
    """Production face embedder backed by SFace."""

    def __init__(
        self,
        model_path: str | None = None,
        model_code: str = "yunet-sface",
        model_version: str = "2021dec",
        name: str = "sface",
        version: str = "2021dec",
    ):
        self._name = name
        self._version = version
        self.model_path = model_path
        self.model_code = model_code
        self.model_version = model_version
        self._recognizer = None
        if model_path and os.path.isfile(model_path):
            try:
                self._recognizer = cv2.FaceRecognizerSF.create(model_path, "")
            except cv2.error:
                self._recognizer = None

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def embedding_dimension(self) -> int:
        return STORAGE_DIMENSION

    @property
    def is_ready(self) -> bool:
        return self._recognizer is not None

    def generate(self, aligned_face: AlignedFace | np.ndarray) -> FaceEmbeddingResult:
        image = self.extract_and_validate_image(aligned_face)
        if self._recognizer is None:
            raise EmbedderNotReadyError(
                f"SFace embedder is not ready: model '{self.model_path}' is missing or invalid"
            )

        # AlignedFace carries its format; a bare array follows the pipeline convention (RGB).
        image_format = "RGB" if isinstance(aligned_face, np.ndarray) else aligned_face.format
        try:
            face = np.ascontiguousarray(image)
            if face.dtype != np.uint8:
                face = np.clip(face, 0, 255).astype(np.uint8)
            if face.ndim == 2:
                face = cv2.cvtColor(face, cv2.COLOR_GRAY2BGR)
            elif face.shape[2] == 4:
                face = cv2.cvtColor(face, cv2.COLOR_RGBA2BGR)
            elif str(image_format).upper() != "BGR":
                face = cv2.cvtColor(face, cv2.COLOR_RGB2BGR)
            if face.shape[:2] != (112, 112):
                face = cv2.resize(face, (112, 112), interpolation=cv2.INTER_LINEAR)
            feature = np.asarray(self._recognizer.feature(face), dtype=np.float64).reshape(-1)
        except cv2.error as exc:
            raise EmbeddingRuntimeError(f"SFace inference error: {exc}") from exc

        if feature.size != SFACE_NATIVE_DIMENSION:
            raise InvalidEmbeddingVectorError(
                f"SFace returned {feature.size} values, expected {SFACE_NATIVE_DIMENSION}"
            )
        native = self.normalize_vector(feature.tolist(), expected_dimension=SFACE_NATIVE_DIMENSION)
        vector = pad_embedding(np.asarray(native)).tolist()

        return FaceEmbeddingResult(
            vector=vector,
            dimension=STORAGE_DIMENSION,
            normalized=True,
            model_code=self.model_code,
            model_version=self.model_version,
            provider=self.name,
        )
