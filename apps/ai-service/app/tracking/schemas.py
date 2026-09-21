"""Face tracking schemas (CAM-008)."""

from dataclasses import dataclass

from app.detection.schemas import BoundingBox


@dataclass
class Track:
    """One ephemeral tracked face, associated across nearby frames.

    track_id is a randomly generated, per-process-lifetime identifier. It is
    NEVER a biometric identity and must never be treated as one downstream:
    track_id != person_id, track_id != face_profile_id, track_id != student
    identity. It exists only to associate detections belonging to (probably)
    the same physical face across a short run of frames, so recognition
    doesn't have to re-run on every single frame (CAM-009/CAM-010).

    age: frames since this track was created (matched or not).
    hits: frames where this track was successfully matched to a detection.
    missed_frames: CONSECUTIVE frames with no matching detection (reset to 0
    on any match). A track expires once this exceeds the tracker's
    max_missed_frames.
    """

    track_id: str
    bbox: BoundingBox
    confidence: float
    age: int
    hits: int
    missed_frames: int
