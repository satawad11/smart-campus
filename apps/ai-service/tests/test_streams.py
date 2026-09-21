"""Tests for VideoSource abstraction (CAM-005)."""

import numpy as np
import pytest

from app.streams import (
    Frame,
    MockVideoSource,
    SourceState,
    VideoSourceAlreadyOpenError,
    VideoSourceConnectionError,
    VideoSourceEOFError,
    VideoSourceNotOpenError,
    VideoSourceReadError,
    get_video_source,
)


class TestLifecycle:
    """open/read/close lifecycle."""

    def test_starts_closed(self) -> None:
        source = MockVideoSource()
        assert source.state == SourceState.CLOSED
        assert source.is_open is False

    def test_open_transitions_to_open(self) -> None:
        source = MockVideoSource()
        source.open()
        assert source.state == SourceState.OPEN
        assert source.is_open is True

    def test_close_transitions_to_closed(self) -> None:
        source = MockVideoSource()
        source.open()
        source.close()
        assert source.state == SourceState.CLOSED
        assert source.is_open is False

    def test_close_is_idempotent_and_safe_when_never_opened(self) -> None:
        source = MockVideoSource()
        source.close()  # must not raise
        source.close()
        assert source.state == SourceState.CLOSED

    def test_close_is_idempotent_after_open(self) -> None:
        source = MockVideoSource()
        source.open()
        source.close()
        source.close()  # second close must not raise
        assert source.state == SourceState.CLOSED

    def test_reopen_after_close_succeeds(self) -> None:
        source = MockVideoSource(num_frames=2)
        source.open()
        source.read()
        source.close()

        source.open()
        frame = source.read()
        # Sequence numbering restarts on a fresh open (a new logical session).
        assert frame.sequence_number == 0


class TestInvalidState:
    """Invalid-state / typed-error behavior."""

    def test_double_open_raises(self) -> None:
        source = MockVideoSource()
        source.open()
        with pytest.raises(VideoSourceAlreadyOpenError):
            source.open()

    def test_read_before_open_raises(self) -> None:
        source = MockVideoSource()
        with pytest.raises(VideoSourceNotOpenError):
            source.read()

    def test_read_after_close_raises(self) -> None:
        source = MockVideoSource()
        source.open()
        source.close()
        with pytest.raises(VideoSourceNotOpenError):
            source.read()

    def test_open_failure_raises_typed_connection_error_and_sets_error_state(self) -> None:
        source = MockVideoSource(fail_to_open=True)
        with pytest.raises(VideoSourceConnectionError):
            source.open()
        assert source.state == SourceState.ERROR
        assert source.is_open is False


class TestEOFAndReadErrors:
    """EOF / read-error behavior."""

    def test_finite_source_raises_eof_after_num_frames(self) -> None:
        source = MockVideoSource(num_frames=3)
        source.open()
        source.read()
        source.read()
        source.read()
        with pytest.raises(VideoSourceEOFError):
            source.read()

    def test_eof_is_stable_on_repeated_reads(self) -> None:
        source = MockVideoSource(num_frames=1)
        source.open()
        source.read()
        with pytest.raises(VideoSourceEOFError):
            source.read()
        with pytest.raises(VideoSourceEOFError):
            source.read()

    def test_infinite_source_never_raises_eof(self) -> None:
        source = MockVideoSource(num_frames=None)
        source.open()
        for _ in range(50):
            source.read()  # must never raise EOF
        assert source.is_open is True

    def test_simulated_read_error_is_typed_and_distinct_from_eof(self) -> None:
        source = MockVideoSource(num_frames=10, simulate_read_error_at=2)
        source.open()
        source.read()
        source.read()
        with pytest.raises(VideoSourceReadError):
            source.read()
        # Source remains usable after a transient read error (distinct from EOF/close).
        assert source.is_open is True
        frame = source.read()
        assert frame.sequence_number == 3


class TestFrameIntegrity:
    """Source array/frame integrity."""

    def test_frame_shape_and_dtype_match_configuration(self) -> None:
        source = MockVideoSource(num_frames=1, frame_width=32, frame_height=24, channels=3)
        source.open()
        frame = source.read()

        assert isinstance(frame, Frame)
        assert frame.width == 32
        assert frame.height == 24
        assert isinstance(frame.data, np.ndarray)
        assert frame.data.shape == (24, 32, 3)
        assert frame.data.dtype == np.uint8

    def test_sequence_numbers_increment_deterministically(self) -> None:
        source = MockVideoSource(num_frames=5)
        source.open()
        sequence_numbers = [source.read().sequence_number for _ in range(5)]
        assert sequence_numbers == [0, 1, 2, 3, 4]

    def test_frame_content_is_deterministic(self) -> None:
        a = MockVideoSource(num_frames=1, frame_width=16, frame_height=16)
        b = MockVideoSource(num_frames=1, frame_width=16, frame_height=16)
        a.open()
        b.open()
        frame_a = a.read()
        frame_b = b.read()
        # Same sequence number -> identical deterministic content.
        assert np.array_equal(frame_a.data, frame_b.data)

    def test_source_name_is_attached_to_frame(self) -> None:
        source = MockVideoSource(num_frames=1, name="camera-42")
        source.open()
        frame = source.read()
        assert frame.source_name == "camera-42"


class TestContextManagerAndResourceSafety:
    """No resource leak: close() always runs, including on exceptions."""

    def test_context_manager_opens_and_closes(self) -> None:
        source = MockVideoSource(num_frames=1)
        with source as opened:
            assert opened is source
            assert source.is_open is True
        assert source.is_open is False

    def test_context_manager_closes_even_on_exception(self) -> None:
        source = MockVideoSource(num_frames=1)
        with pytest.raises(RuntimeError):
            with source:
                raise RuntimeError("boom")
        assert source.is_open is False

    def test_context_manager_closes_even_on_eof(self) -> None:
        source = MockVideoSource(num_frames=1)
        with pytest.raises(VideoSourceEOFError):
            with source:
                source.read()
                source.read()  # triggers EOF inside the block
        assert source.is_open is False


class TestFactory:
    """Provider resolution."""

    def test_default_provider_is_mock(self) -> None:
        source = get_video_source()
        assert isinstance(source, MockVideoSource)

    def test_explicit_mock_provider(self) -> None:
        source = get_video_source("mock", num_frames=7)
        assert isinstance(source, MockVideoSource)

    def test_rtsp_provider_raises_clear_not_implemented_error(self) -> None:
        with pytest.raises(ValueError, match="CAM-006"):
            get_video_source("rtsp")

    def test_unsupported_provider_raises(self) -> None:
        with pytest.raises(ValueError):
            get_video_source("totally-unknown-provider")
