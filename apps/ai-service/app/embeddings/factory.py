"""Face embedder factory for provider resolution (FACE-007)."""

from typing import Any

from app.core.config import settings
from app.embeddings.base import FaceEmbedder
from app.embeddings.mock import MockFaceEmbedder
from app.embeddings.sface import SFaceEmbedder


def get_face_embedder(provider: str | None = None, **kwargs: Any) -> FaceEmbedder:
    """Factory function to resolve and instantiate a FaceEmbedder provider.

    Args:
        provider: Provider name ('mock', 'onnx'). If None, defaults based on `settings.ai_mode`.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured FaceEmbedder instance.

    Raises:
        ValueError: If an unsupported provider is requested.
    """
    resolved_provider = (provider or settings.ai_mode).strip().lower()

    if resolved_provider in ("mock", "mock_embedder", "mock_face_embedder"):
        return MockFaceEmbedder(**kwargs)

    if resolved_provider in ("onnx", "onnx_embedder", "onnx_face_embedder", "sface"):
        kwargs.setdefault("model_path", settings.embedder_model_path)
        return SFaceEmbedder(**kwargs)

    raise ValueError(
        f"Unsupported face embedder provider: '{resolved_provider}'. "
        "Supported providers are: 'mock', 'onnx'."
    )
