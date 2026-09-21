"""Deterministic mock VideoSource for tests and offline development (CAM-005)."""

import numpy as np

from app.streams.base import VideoSource
from app.streams.exceptions import (
    VideoSourceAlreadyOpenError,
    VideoSourceConnectionError,
    VideoSourceEOFError,
    VideoSourceNotOpenError,
    VideoSourceReadError,
)
from app.streams.schemas import Frame, SourceState


class MockVideoSource(VideoSource):
    """Deterministic, dependency-free video source.

    Produces `num_frames` synthetic frames (or runs indefinitely if
    `num_frames` is None, simulating a live camera) and then raises
    VideoSourceEOFError. Frame content is deterministic (seeded by sequence
    number) so tests can assert on exact array integrity.
    """

    def __init__(
        self,
        num_frames: int | None = 10,
        frame_width: int = 64,
        frame_height: int = 48,
        channels: int = 3,
        name: str = "mock_source",
        fail_to_open: bool = False,
        simulate_read_error_at: int | None = None,
    ):
        self._num_frames = num_frames
        self._frame_width = frame_width
        self._frame_height = frame_height
        self._channels = channels
        self._name = name
        self._fail_to_open = fail_to_open
        self._simulate_read_error_at = simulate_read_error_at

        self._state = SourceState.CLOSED
        self._next_sequence = 0

    @property
    def name(self) -> str:
        return self._name

    @property
    def is_open(self) -> bool:
        return self._state == SourceState.OPEN

    @property
    def state(self) -> SourceState:
        return self._state

    def open(self) -> None:
        if self._state == SourceState.OPEN:
            raise VideoSourceAlreadyOpenError(f"Video source '{self._name}' is already open")

        if self._fail_to_open:
            self._state = SourceState.ERROR
            raise VideoSourceConnectionError(
                f"Mock source '{self._name}' configured to fail to open"
            )

        self._state = SourceState.OPEN
        self._next_sequence = 0

    def read(self) -> Frame:
        if self._state != SourceState.OPEN:
            raise VideoSourceNotOpenError(
                f"Video source '{self._name}' is not open (state={self._state.value})"
            )

        if self._num_frames is not None and self._next_sequence >= self._num_frames:
            raise VideoSourceEOFError(
                f"Mock source '{self._name}' exhausted after {self._num_frames} frames"
            )

        sequence = self._next_sequence

        if self._simulate_read_error_at is not None and sequence == self._simulate_read_error_at:
            self._next_sequence += 1
            raise VideoSourceReadError(f"Simulated read failure at frame {sequence}")

        # Deterministic content: every frame is filled with a value derived
        # from its own sequence number, so tests can assert exact integrity.
        fill_value = sequence % 256
        data = np.full(
            (self._frame_height, self._frame_width, self._channels),
            fill_value=fill_value,
            dtype=np.uint8,
        )

        self._next_sequence += 1

        return Frame(
            data=data,
            width=self._frame_width,
            height=self._frame_height,
            sequence_number=sequence,
            source_name=self._name,
        )

    def close(self) -> None:
        # Idempotent: safe from any state, never raises.
        self._state = SourceState.CLOSED
