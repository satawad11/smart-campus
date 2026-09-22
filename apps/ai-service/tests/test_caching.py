"""Tests for RecognitionCache (CAM-010).

Direct unit tests against the cache in isolation — no worker/pipeline
dependency.
"""

from datetime import UTC, datetime

from app.caching.cache import RecognitionCache
from app.detection.schemas import BoundingBox
from app.orchestration.schemas import RecognitionWorkerResult
from app.tracking.schemas import Track


class FakeClock:
    def __init__(self, start: float = 0.0):
        self._now = start

    def __call__(self) -> float:
        return self._now

    def advance(self, seconds: float) -> None:
        self._now += seconds


def make_track(track_id: str = "track-1", hits: int = 1) -> Track:
    return Track(
        track_id=track_id,
        bbox=BoundingBox(x1=0, y1=0, x2=20, y2=20),
        confidence=0.95,
        age=hits - 1,
        hits=hits,
        missed_frames=0,
    )


def make_result(
    track_id: str = "track-1",
    model_code: str = "arcface-r50-v1",
    model_version: str = "1.0.0",
) -> RecognitionWorkerResult:
    return RecognitionWorkerResult(
        event_uuid="event-1",
        camera_uuid="camera-uuid-1",
        ai_node_uuid="node-uuid-1",
        track_id=track_id,
        observed_at=datetime.now(UTC),
        classification="MATCH",
        candidate_person_uuid="person-uuid-1",
        similarity=0.95,
        distance=0.05,
        model_code=model_code,
        model_version=model_version,
        quality_score=0.9,
        liveness_status="REAL",
        rejected_locally=False,
    )


class TestCacheHitAndMiss:
    def test_miss_on_empty_cache(self) -> None:
        cache = RecognitionCache()
        assert cache.get("camera-1", make_track(), "arcface-r50-v1", "1.0.0") is None

    def test_hit_returns_the_put_result(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        track = make_track(hits=1)
        result = make_result()
        cache.put("camera-1", track, result, "arcface-r50-v1", "1.0.0")

        fetched = cache.get("camera-1", make_track(hits=2), "arcface-r50-v1", "1.0.0")
        assert fetched is result

    def test_miss_for_different_camera(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        track = make_track()
        cache.put("camera-1", track, make_result(), "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-2", track, "arcface-r50-v1", "1.0.0") is None

    def test_miss_for_different_track_id(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-1", make_track(track_id="b"), "arcface-r50-v1", "1.0.0") is None


class TestTtlExpiry:
    def test_entry_valid_within_ttl(self) -> None:
        clock = FakeClock()
        cache = RecognitionCache(ttl_seconds=10, clock_fn=clock)
        track = make_track()
        result = make_result()
        cache.put("camera-1", track, result, "arcface-r50-v1", "1.0.0")

        clock.advance(9.999)
        assert cache.get("camera-1", track, "arcface-r50-v1", "1.0.0") is result

    def test_entry_expires_after_ttl(self) -> None:
        clock = FakeClock()
        cache = RecognitionCache(ttl_seconds=10, clock_fn=clock)
        track = make_track()
        cache.put("camera-1", track, make_result(), "arcface-r50-v1", "1.0.0")

        clock.advance(10.001)
        assert cache.get("camera-1", track, "arcface-r50-v1", "1.0.0") is None

    def test_expired_entry_is_evicted_from_the_store(self) -> None:
        clock = FakeClock()
        cache = RecognitionCache(ttl_seconds=10, clock_fn=clock)
        track = make_track()
        cache.put("camera-1", track, make_result(), "arcface-r50-v1", "1.0.0")
        clock.advance(20)
        cache.get("camera-1", track, "arcface-r50-v1", "1.0.0")
        assert cache.size == 0


class TestTrackReuseSafety:
    def test_lower_hits_than_cached_invalidates_entry(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        cache.put("camera-1", make_track(hits=10), make_result(), "arcface-r50-v1", "1.0.0")

        # A fresh object with the same reused track_id starts back at low hits.
        reused_track = make_track(hits=1)
        assert cache.get("camera-1", reused_track, "arcface-r50-v1", "1.0.0") is None
        assert cache.size == 0

    def test_equal_or_higher_hits_is_a_valid_hit(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        result = make_result()
        cache.put("camera-1", make_track(hits=5), result, "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-1", make_track(hits=5), "arcface-r50-v1", "1.0.0") is result
        cache.put("camera-1", make_track(hits=5), result, "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-1", make_track(hits=8), "arcface-r50-v1", "1.0.0") is result


class TestModelChangeInvalidation:
    def test_model_code_change_invalidates(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        track = make_track()
        cache.put("camera-1", track, make_result(), "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-1", track, "other-model", "1.0.0") is None

    def test_model_version_change_invalidates(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        track = make_track()
        cache.put("camera-1", track, make_result(), "arcface-r50-v1", "1.0.0")
        assert cache.get("camera-1", track, "arcface-r50-v1", "2.0.0") is None


class TestExplicitInvalidation:
    def test_invalidate_removes_one_entry(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-1", make_track(track_id="b"), make_result(), "arcface-r50-v1", "1.0.0")

        cache.invalidate("camera-1", "a")

        assert cache.get("camera-1", make_track(track_id="a"), "arcface-r50-v1", "1.0.0") is None
        assert (
            cache.get("camera-1", make_track(track_id="b"), "arcface-r50-v1", "1.0.0") is not None
        )

    def test_invalidate_all_clears_the_whole_cache(self) -> None:
        cache = RecognitionCache(clock_fn=FakeClock())
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-2", make_track(track_id="b"), make_result(), "arcface-r50-v1", "1.0.0")

        cache.invalidate_all()

        assert cache.size == 0
        assert cache.get("camera-1", make_track(track_id="a"), "arcface-r50-v1", "1.0.0") is None


class TestBoundedSize:
    def test_oldest_entry_evicted_when_max_size_exceeded(self) -> None:
        cache = RecognitionCache(max_size=2, clock_fn=FakeClock())
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-1", make_track(track_id="b"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-1", make_track(track_id="c"), make_result(), "arcface-r50-v1", "1.0.0")

        assert cache.size == 2
        assert cache.get("camera-1", make_track(track_id="a"), "arcface-r50-v1", "1.0.0") is None
        assert (
            cache.get("camera-1", make_track(track_id="c"), "arcface-r50-v1", "1.0.0") is not None
        )

    def test_updating_existing_key_does_not_evict(self) -> None:
        cache = RecognitionCache(max_size=2, clock_fn=FakeClock())
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-1", make_track(track_id="b"), make_result(), "arcface-r50-v1", "1.0.0")
        cache.put("camera-1", make_track(track_id="a"), make_result(), "arcface-r50-v1", "1.0.0")

        assert cache.size == 2


class TestConstructorValidation:
    def test_non_positive_ttl_rejected(self) -> None:
        import pytest

        with pytest.raises(ValueError):
            RecognitionCache(ttl_seconds=0)

    def test_non_positive_max_size_rejected(self) -> None:
        import pytest

        with pytest.raises(ValueError):
            RecognitionCache(max_size=0)
