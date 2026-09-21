"""Per-camera IoU-based face tracker (CAM-008).

Associates face detections across nearby frames using greedy IoU matching —
no Kalman filter, no Hungarian-algorithm/scipy dependency, matching the
task's "do not add unnecessary heavy dependency" guidance. One tracker
instance owns exactly one camera's track set; callers create one per camera.

No biometric identity decisions happen here: this module only ever sees
detection bounding boxes/confidence, never embeddings, never person/profile
identity, and produces only ephemeral track IDs.
"""

import uuid
from collections.abc import Callable

from app.detection.schemas import FaceDetection
from app.tracking.iou import compute_iou
from app.tracking.schemas import Track


class IoUTracker:
    """Deterministic greedy-IoU multi-object tracker for one camera.

    Matching: every (existing track, new detection) pair with IoU >= the
    threshold is a match candidate. Candidates are sorted by
    (-iou, track_id, detection_index) — a fixed, fully deterministic order —
    and assigned greedily (each track and each detection used at most once).
    Unmatched tracks accumulate missed_frames and expire (are dropped) once
    that exceeds max_missed_frames; unmatched detections start new tracks.
    """

    def __init__(
        self,
        iou_threshold: float = 0.3,
        max_missed_frames: int = 5,
        id_factory: Callable[[], str] | None = None,
    ):
        if not (0.0 < iou_threshold <= 1.0):
            raise ValueError("iou_threshold must be in (0.0, 1.0]")
        if max_missed_frames < 0:
            raise ValueError("max_missed_frames must be >= 0")

        self._iou_threshold = iou_threshold
        self._max_missed_frames = max_missed_frames
        self._id_factory = id_factory or (lambda: str(uuid.uuid4()))
        self._tracks: dict[str, Track] = {}

    @property
    def active_track_count(self) -> int:
        return len(self._tracks)

    def update(self, detections: list[FaceDetection]) -> list[Track]:
        """Process one frame's detections and return the current active
        track list (order: insertion/creation order is not guaranteed —
        callers needing a stable order should sort by track_id)."""
        candidates: list[tuple[float, str, int]] = []
        track_ids = list(self._tracks.keys())

        for track_id in track_ids:
            track_bbox = self._tracks[track_id].bbox
            for detection_index, detection in enumerate(detections):
                iou = compute_iou(track_bbox, detection.bbox)
                if iou >= self._iou_threshold:
                    candidates.append((iou, track_id, detection_index))

        # Deterministic greedy assignment: highest IoU first; ties broken by
        # track_id then detection_index so results never depend on dict/set
        # iteration order.
        candidates.sort(key=lambda c: (-c[0], c[1], c[2]))

        matched_track_ids: set[str] = set()
        matched_detection_indices: set[int] = set()

        for _iou, track_id, detection_index in candidates:
            if track_id in matched_track_ids or detection_index in matched_detection_indices:
                continue
            matched_track_ids.add(track_id)
            matched_detection_indices.add(detection_index)

            detection = detections[detection_index]
            track = self._tracks[track_id]
            track.bbox = detection.bbox
            track.confidence = detection.confidence
            track.age += 1
            track.hits += 1
            track.missed_frames = 0

        # Unmatched existing tracks: age, accumulate a miss, expire if stale.
        for track_id in track_ids:
            if track_id in matched_track_ids:
                continue
            track = self._tracks[track_id]
            track.age += 1
            track.missed_frames += 1
            if track.missed_frames > self._max_missed_frames:
                del self._tracks[track_id]

        # Unmatched detections: new tracks.
        for detection_index, detection in enumerate(detections):
            if detection_index in matched_detection_indices:
                continue
            new_id = self._id_factory()
            self._tracks[new_id] = Track(
                track_id=new_id,
                bbox=detection.bbox,
                confidence=detection.confidence,
                age=0,
                hits=1,
                missed_frames=0,
            )

        return list(self._tracks.values())

    def reset(self) -> None:
        """Discard all tracks — e.g. on camera reconnect (CAM-006), where
        continuity across the gap can't be assumed."""
        self._tracks.clear()
