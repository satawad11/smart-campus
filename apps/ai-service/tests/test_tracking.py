"""Tests for per-camera face tracking (CAM-008)."""

import pytest

from app.detection.schemas import BoundingBox, FaceDetection
from app.tracking import IoUTracker, Track, compute_iou


def make_detection(
    x1: float, y1: float, x2: float, y2: float, confidence: float = 0.95
) -> FaceDetection:
    return FaceDetection(
        bbox=BoundingBox(x1=x1, y1=y1, x2=x2, y2=y2),
        confidence=confidence,
        detector_name="mock_detector",
        detector_version="1.0.0",
    )


def counting_id_factory():
    counter = {"n": 0}

    def factory() -> str:
        counter["n"] += 1
        return f"track-{counter['n']}"

    return factory


class TestIoUGeometry:
    def test_identical_boxes_have_iou_one(self) -> None:
        box = BoundingBox(x1=0, y1=0, x2=10, y2=10)
        assert compute_iou(box, box) == pytest.approx(1.0)

    def test_disjoint_boxes_have_iou_zero(self) -> None:
        a = BoundingBox(x1=0, y1=0, x2=10, y2=10)
        b = BoundingBox(x1=100, y1=100, x2=110, y2=110)
        assert compute_iou(a, b) == 0.0

    def test_partial_overlap_between_zero_and_one(self) -> None:
        a = BoundingBox(x1=0, y1=0, x2=10, y2=10)
        b = BoundingBox(x1=5, y1=5, x2=15, y2=15)
        iou = compute_iou(a, b)
        assert 0.0 < iou < 1.0


class TestNewAndContinuingTracks:
    def test_new_track_created_for_first_detection(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        tracks = tracker.update([make_detection(0, 0, 20, 20)])
        assert len(tracks) == 1
        assert tracks[0].hits == 1
        assert tracks[0].age == 0
        assert tracks[0].missed_frames == 0

    def test_continuing_track_keeps_same_id_across_frames(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        t1 = tracker.update([make_detection(0, 0, 20, 20)])[0]
        # Slightly shifted box next frame (same face, small motion).
        t2 = tracker.update([make_detection(1, 1, 21, 21)])[0]
        assert t2.track_id == t1.track_id
        assert t2.hits == 2
        assert t2.age == 1

    def test_confidence_and_bbox_update_on_match(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        tracker.update([make_detection(0, 0, 20, 20, confidence=0.5)])
        updated = tracker.update([make_detection(0, 0, 20, 20, confidence=0.9)])[0]
        assert updated.confidence == pytest.approx(0.9)


class TestTrackExpiration:
    def test_disappeared_track_expires_after_max_missed_frames(self) -> None:
        tracker = IoUTracker(max_missed_frames=2, id_factory=counting_id_factory())
        tracker.update([make_detection(0, 0, 20, 20)])

        tracker.update([])  # miss 1
        assert tracker.active_track_count == 1
        tracker.update([])  # miss 2
        assert tracker.active_track_count == 1
        tracker.update([])  # miss 3 -> exceeds max_missed_frames=2 -> expired
        assert tracker.active_track_count == 0

    def test_track_survives_a_temporary_miss_within_tolerance(self) -> None:
        tracker = IoUTracker(max_missed_frames=3, id_factory=counting_id_factory())
        t1 = tracker.update([make_detection(0, 0, 20, 20)])[0]

        tracker.update([])  # one miss, still within tolerance

        t3 = tracker.update([make_detection(0, 0, 20, 20)])[0]
        assert t3.track_id == t1.track_id
        assert t3.missed_frames == 0  # reset on re-match
        assert t3.hits == 2

    def test_missed_frames_resets_to_zero_on_rematch_not_cumulative(self) -> None:
        tracker = IoUTracker(max_missed_frames=1, id_factory=counting_id_factory())
        t1 = tracker.update([make_detection(0, 0, 20, 20)])[0]
        tracker.update([])  # miss 1 (== max_missed_frames, not expired yet)
        t3 = tracker.update([make_detection(0, 0, 20, 20)])[0]  # rematch resets
        tracker.update([])  # miss 1 again (would have been miss 2 if cumulative)
        assert tracker.active_track_count == 1
        assert t3.track_id == t1.track_id


class TestMultipleFaces:
    def test_multiple_simultaneous_faces_tracked_independently(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        tracks = tracker.update(
            [
                make_detection(0, 0, 20, 20),
                make_detection(100, 100, 120, 120),
                make_detection(200, 200, 220, 220),
            ]
        )
        assert len(tracks) == 3
        assert len({t.track_id for t in tracks}) == 3

    def test_multiple_faces_each_continue_their_own_track(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        first = tracker.update([make_detection(0, 0, 20, 20), make_detection(100, 100, 120, 120)])
        ids_by_position = {t.bbox.x1: t.track_id for t in first}

        second = tracker.update([make_detection(1, 1, 21, 21), make_detection(101, 101, 121, 121)])
        for t in second:
            nearest_original_x1 = min(ids_by_position, key=lambda x1: abs(x1 - t.bbox.x1))
            assert t.track_id == ids_by_position[nearest_original_x1]

    def test_non_overlapping_parallel_faces_never_swap_ids(self) -> None:
        # Two faces moving in parallel (never close enough to be ambiguous)
        # across several frames must keep distinct, stable track IDs.
        tracker = IoUTracker(id_factory=counting_id_factory())
        left_positions = [(0, 0, 20, 20), (5, 0, 25, 20), (10, 0, 30, 20)]
        right_positions = [(200, 0, 220, 20), (205, 0, 225, 20), (210, 0, 230, 20)]

        left_id = right_id = None
        for left, right in zip(left_positions, right_positions, strict=True):
            tracks = tracker.update([make_detection(*left), make_detection(*right)])
            by_x = sorted(tracks, key=lambda t: t.bbox.x1)
            if left_id is None:
                left_id, right_id = by_x[0].track_id, by_x[1].track_id
            else:
                assert by_x[0].track_id == left_id
                assert by_x[1].track_id == right_id


class TestUnmatchedDetections:
    def test_detection_below_iou_threshold_starts_a_new_track(self) -> None:
        tracker = IoUTracker(iou_threshold=0.5, id_factory=counting_id_factory())
        t1 = tracker.update([make_detection(0, 0, 20, 20)])[0]
        # Barely overlapping -> IoU well below 0.5 -> treated as a different face.
        tracks = tracker.update([make_detection(19, 19, 39, 39)])
        assert len(tracks) == 2  # old track ages out but hasn't expired; new one created
        assert any(t.track_id != t1.track_id for t in tracks)


class TestDeterminism:
    def test_same_input_sequence_produces_same_track_assignments(self) -> None:
        sequence = [
            [make_detection(0, 0, 20, 20), make_detection(100, 100, 120, 120)],
            [make_detection(2, 2, 22, 22), make_detection(102, 102, 122, 122)],
            [make_detection(4, 4, 24, 24)],
        ]

        def run() -> list[list[str]]:
            tracker = IoUTracker(id_factory=counting_id_factory())
            results = []
            for frame_detections in sequence:
                tracks = tracker.update(frame_detections)
                results.append(sorted(t.track_id for t in tracks))
            return results

        assert run() == run()

    def test_tie_breaking_is_order_independent_of_dict_iteration(self) -> None:
        # Two existing tracks equally eligible for one ambiguous detection —
        # result must be stable across repeated runs regardless of internal
        # dict ordering (Python dicts are insertion-ordered, so this mainly
        # guards against a future refactor introducing nondeterminism).
        outcomes = set()
        for _ in range(5):
            tracker = IoUTracker(id_factory=counting_id_factory())
            tracker.update([make_detection(0, 0, 20, 20), make_detection(15, 0, 35, 20)])
            tracks = tracker.update([make_detection(7, 0, 27, 20)])
            outcomes.add(tuple(sorted(t.track_id for t in tracks)))
        assert len(outcomes) == 1


class TestBiometricIdentitySeparation:
    def test_track_has_no_biometric_identity_fields(self) -> None:
        track_fields = set(Track.__dataclass_fields__.keys())
        assert "person_id" not in track_fields
        assert "person_uuid" not in track_fields
        assert "face_profile_id" not in track_fields
        assert "student_id" not in track_fields
        assert "embedding" not in track_fields

    def test_track_id_is_a_fresh_synthetic_value_not_derived_from_detection(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        track = tracker.update([make_detection(0, 0, 20, 20)])[0]
        assert track.track_id == "track-1"


class TestConfigValidation:
    def test_invalid_iou_threshold_rejected(self) -> None:
        with pytest.raises(ValueError):
            IoUTracker(iou_threshold=0.0)
        with pytest.raises(ValueError):
            IoUTracker(iou_threshold=1.5)

    def test_negative_max_missed_frames_rejected(self) -> None:
        with pytest.raises(ValueError):
            IoUTracker(max_missed_frames=-1)


class TestResetAndEmptyFrames:
    def test_empty_detections_ages_tracks_without_creating_new_ones(self) -> None:
        tracker = IoUTracker(max_missed_frames=5, id_factory=counting_id_factory())
        tracker.update([make_detection(0, 0, 20, 20)])
        tracks = tracker.update([])
        assert len(tracks) == 1
        assert tracks[0].missed_frames == 1

    def test_reset_clears_all_tracks(self) -> None:
        tracker = IoUTracker(id_factory=counting_id_factory())
        tracker.update([make_detection(0, 0, 20, 20)])
        tracker.reset()
        assert tracker.active_track_count == 0
        # Next detection starts a fresh track (id counter continues, but no
        # continuity with the pre-reset track is implied or claimed).
        tracks = tracker.update([make_detection(0, 0, 20, 20)])
        assert len(tracks) == 1
