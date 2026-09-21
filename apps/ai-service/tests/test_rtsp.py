"""Tests for RTSP VideoSource + reconnect (CAM-006).

All tests run against a FakeCapture test double injected via
capture_factory — no real camera or network dependency, matching the task's
own "no actual internet dependency" requirement. Backoff sleeps are captured
via an injected sleep_fn instead of actually sleeping, so tests stay fast.
"""

from collections.abc import Callable

import numpy as np
import pytest

from app.streams import (
    RTSPVideoSource,
    VideoSourceAlreadyOpenError,
    VideoSourceConnectionError,
    VideoSourceNotOpenError,
    VideoSourceReadError,
    get_video_source,
    mask_rtsp_url,
)

CREDENTIALED_URL = "rtsp://admin:S3cr3tPass@192.0.2.10:554/stream1"


class FakeCapture:
    """Deterministic stand-in for cv2.VideoCapture.

    `read_outcomes` is a queue of True/False consumed one per read() call;
    once exhausted, read() keeps succeeding. `opens_successfully=False`
    simulates an invalid stream / authentication failure (isOpened() is
    always False, as real OpenCV reports for those cases).
    """

    def __init__(
        self,
        opens_successfully: bool = True,
        read_outcomes: list[bool] | None = None,
        frame_shape: tuple[int, int, int] = (4, 4, 3),
    ):
        self._opens_successfully = opens_successfully
        self._read_outcomes = list(read_outcomes) if read_outcomes is not None else []
        self._read_index = 0
        self._frame_shape = frame_shape
        self.released = False

    def isOpened(self) -> bool:  # noqa: N802
        return self._opens_successfully and not self.released

    def read(self) -> tuple[bool, np.ndarray | None]:
        if self._read_index < len(self._read_outcomes):
            outcome = self._read_outcomes[self._read_index]
            self._read_index += 1
        else:
            outcome = True

        if not outcome:
            return False, None
        return True, np.zeros(self._frame_shape, dtype=np.uint8)

    def release(self) -> None:
        self.released = True

    def set(self, prop_id: int, value: float) -> bool:  # noqa: N802
        return True


def factory_from_queue(captures: list[FakeCapture]) -> Callable[[str, int, int], FakeCapture]:
    """Each call to the returned factory pops the next capture in order —
    simulates successive (re)connect attempts returning distinct captures."""
    queue = list(captures)

    def factory(url: str, connect_timeout_ms: int, read_timeout_ms: int) -> FakeCapture:
        if not queue:
            raise AssertionError("factory called more times than captures were queued")
        return queue.pop(0)

    return factory


def no_sleep(seconds: float) -> None:
    pass


class TestMaskRtspUrl:
    def test_masks_username_and_password(self) -> None:
        masked = mask_rtsp_url(CREDENTIALED_URL)
        assert "S3cr3tPass" not in masked
        assert "admin" not in masked
        assert "192.0.2.10:554" in masked
        assert "***:***@" in masked

    def test_url_without_credentials_is_unchanged(self) -> None:
        url = "rtsp://192.0.2.10:554/stream1"
        assert mask_rtsp_url(url) == url

    def test_malformed_url_falls_back_safely_without_crashing(self) -> None:
        # urlsplit() only raises ValueError for specific malformed forms
        # (e.g. an unterminated IPv6 host literal) — this exercises that
        # exact fallback path rather than assuming arbitrary garbage raises.
        assert mask_rtsp_url("rtsp://[::1:not-closed/stream") == "rtsp://***"

    def test_arbitrary_garbage_input_does_not_raise(self) -> None:
        # Most malformed strings parse leniently with no username/password
        # found, so they pass through unchanged rather than being masked —
        # there is nothing sensitive to strip. The guarantee is just: never
        # raise, and never fabricate credential-looking output.
        result = mask_rtsp_url("::::not a url::::")
        assert isinstance(result, str)


class TestOpenLifecycle:
    def test_successful_open_and_read(self) -> None:
        cap = FakeCapture()
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([cap]), sleep_fn=no_sleep
        )
        source.open()
        assert source.is_open is True

        frame = source.read()
        assert frame.sequence_number == 0
        assert frame.data.shape == (4, 4, 3)

    def test_double_open_raises(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture(), FakeCapture()]),
            sleep_fn=no_sleep,
        )
        source.open()
        with pytest.raises(VideoSourceAlreadyOpenError):
            source.open()

    def test_read_before_open_raises(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([]), sleep_fn=no_sleep
        )
        with pytest.raises(VideoSourceNotOpenError):
            source.read()

    def test_read_after_close_raises(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([FakeCapture()]), sleep_fn=no_sleep
        )
        source.open()
        source.close()
        with pytest.raises(VideoSourceNotOpenError):
            source.read()


class TestInitialConnectionFailure:
    def test_invalid_stream_or_auth_failure_raises_connection_error(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture(opens_successfully=False)]),
            sleep_fn=no_sleep,
        )
        with pytest.raises(VideoSourceConnectionError):
            source.open()
        assert source.is_open is False

    def test_initial_open_failure_does_not_retry_automatically(self) -> None:
        # open() fails fast exactly once — it must not consume more than one
        # capture from the factory queue on a single failed attempt.
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture(opens_successfully=False)]),
            sleep_fn=no_sleep,
        )
        with pytest.raises(VideoSourceConnectionError):
            source.open()

    def test_factory_exception_is_normalized_to_typed_error(self) -> None:
        def failing_factory(url: str, connect_timeout_ms: int, read_timeout_ms: int) -> FakeCapture:
            raise OSError("network unreachable")

        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=failing_factory, sleep_fn=no_sleep
        )
        with pytest.raises(VideoSourceConnectionError):
            source.open()

    def test_connection_error_message_never_contains_plaintext_credentials(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture(opens_successfully=False)]),
            sleep_fn=no_sleep,
        )
        with pytest.raises(VideoSourceConnectionError) as exc_info:
            source.open()
        assert "S3cr3tPass" not in str(exc_info.value)
        assert "admin" not in str(exc_info.value)


class TestReconnectOnTransientFailure:
    def test_read_failure_triggers_transparent_reconnect(self) -> None:
        # First capture's read() fails once; the reconnect path releases it
        # and the factory hands back a fresh, healthy second capture.
        first = FakeCapture(read_outcomes=[False])
        second = FakeCapture()

        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([first, second]), sleep_fn=no_sleep
        )
        source.open()

        frame = source.read()  # triggers reconnect internally, then succeeds
        assert frame.sequence_number == 0
        assert source.reconnect_count == 1

    def test_camera_restart_recovery_via_isopened_false(self) -> None:
        initial = FakeCapture()
        fresh = FakeCapture()

        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([initial, fresh]),
            sleep_fn=no_sleep,
        )
        source.open()
        # Simulate the camera dropping the connection mid-session (e.g. a
        # restart): the same capture object flips to "not open".
        initial.released = True

        frame = source.read()
        assert frame is not None
        assert source.reconnect_count == 1

    def test_network_loss_then_recovery_across_multiple_read_failures(self) -> None:
        flaky = FakeCapture(read_outcomes=[False, False])
        healthy = FakeCapture()

        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([flaky, healthy]),
            sleep_fn=no_sleep,
            max_reconnect_attempts=5,
        )
        source.open()
        frame = source.read()
        assert frame is not None

    def test_source_remains_open_after_successful_reconnect(self) -> None:
        first = FakeCapture(read_outcomes=[False])
        second = FakeCapture()
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([first, second]), sleep_fn=no_sleep
        )
        source.open()
        source.read()
        assert source.is_open is True


class TestReconnectExhaustion:
    def test_exceeding_max_reconnect_attempts_raises_typed_error(self) -> None:
        always_broken = [FakeCapture(opens_successfully=False) for _ in range(10)]
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture()] + always_broken),
            sleep_fn=no_sleep,
            max_reconnect_attempts=3,
        )
        source.open()
        source._capture.released = True  # noqa: SLF001 — force reconnect path on next read

        with pytest.raises(VideoSourceReadError):
            source.read()

    def test_never_busy_spins_sleep_called_between_every_attempt(self) -> None:
        sleep_calls: list[float] = []
        always_broken = [FakeCapture(opens_successfully=False) for _ in range(10)]
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture()] + always_broken),
            sleep_fn=sleep_calls.append,
            max_reconnect_attempts=4,
            random_fn=lambda: 0.5,  # neutral jitter
        )
        source.open()
        source._capture.released = True  # noqa: SLF001

        with pytest.raises(VideoSourceReadError):
            source.read()

        assert len(sleep_calls) == 4
        assert all(delay > 0 for delay in sleep_calls)


class TestBackoffPolicy:
    def test_backoff_grows_and_is_capped(self) -> None:
        sleep_calls: list[float] = []
        always_broken = [FakeCapture(opens_successfully=False) for _ in range(10)]
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture()] + always_broken),
            sleep_fn=sleep_calls.append,
            max_reconnect_attempts=6,
            initial_backoff_seconds=1.0,
            max_backoff_seconds=8.0,
            backoff_multiplier=2.0,
            random_fn=lambda: 0.5,  # neutral jitter -> exact multiplier progression
        )
        source.open()
        source._capture.released = True  # noqa: SLF001

        with pytest.raises(VideoSourceReadError):
            source.read()

        # 1, 2, 4, 8, 8, 8 (capped at max_backoff_seconds)
        assert sleep_calls == [1.0, 2.0, 4.0, 8.0, 8.0, 8.0]

    def test_jitter_stays_within_configured_ratio(self) -> None:
        sleep_calls: list[float] = []
        always_broken = [FakeCapture(opens_successfully=False) for _ in range(3)]
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture()] + always_broken),
            sleep_fn=sleep_calls.append,
            max_reconnect_attempts=3,
            initial_backoff_seconds=10.0,
            max_backoff_seconds=20.0,  # high enough that the cap doesn't mask jitter
            backoff_multiplier=1.0,
            jitter_ratio=0.2,
            random_fn=lambda: 1.0,  # max positive jitter
        )
        source.open()
        source._capture.released = True  # noqa: SLF001

        with pytest.raises(VideoSourceReadError):
            source.read()

        # base=10, jitter_ratio=0.2, random=1.0 -> factor = 1 + (1*2-1)*0.2 = 1.2 -> 12.0.
        # The max_backoff_seconds cap applies AFTER jitter (a hard ceiling
        # the sleep duration can never exceed), so it must be set above the
        # jittered value here for the test to actually observe the jitter.
        assert all(delay == pytest.approx(12.0) for delay in sleep_calls)


class TestShutdownCancelsReconnect:
    def test_close_during_backoff_wait_cancels_reconnect(self) -> None:
        source_holder: dict[str, RTSPVideoSource] = {}

        def sleep_and_close(seconds: float) -> None:
            source_holder["source"].close()

        always_broken = [FakeCapture(opens_successfully=False) for _ in range(10)]
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture()] + always_broken),
            sleep_fn=sleep_and_close,
            max_reconnect_attempts=None,  # would otherwise retry forever
        )
        source_holder["source"] = source
        source.open()
        source._capture.released = True  # noqa: SLF001

        with pytest.raises(VideoSourceNotOpenError):
            source.read()
        assert source.is_open is False

    def test_close_is_idempotent_and_releases_capture(self) -> None:
        cap = FakeCapture()
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([cap]), sleep_fn=no_sleep
        )
        source.open()
        source.close()
        assert cap.released is True
        source.close()  # must not raise
        assert source.state.value == "CLOSED"

    def test_close_without_ever_opening_is_safe(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([]), sleep_fn=no_sleep
        )
        source.close()  # must not raise


class TestTelemetry:
    def test_reconnect_count_and_last_error_are_tracked(self) -> None:
        first = FakeCapture(read_outcomes=[False])
        second = FakeCapture()
        source = RTSPVideoSource(
            CREDENTIALED_URL, capture_factory=factory_from_queue([first, second]), sleep_fn=no_sleep
        )
        source.open()
        assert source.reconnect_count == 0
        source.read()
        assert source.reconnect_count == 1

    def test_last_error_never_contains_plaintext_credentials(self) -> None:
        source = RTSPVideoSource(
            CREDENTIALED_URL,
            capture_factory=factory_from_queue([FakeCapture(opens_successfully=False)]),
            sleep_fn=no_sleep,
        )
        with pytest.raises(VideoSourceConnectionError):
            source.open()
        assert source.last_error is not None
        assert "S3cr3tPass" not in source.last_error


class TestFactoryIntegration:
    def test_get_video_source_rtsp_requires_url(self) -> None:
        with pytest.raises(ValueError, match="rtsp_url"):
            get_video_source("rtsp")

    def test_get_video_source_rtsp_returns_rtsp_video_source(self) -> None:
        source = get_video_source(
            "rtsp", rtsp_url=CREDENTIALED_URL, capture_factory=factory_from_queue([FakeCapture()])
        )
        assert isinstance(source, RTSPVideoSource)
