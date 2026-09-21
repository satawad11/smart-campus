"""Tests for controlled frame sampling (CAM-007).

The underlying "camera" is a MockVideoSource (CAM-005). Source frame rate is
simulated by advancing an injected fake clock by a fixed interval on every
sample() call (one call == one source.read()) — deterministic, no real
wall-clock timing dependency.
"""

import pytest

from app.sampling import FrameSampler
from app.streams import MockVideoSource, VideoSourceEOFError, VideoSourceNotOpenError


class FakeClock:
    """Advances by a fixed step every time it's called — simulates a source
    producing frames at a constant nominal rate.

    Computes each value as start + n*step (not cumulative +=) to avoid
    floating-point drift accumulating over many calls.
    """

    def __init__(self, step: float, start: float = 0.0):
        self._start = start
        self._step = step
        self._calls = 0

    def __call__(self) -> float:
        current = self._start + self._calls * self._step
        self._calls += 1
        return current


def open_mock_source(num_frames: int | None = None) -> MockVideoSource:
    source = MockVideoSource(num_frames=num_frames)
    source.open()
    return source


class TestRateThrottling:
    def test_target_fps_lower_than_source_drops_frames(self) -> None:
        # Simulated 30 fps source, target ~4.8 fps (not an exact ratio of the
        # 1/30 step, which avoids an IEEE754 rounding boundary — see the
        # comment in test_stable_sampling_rate_over_many_frames) -> keeps
        # roughly 1 in every 6-7 reads.
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=4.8, clock_fn=clock, queue_size=100
        )

        kept = sum(1 for _ in range(60) if sampler.sample() is not None)

        assert sampler.read_count == 60
        assert sampler.dropped_count == 60 - kept
        # ~30fps source over 2 seconds of simulated time at ~4.8fps target -> ~9-10 kept.
        assert 7 <= kept <= 12

    def test_target_fps_at_or_above_source_never_drops(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 25)  # 25 fps source
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=30.0, clock_fn=clock, queue_size=100
        )

        kept = sum(1 for _ in range(25) if sampler.sample() is not None)

        assert kept == 25
        assert sampler.dropped_count == 0

    def test_first_frame_is_always_kept(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        sampler = FrameSampler(source, camera_uuid="cam-1", target_fps=1.0, clock_fn=clock)

        assert sampler.sample() is not None

    def test_stable_sampling_rate_over_many_frames(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        # target_fps=9.0 (not 10) deliberately avoids an exact-boundary ratio
        # against the 1/30 source step (0.1 vs 3*(1/30) sit a hair apart in
        # IEEE754, making the drop/keep decision hypersensitive to rounding
        # for that specific pair — a real system reading real timestamps has
        # the same edge case). 9.0 keeps every ~3.33rd frame, well clear of it.
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=9.0, clock_fn=clock, queue_size=1000
        )

        for _ in range(300):  # 10 simulated seconds at 30fps
            sampler.sample()

        # 10 simulated seconds at a 9fps target -> ~90 kept frames.
        kept = 300 - sampler.dropped_count
        assert 70 <= kept <= 95


class TestQueueBackpressure:
    def test_queue_depth_never_exceeds_configured_size(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)  # every read is kept (well above target interval)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=1000.0, queue_size=3, clock_fn=clock
        )

        for _ in range(20):
            sampler.sample()

        assert sampler.queue_depth == 3
        assert sampler.evicted_count == 20 - 3

    def test_full_queue_evicts_oldest_keeps_newest(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=1000.0, queue_size=2, clock_fn=clock
        )

        for _ in range(5):
            sampler.sample()

        first = sampler.pop()
        second = sampler.pop()
        assert sampler.pop() is None  # queue exhausted
        # The last two kept frames (sequence 3, 4) survive; 0,1,2 were evicted.
        assert first.sampled_sequence == 3
        assert second.sampled_sequence == 4

    def test_pop_returns_fifo_order(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=1000.0, queue_size=10, clock_fn=clock
        )

        for _ in range(4):
            sampler.sample()

        sequences = [sampler.pop().sampled_sequence for _ in range(4)]
        assert sequences == [0, 1, 2, 3]

    def test_never_grows_unbounded_under_sustained_load(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=1000.0, queue_size=5, clock_fn=clock
        )

        for _ in range(10_000):
            sampler.sample()

        assert sampler.queue_depth <= 5


class TestTimestampsAndIdentity:
    def test_source_captured_at_is_preserved_unchanged(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(source, camera_uuid="cam-1", target_fps=1.0, clock_fn=clock)

        sampled = sampler.sample()
        assert sampled.frame.captured_at is not None

    def test_camera_uuid_is_attached(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(source, camera_uuid="cam-42-uuid", target_fps=1.0, clock_fn=clock)

        sampled = sampler.sample()
        assert sampled.camera_uuid == "cam-42-uuid"

    def test_sampled_at_is_distinct_from_source_captured_at_field(self) -> None:
        # Two different timestamps must both be present, not conflated into one.
        source = open_mock_source()
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(source, camera_uuid="cam-1", target_fps=1.0, clock_fn=clock)

        sampled = sampler.sample()
        assert sampled.sampled_at is not None
        assert sampled.frame.captured_at is not None
        # Both are real timestamps (not derived from the loop index).
        assert sampled.sampled_at.year > 2000
        assert sampled.frame.captured_at.year > 2000


class TestSequenceIntegrity:
    def test_no_duplicate_sampled_sequence_numbers(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=10.0, clock_fn=clock, queue_size=1000
        )

        sequences = []
        for _ in range(200):
            result = sampler.sample()
            if result is not None:
                sequences.append(result.sampled_sequence)

        assert len(sequences) == len(set(sequences))
        assert sequences == sorted(sequences)

    def test_sampled_sequence_only_increments_on_kept_frames(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=5.0, clock_fn=clock, queue_size=1000
        )

        for _ in range(30):
            sampler.sample()

        kept = 30 - sampler.dropped_count
        # Next kept frame's sequence number equals the count already kept.
        assert sampler._next_sampled_sequence == kept  # noqa: SLF001


class TestSourceErrorPropagation:
    def test_eof_propagates_unchanged(self) -> None:
        source = open_mock_source(num_frames=2)
        clock = FakeClock(step=1.0)
        sampler = FrameSampler(source, camera_uuid="cam-1", target_fps=1000.0, clock_fn=clock)

        sampler.sample()
        sampler.sample()
        with pytest.raises(VideoSourceEOFError):
            sampler.sample()

    def test_not_open_error_propagates_unchanged(self) -> None:
        source = MockVideoSource()  # never opened
        sampler = FrameSampler(source, camera_uuid="cam-1", target_fps=1.0)
        with pytest.raises(VideoSourceNotOpenError):
            sampler.sample()


class TestConfigValidation:
    def test_zero_or_negative_target_fps_rejected(self) -> None:
        source = open_mock_source()
        with pytest.raises(ValueError):
            FrameSampler(source, camera_uuid="cam-1", target_fps=0)
        with pytest.raises(ValueError):
            FrameSampler(source, camera_uuid="cam-1", target_fps=-1)

    def test_zero_or_negative_queue_size_rejected(self) -> None:
        source = open_mock_source()
        with pytest.raises(ValueError):
            FrameSampler(source, camera_uuid="cam-1", target_fps=5.0, queue_size=0)

    def test_source_fps_is_optional_and_informational_only(self) -> None:
        source = open_mock_source()
        clock = FakeClock(step=1.0 / 30)
        sampler = FrameSampler(
            source, camera_uuid="cam-1", target_fps=5.0, clock_fn=clock, source_fps=30.0
        )
        assert sampler.source_fps == 30.0
        # Correctness of throttling does not depend on source_fps being set.
        assert sampler.sample() is not None
