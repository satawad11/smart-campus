"""VideoSource factory for provider resolution (CAM-005)."""

from typing import Any

from app.streams.base import VideoSource
from app.streams.mock import MockVideoSource


def get_video_source(provider: str | None = None, **kwargs: Any) -> VideoSource:
    """Factory function to resolve and instantiate a VideoSource provider.

    Args:
        provider: Provider name ('mock'). Defaults to 'mock' when omitted.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured VideoSource instance.

    Raises:
        ValueError: If an unsupported provider is requested. 'rtsp' is a
            recognized future provider name (CAM-006) — requesting it now
            raises a clear "not yet implemented" message rather than
            silently falling back to mock.
    """
    resolved_provider = (provider or "mock").strip().lower()

    if resolved_provider in ("mock", "mock_source"):
        return MockVideoSource(**kwargs)

    if resolved_provider in ("rtsp", "rtsp_source"):
        raise ValueError(
            "The 'rtsp' VideoSource provider is not implemented yet (lands in CAM-006). "
            "Use 'mock' until then."
        )

    raise ValueError(
        f"Unsupported video source provider: '{resolved_provider}'. "
        "Supported providers are: 'mock'."
    )
