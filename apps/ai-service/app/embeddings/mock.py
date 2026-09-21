"""Mock face embedder for testing and offline development (FACE-007)."""

import hashlib

import numpy as np

from app.alignment.schemas import AlignedFace
from app.embeddings.base import FaceEmbedder
from app.embeddings.schemas import FaceEmbeddingResult


class MockFaceEmbedder(FaceEmbedder):
    """Deterministic mock face embedder generating synthetic unit vectors."""

    def __init__(
        self,
        dimension: int = 512,
        model_code: str = "mock-arcface-v1",
        model_version: str = "1.0.0",
        custom_vector: list[float] | None = None,
        simulate_error: Exception | None = None,
        name: str = "mock_face_embedder",
    ):
        self._dimension = dimension
        self._model_code = model_code
        self._model_version = model_version
        self.custom_vector = custom_vector
        self.simulate_error = simulate_error
        self._name = name

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._model_version

    @property
    def embedding_dimension(self) -> int:
        return self._dimension

    def generate(self, aligned_face: AlignedFace | np.ndarray) -> FaceEmbeddingResult:
        """Validate input image and generate deterministic normalized embedding vector."""
        valid_img = self.extract_and_validate_image(aligned_face)

        if self.simulate_error is not None:
            raise self.simulate_error

        if self.custom_vector is not None:
            normalized_vec = self.normalize_vector(self.custom_vector)
            return FaceEmbeddingResult(
                vector=normalized_vec,
                dimension=self.embedding_dimension,
                normalized=True,
                model_type=self.model_type,
                model_code=self._model_code,
                model_version=self._model_version,
                provider=self.name,
            )

        # Generate deterministic pseudo-random vector from SHA-256 hash of image content
        img_bytes = valid_img.tobytes()
        seed_hash = hashlib.sha256(img_bytes).digest()
        seed_int = int.from_bytes(seed_hash[:8], byteorder="big")

        rng = np.random.default_rng(seed_int)
        raw_vec = rng.standard_normal(self.embedding_dimension, dtype=np.float32)

        normalized_vec = self.normalize_vector(raw_vec)

        return FaceEmbeddingResult(
            vector=normalized_vec,
            dimension=self.embedding_dimension,
            normalized=True,
            model_type=self.model_type,
            model_code=self._model_code,
            model_version=self._model_version,
            provider=self.name,
        )
