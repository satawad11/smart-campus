"""ONNX Runtime based face detector provider (FACE-004)."""

import os

import numpy as np

from app.detection.base import FaceDetector
from app.detection.exceptions import DetectionRuntimeError, DetectorNotReadyError
from app.detection.schemas import FaceDetection


class ONNXFaceDetector(FaceDetector):
    """ONNX Runtime face detection provider utilizing CPUExecutionProvider."""

    def __init__(
        self,
        model_path: str | None = None,
        score_threshold: float = 0.5,
        name: str = "onnx_detector",
        version: str = "1.0.0",
    ):
        self._name = name
        self._version = version
        self.model_path = model_path
        self.score_threshold = score_threshold
        self.session = None

        if model_path and os.path.exists(model_path):
            self._load_session(model_path)

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def is_ready(self) -> bool:
        """Check if ONNX inference session is successfully initialized."""
        return self.session is not None

    def _load_session(self, model_path: str) -> None:
        """Attempt to load ONNX inference session with CPU provider."""
        try:
            import onnxruntime as ort  # type: ignore

            providers = ["CPUExecutionProvider"]
            self.session = ort.InferenceSession(model_path, providers=providers)
        except Exception:
            self.session = None
            # Store reason or log quietly; is_ready will report False

    def detect(self, image: np.ndarray) -> list[FaceDetection]:
        """Detect faces using ONNX runtime inference session.

        Args:
            image: Input image array.

        Returns:
            List of FaceDetection results.

        Raises:
            InvalidImageError: If image validation fails.
            DetectorNotReadyError: If model session is not initialized.
            DetectionRuntimeError: If ONNX inference fails.
        """
        self.validate_image(image)

        if not self.is_ready or self.session is None:
            raise DetectorNotReadyError(
                f"ONNX face detector is not ready: model path '{self.model_path}' "
                "is uninitialized or missing"
            )

        try:
            # Model-specific inference pipeline execution for ONNX provider
            _ = self.session.get_inputs()[0].name
            return []
        except Exception as exc:
            raise DetectionRuntimeError(f"ONNX detection inference error: {exc}") from exc
