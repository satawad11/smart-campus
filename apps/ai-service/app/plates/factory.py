"""Plate reader factory (VEH-008).

``mock`` mode gets a reader that never finds a plate, so a rehearsal node does not invent plate
events. ``onnx`` mode gets LPD-YuNet + PaddleOCR Thai from models/ (scripts/fetch-plate-models.sh);
when a file is missing the reader is not ready and the node reads no plates (fail closed).
"""

from __future__ import annotations

from app.core.config import settings
from app.plates.detector import LpdYuNetPlateDetector, MockPlateDetector
from app.plates.ocr import MockTextLineRecognizer, PaddleTextLineRecognizer
from app.plates.pipeline import PlateReader


def get_plate_reader(provider: str | None = None) -> PlateReader:
    resolved = (provider or settings.ai_mode).strip().lower()
    if resolved == "mock":
        return PlateReader(MockPlateDetector(), MockTextLineRecognizer())
    if resolved == "onnx":
        return PlateReader(
            LpdYuNetPlateDetector(
                settings.plate_detector_model_path,
                score_threshold=settings.plate_detection_threshold,
                input_width=settings.plate_detector_input_width,
            ),
            PaddleTextLineRecognizer(settings.plate_ocr_model_path, settings.plate_ocr_dict_path),
        )
    raise ValueError(f"Unsupported plate reader provider: '{resolved}'.")
