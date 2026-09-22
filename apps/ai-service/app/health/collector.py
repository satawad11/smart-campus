"""Rolling-window camera health collector (CAM-013).

Accumulates observations between collect() calls (frame arrivals, dropped
frames, and the source's own cumulative reconnect counter) and produces one
CameraHealthSample snapshot per call, then resets the window — the same
snapshot-then-reset telemetry shape as CAM-009/011's per-attempt results.
"""

from datetime import datetime

from app.health.frame_metrics import compute_frame_metrics
from app.health.schemas import CameraHealthSample, FrameMetrics
from app.streams.schemas import Frame


class CameraHealthCollector:
    def __init__(self, camera_uuid: str):
        self._camera_uuid = camera_uuid
        self._frame_timestamps: list[datetime] = []
        self._dropped_frames = 0
        self._resolution: str | None = None
        self._latest_metrics: FrameMetrics | None = None
        self._latest_latency_ms: int | None = None
        self._last_reconnect_count = 0

    def record_frame(self, frame: Frame, latency_ms: float | None = None) -> None:
        self._frame_timestamps.append(frame.captured_at)
        self._resolution = f"{frame.width}x{frame.height}"
        self._latest_metrics = compute_frame_metrics(frame.data)
        if latency_ms is not None:
            self._latest_latency_ms = int(latency_ms)

    def record_dropped_frames(self, count: int = 1) -> None:
        self._dropped_frames += count

    def collect(self, source_reconnect_count: int = 0) -> CameraHealthSample:
        """source_reconnect_count is the VideoSource's own cumulative
        counter (e.g. RTSPVideoSource.reconnect_count) — only the delta
        since the previous collect() is reported, so a reconnect from long
        ago does not keep the camera flagged DEGRADED forever."""
        reconnects_this_window = max(0, source_reconnect_count - self._last_reconnect_count)
        self._last_reconnect_count = source_reconnect_count

        brightness_score = self._latest_metrics.brightness_score if self._latest_metrics else None
        blur_score = self._latest_metrics.blur_score if self._latest_metrics else None

        sample = CameraHealthSample(
            camera_uuid=self._camera_uuid,
            fps=self._compute_fps(),
            latency_ms=self._latest_latency_ms,
            dropped_frames=self._dropped_frames,
            resolution=self._resolution,
            brightness_score=brightness_score,
            blur_score=blur_score,
            reconnect_count=reconnects_this_window,
        )
        self._reset_window()
        return sample

    def _compute_fps(self) -> float | None:
        if len(self._frame_timestamps) < 2:
            return None
        span = (self._frame_timestamps[-1] - self._frame_timestamps[0]).total_seconds()
        if span <= 0:
            return None
        return (len(self._frame_timestamps) - 1) / span

    def _reset_window(self) -> None:
        self._frame_timestamps = []
        self._dropped_frames = 0
