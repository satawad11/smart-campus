"""Node plate pipeline (VEH-008, ACR-VEH-01 decision 3).

plate detection -> perspective crop -> OCR of both rows -> province resolution -> text
normalisation -> per-track vote across frames -> one result per vehicle pass.

The node only reports what it read. The backend normalises again, applies the OCR confidence
threshold (config alpr.ocr_min_confidence), looks up the vehicle and picks the event type; a
plate never decides access (D2). Nothing here stores or sends plate images.
"""

from __future__ import annotations

import re
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import datetime

import numpy as np

from app.plates import thai
from app.plates.detector import PlateDetector
from app.plates.ocr import TextLineRecognizer, rectify, split_rows
from app.plates.schemas import PlateDetection, PlateRead, VotedPlate
from app.roi.filter import is_detection_in_roi
from app.roi.schemas import RegionOfInterest
from app.tracking.tracker import IoUTracker

# Registration row: Thai consonants and digits only (vowels, tone marks and punctuation are
# OCR noise on a plate).
_PLATE_CHARS = re.compile(r"[^ก-ฮ0-9๐-๙]")

REASON_TEXT_INVALID = "PLATE_TEXT_INVALID"
REASON_PROVINCE_UNRESOLVED = "PROVINCE_UNRESOLVED"
REASON_VOTE_DISAGREEMENT = "VOTE_DISAGREEMENT"


class PlateReader:
    """Reads one detected plate in one frame."""

    def __init__(self, detector: PlateDetector, recognizer: TextLineRecognizer):
        self.detector = detector
        self.recognizer = recognizer

    @property
    def is_ready(self) -> bool:
        return self.detector.is_ready and self.recognizer.is_ready

    def read(self, image: np.ndarray, detection: PlateDetection) -> PlateRead:
        plate = rectify(image, detection.corners)
        top, bottom = split_rows(plate)
        registration = self.recognizer.read(top)
        province = self.recognizer.read(bottom)
        cleaned = _PLATE_CHARS.sub("", registration.text)
        province_raw = province.text.strip() or None
        return PlateRead(
            plate_text=cleaned,
            plate_score=registration.score,
            province_text=province_raw,
            province_score=province.score,
            detection_score=detection.confidence,
            canonical=thai.try_normalize(cleaned),
            province_code=thai.closest_province(province_raw),
        )


@dataclass
class _TrackReads:
    reads: list[tuple[PlateRead, datetime]] = field(default_factory=list)


def vote(track_id: str, reads: list[tuple[PlateRead, datetime]]) -> VotedPlate | None:
    """One result from all reads of one track; None when no frame read any text.

    The winning text is the normalised plate with the highest total OCR score; when no frame
    gave a valid Thai plate, the single best raw read is sent and marked PLATE_TEXT_INVALID
    (the backend records it as PLATE_LOW_CONFIDENCE).
    """
    usable = [(r, t) for r, t in reads if r.plate_text]
    if not usable:
        return None
    reasons: list[str] = []

    weights: dict[str, float] = {}
    for r, _ in usable:
        if r.canonical is not None:
            weights[r.canonical] = weights.get(r.canonical, 0.0) + r.plate_score
    if weights:
        winner = max(sorted(weights), key=lambda k: weights[k])
        winning = [(r, t) for r, t in usable if r.canonical == winner]
        plate_text = winner
        if weights[winner] < 2 * (sum(weights.values()) - weights[winner]):
            reasons.append(REASON_VOTE_DISAGREEMENT)
    else:
        best = max(usable, key=lambda rt: rt[0].plate_score)
        winning = [best]
        plate_text = best[0].plate_text
        reasons.append(REASON_TEXT_INVALID)

    provinces: dict[str, float] = {}
    for r, _ in winning:
        if r.province_code is not None:
            provinces[r.province_code] = provinces.get(r.province_code, 0.0) + r.province_score
    if provinces:
        province_text: str | None = max(sorted(provinces), key=lambda k: provinces[k])
    else:
        reasons.append(REASON_PROVINCE_UNRESOLVED)
        raw = [r for r, _ in winning if r.province_text]
        province_text = max(raw, key=lambda r: r.province_score).province_text if raw else None

    best_read, best_time = max(winning, key=lambda rt: rt[0].plate_score)
    return VotedPlate(
        track_id=track_id,
        plate_text=plate_text,
        province_text=province_text,
        ocr_score=round(float(np.mean([r.plate_score for r, _ in winning])), 4),
        detection_score=round(max(r.detection_score for r, _ in winning), 4),
        frames_voted=len(winning),
        occurred_at=best_time,
        reasons=reasons,
    )


class CameraPlateSession:
    """Plate tracking and voting for one camera.

    A track gets one result: after ``max_reads`` reads (so a gate pass is reported within a
    second or two, inside the gate window) or when the plate leaves the frame, whichever is
    first. A car that stays in view is not reported again until its track ends.
    """

    def __init__(
        self,
        reader: PlateReader,
        max_reads: int = 8,
        max_missed_frames: int = 5,
        id_factory: Callable[[], str] | None = None,
    ):
        self.reader = reader
        self.max_reads = max_reads
        self.tracker = IoUTracker(max_missed_frames=max_missed_frames, id_factory=id_factory)
        self._tracks: dict[str, _TrackReads] = {}
        self._reported: set[str] = set()

    def process(
        self,
        image: np.ndarray,
        captured_at: datetime,
        roi: RegionOfInterest | None = None,
    ) -> list[VotedPlate]:
        height, width = image.shape[:2]
        detections = self.reader.detector.detect(image)
        if roi is not None:
            detections = [d for d in detections if is_detection_in_roi(d, roi, width, height)]
        tracks = self.tracker.update(detections)  # type: ignore[arg-type]
        alive = {t.track_id for t in tracks}
        results: list[VotedPlate] = []

        for track in tracks:
            if track.missed_frames != 0 or track.track_id in self._reported:
                continue
            detection = next((d for d in detections if d.bbox == track.bbox), None)
            if detection is None:
                continue
            state = self._tracks.setdefault(track.track_id, _TrackReads())
            state.reads.append((self.reader.read(image, detection), captured_at))
            if len(state.reads) >= self.max_reads:
                results.extend(self._finish(track.track_id))

        for track_id in list(self._tracks):
            if track_id not in alive:
                results.extend(self._finish(track_id))
        self._reported &= alive
        return results

    def _finish(self, track_id: str) -> list[VotedPlate]:
        state = self._tracks.pop(track_id, None)
        self._reported.add(track_id)
        if state is None:
            return []
        voted = vote(track_id, state.reads)
        return [voted] if voted is not None else []

    def flush(self) -> list[VotedPlate]:
        """Results for every track still being read (camera stopping)."""
        results: list[VotedPlate] = []
        for track_id in list(self._tracks):
            results.extend(self._finish(track_id))
        return results

    def reset(self) -> None:
        """Drop everything unreported (plate reading switched off: fail closed)."""
        self._tracks.clear()
        self._reported.clear()
        self.tracker.reset()
