"""VideoSource factory for provider resolution (CAM-005/CAM-006)."""

from typing import Any

from app.streams.base import VideoSource
from app.streams.mock import MockVideoSource
from app.streams.rtsp import RTSPVideoSource


def get_video_source(provider: str | None = None, **kwargs: Any) -> VideoSource:
    """Factory function to resolve and instantiate a VideoSource provider.

    Args:
        provider: Provider name ('mock' or 'rtsp'). Defaults to 'mock' when
            omitted. 'rtsp' requires an `rtsp_url` keyword argument.
        **kwargs: Provider-specific configuration options.

    Returns:
        Configured VideoSource instance.

    Raises:
        ValueError: If an unsupported provider is requested, or 'rtsp' is
            requested without `rtsp_url`.
    """
    resolved_provider = (provider or "mock").strip().lower()

    if resolved_provider in ("mock", "mock_source"):
        return MockVideoSource(**kwargs)

    if resolved_provider in ("rtsp", "rtsp_source"):
        if "rtsp_url" not in kwargs:
            raise ValueError("The 'rtsp' provider requires an 'rtsp_url' argument.")
        return RTSPVideoSource(**kwargs)

    raise ValueError(
        f"Unsupported video source provider: '{resolved_provider}'. "
        "Supported providers are: 'mock', 'rtsp'."
    )
