"""Controlled frame sampling (CAM-007).

Purpose: avoid running face AI on every camera frame. Reads from a
VideoSource (CAM-005/006) and decides, per frame, whether to keep it for
downstream processing based on a target processing rate — independent of
whatever the source's actual/nominal frame rate is. Kept frames are held in
a small bounded queue; VideoSource/detection/recognition errors are never
caught here — this module owns sampling policy only.
"""

from collections import deque
from collections.abc import Callable
from time import monotonic

from app.sampling.schemas import SampledFrame
from app.streams.base import VideoSource


class FrameSampler:
    """Throttles a VideoSource to a target processing rate with a bounded
    output queue.

    Rate control is wall-clock/time-based (elapsed time since the last KEPT
    frame vs. 1/target_fps) — it works correctly regardless of the source's
    actual frame rate, and does not require it to be known in advance.
    `source_fps`, if supplied, is informational telemetry only (e.g. for
    computing an expected drop ratio) and never affects correctness.

    Backpressure: the output queue is bounded (`queue_size`). When full, a
    newly sampled frame evicts the oldest queued one (deque(maxlen=...)) —
    matching the project-wide policy of preferring to drop stale frames over
    processing/holding ancient ones. Nothing here ever grows unboundedly.
    """

    def __init__(
        self,
        source: VideoSource,
        camera_uuid: str,
        target_fps: float,
        queue_size: int = 5,
        source_fps: float | None = None,
        clock_fn: Callable[[], float] | None = None,
    ):
        if target_fps <= 0:
            raise ValueError("target_fps must be positive")
        if queue_size <= 0:
            raise ValueError("queue_size must be positive")

        self._source = source
        self._camera_uuid = camera_uuid
        self._target_fps = target_fps
        self._min_interval = 1.0 / target_fps
        self._source_fps = source_fps
        self._clock_fn = clock_fn or monotonic

        self._queue: deque[SampledFrame] = deque(maxlen=queue_size)
        self._last_kept_at: float | None = None
        self._next_sampled_sequence = 0
        self._dropped_count = 0
        self._evicted_count = 0
        self._read_count = 0

    @property
    def queue_depth(self) -> int:
        return len(self._queue)

    @property
    def dropped_count(self) -> int:
        """Frames read from the source but rejected by the rate throttle."""
        return self._dropped_count

    @property
    def evicted_count(self) -> int:
        """Kept frames later discarded because the output queue was full
        when a newer frame arrived (stale-frame eviction, not a rate drop)."""
        return self._evicted_count

    @property
    def read_count(self) -> int:
        return self._read_count

    @property
    def source_fps(self) -> float | None:
        return self._source_fps

    def sample(self) -> SampledFrame | None:
        """Read exactly one frame from the source. If the target-rate
        throttle accepts it, enqueue (evicting the oldest queued frame if
        the queue is full) and return it; otherwise return None.

        Propagates the source's own VideoSourceNotOpenError / EOF / read
        errors unchanged — this method does not swallow them.
        """
        frame = self._source.read()
        self._read_count += 1

        now = self._clock_fn()
        if self._last_kept_at is not None and (now - self._last_kept_at) < self._min_interval:
            self._dropped_count += 1
            return None

        self._last_kept_at = now

        sampled = SampledFrame(
            frame=frame,
            camera_uuid=self._camera_uuid,
            sampled_sequence=self._next_sampled_sequence,
        )
        self._next_sampled_sequence += 1

        if len(self._queue) == self._queue.maxlen:
            self._evicted_count += 1
        self._queue.append(sampled)

        return sampled

    def pop(self) -> SampledFrame | None:
        """Pop the oldest currently-queued sampled frame (FIFO), or None if
        the queue is empty."""
        if not self._queue:
            return None
        return self._queue.popleft()
