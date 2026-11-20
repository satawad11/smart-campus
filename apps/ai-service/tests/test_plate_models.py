"""VEH-008: the real plate models load and run through OpenCV DNN.

Skips when apps/ai-service/models/ has not been populated (scripts/fetch-plate-models.sh).
These tests check the wiring (input sizes, output classes, decoding), not accuracy: accuracy is
measured on real gate photos with ``python -m app.plates.evaluate`` (VEH-007 part 2).
"""

from __future__ import annotations

import os

import numpy as np
import pytest

from app.plates.detector import LpdYuNetPlateDetector
from app.plates.ocr import PaddleTextLineRecognizer, load_dictionary

MODELS = os.path.join(os.path.dirname(__file__), "..", "models")
LPD = os.path.join(MODELS, "license_plate_detection_lpd_yunet_2023mar.onnx")
OCR = os.path.join(MODELS, "th_ppocrv5_mobile_rec.onnx")
DICT = os.path.join(MODELS, "th_ppocrv5_mobile_rec_dict.txt")
needs_models = pytest.mark.skipif(
    not all(os.path.isfile(p) for p in (LPD, OCR, DICT)),
    reason="run scripts/fetch-plate-models.sh",
)


@needs_models
@pytest.mark.parametrize("shape", [(480, 640, 3), (1080, 1920, 3), (240, 320, 3)])
def test_detector_runs_on_camera_sized_frames(shape) -> None:
    detector = LpdYuNetPlateDetector(LPD)
    assert detector.is_ready
    assert detector.detect(np.zeros(shape, dtype=np.uint8)) == []


@needs_models
def test_dictionary_matches_the_model_output() -> None:
    classes = load_dictionary(DICT)
    assert classes[0] == "" and classes[-1] == " "
    assert "ก" in classes and "ฮ" in classes and "9" in classes

    recognizer = PaddleTextLineRecognizer(OCR, DICT)
    assert recognizer.is_ready
    recognizer._net.setInput(recognizer._blob(np.full((48, 200, 3), 255, dtype=np.uint8)))
    probs = recognizer._net.forward()
    assert probs.shape[-1] == len(classes)


@needs_models
@pytest.mark.parametrize("size", [(48, 40), (60, 340), (90, 340), (30, 600)])
def test_recognizer_reads_any_line_size(size) -> None:
    recognizer = PaddleTextLineRecognizer(OCR, DICT)
    line = recognizer.read(np.full((*size, 3), 255, dtype=np.uint8))
    assert 0.0 <= line.score <= 1.0
