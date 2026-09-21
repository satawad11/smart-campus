"""Face quality evaluator factory for provider resolution (FACE-006)."""

from typing import Any

from app.core.config import settings
from app.quality.base import FaceQualityEvaluator
from app.quality.mock import MockFaceQualityEvaluator
from app.quality.standard import StandardFaceQualityEvaluator


def get_face_quality_evaluator(provider: str | None = None, **kwargs: Any) -> FaceQualityEvaluator:
    """Factory function to resolve and instantiate a FaceQualityEvaluator provider.

    Args:
        provider: Provider name ('standard', 'mock'). If None, defaults based on `settings.ai_mode`.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured FaceQualityEvaluator instance.

    Raises:
        ValueError: If an unsupported provider is requested.
    """
    resolved_provider = (provider or settings.ai_mode).strip().lower()

    if resolved_provider in ("mock", "mock_quality", "mock_quality_evaluator"):
        return MockFaceQualityEvaluator(**kwargs)

    if resolved_provider in (
        "standard",
        "standard_quality",
        "standard_quality_evaluator",
        "onnx",
    ):
        return StandardFaceQualityEvaluator(**kwargs)

    raise ValueError(
        f"Unsupported face quality evaluator provider: '{resolved_provider}'. "
        "Supported providers are: 'standard', 'mock'."
    )
