"""ONNX Runtime based face embedder provider (FACE-007)."""

import os
from typing import Any

import numpy as np

from app.alignment.schemas import AlignedFace
from app.embeddings.base import FaceEmbedder
from app.embeddings.exceptions import EmbedderNotReadyError, EmbeddingRuntimeError
from app.embeddings.schemas import FaceEmbeddingResult


class ONNXFaceEmbedder(FaceEmbedder):
    """ONNX Runtime biometric embedding provider utilizing CPUExecutionProvider."""

    def __init__(
        self,
        model_path: str | None = None,
        model_code: str = "arcface-r50-v1",
        model_version: str = "1.0.0",
        dimension: int = 512,
        name: str = "onnx_face_embedder",
    ):
        self._model_path = model_path
        self._model_code = model_code
        self._model_version = model_version
        self._dimension = dimension
        self._name = name
        self.session: Any = None

        if model_path and os.path.exists(model_path):
            self._load_session(model_path)

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._model_version

    @property
    def embedding_dimension(self) -> int:
        return self._dimension

    @property
    def is_ready(self) -> bool:
        """Check if ONNX inference session is initialized and ready."""
        return self.session is not None

    def _load_session(self, model_path: str) -> None:
        """Attempt to load ONNX inference session with CPU provider."""
        try:
            import onnxruntime as ort  # type: ignore

            providers = ["CPUExecutionProvider"]
            self.session = ort.InferenceSession(model_path, providers=providers)
        except Exception:
            self.session = None

    def generate(self, aligned_face: AlignedFace | np.ndarray) -> FaceEmbeddingResult:
        """Preprocess aligned face image, run ONNX model, and return normalized embedding."""
        valid_img = self.extract_and_validate_image(aligned_face)

        if not self.is_ready or self.session is None:
            raise EmbedderNotReadyError(
                f"ONNX face embedder is not ready: model path '{self._model_path}' "
                "is uninitialized or missing"
            )

        try:
            # Preprocessing: 3-channel RGB -> NCHW -> standard (pixel - 127.5) / 128.0
            if valid_img.ndim == 2:
                rgb_img = np.stack([valid_img] * 3, axis=-1)
            elif valid_img.ndim == 3 and valid_img.shape[2] == 1:
                rgb_img = np.concatenate([valid_img] * 3, axis=-1)
            elif valid_img.ndim == 3 and valid_img.shape[2] == 4:
                rgb_img = valid_img[:, :, :3]
            else:
                rgb_img = valid_img

            # NCHW transpose: (H, W, C) -> (1, C, H, W)
            blob = np.transpose(rgb_img, (2, 0, 1))[np.newaxis, :, :, :].astype(np.float32)
            blob = (blob - 127.5) / 128.0

            input_name = self.session.get_inputs()[0].name
            outputs = self.session.run(None, {input_name: blob})
            raw_vector = outputs[0][0]

            normalized_vec = self.normalize_vector(raw_vector)

            return FaceEmbeddingResult(
                vector=normalized_vec,
                dimension=self.embedding_dimension,
                normalized=True,
                model_type=self.model_type,
                model_code=self._model_code,
                model_version=self._model_version,
                provider=self.name,
            )
        except Exception as exc:
            raise EmbeddingRuntimeError(f"ONNX embedding inference failed: {exc}") from exc
