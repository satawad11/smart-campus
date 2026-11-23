"""Face detector factory for provider resolution (FACE-004)."""

from typing import Any

from app.core.config import settings
from app.detection.base import FaceDetector
from app.detection.mock import MockFaceDetector
from app.detection.yunet import YuNetFaceDetector


def get_face_detector(provider: str | None = None, **kwargs: Any) -> FaceDetector:
    """Factory function to resolve and instantiate a FaceDetector provider.

    Args:
        provider: Provider name ('mock', 'onnx'). If None, defaults to `settings.ai_mode`.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured FaceDetector instance.

    Raises:
        ValueError: If an unsupported provider is requested.
    """
    resolved_provider = (provider or settings.ai_mode).strip().lower()

    if resolved_provider in ("mock", "mock_detector"):
        return MockFaceDetector(**kwargs)

    if resolved_provider in ("onnx", "onnx_detector", "yunet"):
        kwargs.setdefault("model_path", settings.detector_model_path)
        kwargs.setdefault("max_input_width", settings.detector_max_input_width)
        return YuNetFaceDetector(**kwargs)

    raise ValueError(
        f"Unsupported face detector provider: '{resolved_provider}'. "
        "Supported providers are: 'mock', 'onnx'."
    )
