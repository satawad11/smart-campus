"""Face aligner factory for provider resolution (FACE-005)."""

from typing import Any

from app.alignment.base import FaceAligner
from app.alignment.mock import MockFaceAligner
from app.alignment.standard import StandardFaceAligner
from app.core.config import settings


def get_face_aligner(provider: str | None = None, **kwargs: Any) -> FaceAligner:
    """Factory function to resolve and instantiate a FaceAligner provider.

    Args:
        provider: Provider name ('standard', 'standard_5pt', 'mock').
                  If None, defaults based on `settings.ai_mode`.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured FaceAligner instance.

    Raises:
        ValueError: If an unsupported provider is requested.
    """
    resolved_provider = (provider or settings.ai_mode).strip().lower()

    if resolved_provider in ("mock", "mock_aligner", "mock_face_aligner"):
        return MockFaceAligner(**kwargs)

    if resolved_provider in ("standard", "standard_5pt", "standard_5pt_aligner", "onnx"):
        return StandardFaceAligner(**kwargs)

    raise ValueError(
        f"Unsupported face aligner provider: '{resolved_provider}'. "
        "Supported providers are: 'standard', 'mock'."
    )
