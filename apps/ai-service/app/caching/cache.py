"""Short-lived, per-track recognition cache (CAM-010).

Purpose: avoid repeated vector search/inference for the same stable track.
This is deliberately a small, bounded, TTL-based cache — never a substitute
for durable state, and never a place raw biometric vectors are stored (only
the already-safe RecognitionWorkerResult, which itself never carries a raw
embedding — see CAM-009's schema).
"""

import time
from collections import OrderedDict
from dataclasses import dataclass

from app.orchestration.schemas import RecognitionWorkerResult
from app.tracking.schemas import Track


@dataclass
class _CacheEntry:
    result: RecognitionWorkerResult
    cached_at: float
    model_code: str
    model_version: str
    hits_at_cache_time: int
    ttl_seconds: float | None = None


class RecognitionCache:
    """Per-(camera, track) cache of the most recent recognition result.

    Bounded (max_size, oldest-first eviction) and TTL-based (ttl_seconds).
    Invalidated automatically on:
      - TTL expiry.
      - model_code/model_version change (a config/model swap must never
        serve a result computed under the old model).
      - track reuse: Track.hits only ever increases for a continuously
        tracked object; if the presented track's hits is LOWER than what
        was cached, the track_id has been reused for a different physical
        track (or the tracker was reset) and the stale entry is discarded
        rather than ever being served as if it were still valid. This is
        the primary defense against "safe against stale identity reuse"
        without needing a separate generation counter, since a fresh
        object's hits always starts at 1.
      - explicit invalidate()/invalidate_all() (e.g. a full recognition
        config reload).
    """

    def __init__(self, ttl_seconds: float = 30.0, max_size: int = 1000, clock_fn=None):
        if ttl_seconds <= 0:
            raise ValueError("ttl_seconds must be positive")
        if max_size <= 0:
            raise ValueError("max_size must be positive")

        self._ttl_seconds = ttl_seconds
        self._max_size = max_size
        self._clock_fn = clock_fn or time.monotonic
        self._store: OrderedDict[tuple[str, str], _CacheEntry] = OrderedDict()

    @property
    def size(self) -> int:
        return len(self._store)

    def get(
        self, camera_uuid: str, track: Track, model_code: str, model_version: str
    ) -> RecognitionWorkerResult | None:
        key = (camera_uuid, track.track_id)
        entry = self._store.get(key)
        if entry is None:
            return None

        if entry.model_code != model_code or entry.model_version != model_version:
            self.invalidate(camera_uuid, track.track_id)
            return None

        if track.hits < entry.hits_at_cache_time:
            # track_id reused by a different physical track (or tracker
            # reset) — never serve the previous track's result.
            self.invalidate(camera_uuid, track.track_id)
            return None

        ttl = entry.ttl_seconds if entry.ttl_seconds is not None else self._ttl_seconds
        if (self._clock_fn() - entry.cached_at) > ttl:
            self.invalidate(camera_uuid, track.track_id)
            return None

        return entry.result

    def put(
        self,
        camera_uuid: str,
        track: Track,
        result: RecognitionWorkerResult,
        model_code: str,
        model_version: str,
        ttl_seconds: float | None = None,
    ) -> None:
        """Cache a result. ``ttl_seconds`` overrides the cache-wide TTL for this entry
        (a rejected/non-MATCH attempt is kept only briefly so the track is re-tried)."""
        if ttl_seconds is not None and ttl_seconds <= 0:
            raise ValueError("ttl_seconds must be positive")
        key = (camera_uuid, track.track_id)

        if key not in self._store and len(self._store) >= self._max_size:
            self._store.popitem(last=False)  # evict oldest

        self._store[key] = _CacheEntry(
            result=result,
            cached_at=self._clock_fn(),
            model_code=model_code,
            model_version=model_version,
            hits_at_cache_time=track.hits,
            ttl_seconds=ttl_seconds,
        )
        self._store.move_to_end(key)

    def invalidate(self, camera_uuid: str, track_id: str) -> None:
        self._store.pop((camera_uuid, track_id), None)

    def invalidate_all(self) -> None:
        """Global invalidation — e.g. recognition policy/config reload."""
        self._store.clear()
