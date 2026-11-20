"""Plate pipeline data types (VEH-008, ACR-VEH-01).

A plate detection carries the four plate corners (for the perspective crop) and an axis-aligned
box (for the tracker). It has `bbox` and `confidence` like a face detection, so the existing
IoU tracker can follow plates without knowing what it tracks.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime

from app.detection.schemas import BoundingBox

Point = tuple[float, float]


@dataclass(frozen=True)
class PlateDetection:
    """One plate in a frame. corners: top-left, top-right, bottom-right, bottom-left (image
    pixels); confidence: detector score in [0, 1]."""

    corners: tuple[Point, Point, Point, Point]
    bbox: BoundingBox
    confidence: float


@dataclass(frozen=True)
class TextLine:
    """One OCR'd line: the decoded text and the mean character probability in [0, 1]."""

    text: str
    score: float


@dataclass(frozen=True)
class PlateRead:
    """What one frame said about one plate. Nothing here is normalised for the backend:
    plate_text is the cleaned OCR text, province_text the resolved code or the raw line."""

    plate_text: str
    plate_score: float
    province_text: str | None
    province_score: float
    detection_score: float
    canonical: str | None = None
    province_code: str | None = None


@dataclass(frozen=True)
class VotedPlate:
    """One result per vehicle pass, ready for PlateEventPayload."""

    track_id: str
    plate_text: str
    province_text: str | None
    ocr_score: float
    detection_score: float
    frames_voted: int
    occurred_at: datetime  # capture time of the frame with the best winning read
    reasons: list[str] = field(default_factory=list)
