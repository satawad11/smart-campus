"""VideoSource module exports (CAM-005/CAM-006)."""

from app.streams.base import VideoSource
from app.streams.exceptions import (
    VideoSourceAlreadyOpenError,
    VideoSourceConnectionError,
    VideoSourceEOFError,
    VideoSourceError,
    VideoSourceNotOpenError,
    VideoSourceReadError,
)
from app.streams.factory import get_video_source
from app.streams.mock import MockVideoSource
from app.streams.rtsp import RTSPVideoSource, mask_rtsp_url
from app.streams.schemas import Frame, SourceState

__all__ = [
    "VideoSource",
    "MockVideoSource",
    "RTSPVideoSource",
    "mask_rtsp_url",
    "get_video_source",
    "Frame",
    "SourceState",
    "VideoSourceError",
    "VideoSourceAlreadyOpenError",
    "VideoSourceNotOpenError",
    "VideoSourceConnectionError",
    "VideoSourceReadError",
    "VideoSourceEOFError",
]
