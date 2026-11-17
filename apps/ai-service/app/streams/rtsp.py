"""RTSP-backed VideoSource with automatic reconnect (CAM-006).

Real frame acquisition uses OpenCV's FFMPEG-backed VideoCapture, imported
lazily (module load must never fail if opencv isn't installed in a given
deployment — same convention as app.alignment.standard's lazy `import cv2`
and app.core.health's onnxruntime presence check). All capture interaction
goes through the `CaptureLike` structural protocol and an injectable
`capture_factory`, so tests exercise the full reconnect/backoff state
machine deterministically against a fake, with no real network/camera
dependency.
"""

import os
import random
import time
from collections.abc import Callable
from typing import Any, Protocol
from urllib.parse import urlsplit, urlunsplit

from app.streams.base import VideoSource
from app.streams.exceptions import (
    VideoSourceAlreadyOpenError,
    VideoSourceConnectionError,
    VideoSourceNotOpenError,
    VideoSourceReadError,
)
from app.streams.schemas import Frame, SourceState


class CaptureLike(Protocol):
    """Structural protocol for whatever open_capture() returns — satisfied by
    both a real cv2.VideoCapture and a test double, without importing cv2
    for typing purposes."""

    def isOpened(self) -> bool: ...  # noqa: N802 (matches cv2's own naming)

    def read(self) -> tuple[bool, Any]: ...

    def release(self) -> None: ...

    def set(self, prop_id: int, value: float) -> bool: ...  # noqa: N802


def mask_rtsp_url(url: str) -> str:
    """Replaces embedded userinfo (user:pass@) with a fixed placeholder.

    Used in every log/exception message this class produces — the raw URL
    (which may embed RTSP credentials) must never be logged in full.
    """
    try:
        parts = urlsplit(url)
    except ValueError:
        return "rtsp://***"

    if parts.username is None and parts.password is None:
        return url

    host = parts.hostname or ""
    if parts.port:
        host = f"{host}:{parts.port}"
    masked_netloc = f"***:***@{host}"

    return urlunsplit((parts.scheme, masked_netloc, parts.path, parts.query, parts.fragment))


def _default_capture_factory(
    url: str, connect_timeout_ms: int, read_timeout_ms: int
) -> CaptureLike:
    # Keep the AI-node transport consistent with the backend's camera preview.
    # UDP packet loss can yield decodable-looking frames with corrupted/missing
    # image regions, which is particularly harmful to face detection. Operators
    # may still override this process-wide OpenCV option in the deployment env.
    os.environ.setdefault("OPENCV_FFMPEG_CAPTURE_OPTIONS", "rtsp_transport;tcp")
    try:
        import cv2  # noqa: PLC0415 (intentionally lazy — see module docstring)
    except ImportError as exc:
        raise VideoSourceConnectionError(
            "opencv-python is not installed; RTSP capture requires it"
        ) from exc

    capture = cv2.VideoCapture(url, cv2.CAP_FFMPEG)
    # Bounded timeouts (OpenCV >= 4.5, FFMPEG backend) — never block indefinitely.
    capture.set(cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, connect_timeout_ms)
    capture.set(cv2.CAP_PROP_READ_TIMEOUT_MSEC, read_timeout_ms)
    return capture


class RTSPVideoSource(VideoSource):
    """A live RTSP camera stream, with automatic bounded-backoff reconnect.

    open() attempts the initial connection exactly once and raises
    VideoSourceConnectionError on failure — the caller decides whether/when
    to retry a camera that was never reachable at all. Once open, read()
    transparently reconnects (bounded exponential backoff with jitter) on a
    transient failure (network loss, camera restart) — a live source should
    self-heal rather than bubble every blip up to the caller. close() is
    always safe and cancels any in-progress reconnect wait promptly.
    """

    def __init__(
        self,
        rtsp_url: str,
        name: str = "rtsp_source",
        connect_timeout_ms: int = 5000,
        read_timeout_ms: int = 5000,
        max_reconnect_attempts: int | None = None,
        initial_backoff_seconds: float = 1.0,
        max_backoff_seconds: float = 30.0,
        backoff_multiplier: float = 2.0,
        jitter_ratio: float = 0.1,
        capture_factory: Callable[[str, int, int], CaptureLike] | None = None,
        sleep_fn: Callable[[float], None] | None = None,
        random_fn: Callable[[], float] | None = None,
    ):
        self._rtsp_url = rtsp_url
        self._name = name
        self._connect_timeout_ms = connect_timeout_ms
        self._read_timeout_ms = read_timeout_ms
        self._max_reconnect_attempts = max_reconnect_attempts
        self._initial_backoff_seconds = initial_backoff_seconds
        self._max_backoff_seconds = max_backoff_seconds
        self._backoff_multiplier = backoff_multiplier
        self._jitter_ratio = jitter_ratio
        self._capture_factory = capture_factory or _default_capture_factory
        self._sleep_fn = sleep_fn or time.sleep
        self._random_fn = random_fn or random.random

        self._state = SourceState.CLOSED
        self._capture: CaptureLike | None = None
        self._next_sequence = 0
        self._reconnect_count = 0
        self._last_error: str | None = None
        self._shutdown_requested = False

    @property
    def name(self) -> str:
        return self._name

    @property
    def is_open(self) -> bool:
        return self._state == SourceState.OPEN

    @property
    def state(self) -> SourceState:
        return self._state

    @property
    def reconnect_count(self) -> int:
        """Number of successful reconnects since open() — telemetry for
        future health collection (CAM-013)."""
        return self._reconnect_count

    @property
    def last_error(self) -> str | None:
        """Sanitized (URL-masked) message of the most recent connection
        error, or None. Telemetry only — never raised again from here."""
        return self._last_error

    def open(self) -> None:
        if self._state == SourceState.OPEN:
            raise VideoSourceAlreadyOpenError(f"RTSP source '{self._name}' is already open")

        self._shutdown_requested = False
        self._capture = self._connect_once()
        self._state = SourceState.OPEN
        self._next_sequence = 0

    def read(self) -> Frame:
        if self._state != SourceState.OPEN:
            raise VideoSourceNotOpenError(
                f"RTSP source '{self._name}' is not open (state={self._state.value})"
            )

        if self._capture is None or not self._capture.isOpened():
            self._reconnect_with_backoff()

        assert self._capture is not None  # narrowed by the reconnect above
        ok, data = self._capture.read()

        if not ok or data is None:
            # One transparent reconnect-and-retry before surfacing a typed error.
            self._reconnect_with_backoff()
            assert self._capture is not None
            ok, data = self._capture.read()
            if not ok or data is None:
                masked = mask_rtsp_url(self._rtsp_url)
                raise VideoSourceReadError(
                    f"RTSP source '{self._name}' ({masked}) failed to read a frame"
                )

        # OpenCV decodes BGR; the perception pipeline (aligner, quality, models) is RGB.
        if getattr(data, "ndim", 0) == 3 and data.shape[2] == 3:
            data = data[:, :, ::-1].copy()

        sequence = self._next_sequence
        self._next_sequence += 1
        height, width = data.shape[0], data.shape[1]

        return Frame(
            data=data,
            width=width,
            height=height,
            sequence_number=sequence,
            source_name=self._name,
        )

    def close(self) -> None:
        # Signals any in-progress backoff wait to stop promptly.
        self._shutdown_requested = True
        if self._capture is not None:
            try:
                self._capture.release()
            except Exception:  # noqa: BLE001 — release() must never raise out of close().
                pass
            self._capture = None
        self._state = SourceState.CLOSED

    # ---- Connection / reconnect internals ----

    def _connect_once(self) -> CaptureLike:
        masked = mask_rtsp_url(self._rtsp_url)

        try:
            capture = self._capture_factory(
                self._rtsp_url, self._connect_timeout_ms, self._read_timeout_ms
            )
        except VideoSourceConnectionError:
            raise
        except Exception as exc:  # noqa: BLE001 — normalize any backend error to our typed exception.
            self._last_error = str(exc)
            raise VideoSourceConnectionError(
                f"Failed to open RTSP source '{self._name}' ({masked}): {exc}"
            ) from exc

        if not capture.isOpened():
            message = (
                f"RTSP source '{self._name}' ({masked}) did not open "
                "(invalid stream or authentication failure)"
            )
            self._last_error = message
            raise VideoSourceConnectionError(message)

        return capture

    def _reconnect_with_backoff(self) -> None:
        if self._capture is not None:
            try:
                self._capture.release()
            except Exception:  # noqa: BLE001
                pass
            self._capture = None

        backoff = self._initial_backoff_seconds
        attempt = 0

        while True:
            if self._shutdown_requested:
                raise VideoSourceNotOpenError(
                    f"RTSP source '{self._name}' was closed during reconnect"
                )

            if self._max_reconnect_attempts is not None and attempt >= self._max_reconnect_attempts:
                raise VideoSourceReadError(
                    f"RTSP source '{self._name}' ({mask_rtsp_url(self._rtsp_url)}) exceeded "
                    f"{self._max_reconnect_attempts} reconnect attempts"
                )

            attempt += 1

            try:
                self._capture = self._connect_once()
                self._reconnect_count += 1
                return
            except VideoSourceConnectionError as exc:
                self._last_error = str(exc)

            if self._shutdown_requested:
                raise VideoSourceNotOpenError(
                    f"RTSP source '{self._name}' was closed during reconnect"
                )

            jitter = 1.0 + (self._random_fn() * 2 - 1) * self._jitter_ratio
            self._sleep_fn(min(backoff * jitter, self._max_backoff_seconds))
            backoff = min(backoff * self._backoff_multiplier, self._max_backoff_seconds)
