"""Plate crop and Thai text-line OCR (VEH-008, ACR-VEH-01 D4).

- ``rectify``: perspective-warp the four plate corners to a flat plate image.
- ``split_rows``: a Thai plate has two rows: the registration on top (``1กข 1234``) and the
  province name below. The split is the emptiest row band between 45% and 75% of the height,
  falling back to 62%.
- ``PaddleTextLineRecognizer``: PaddleOCR ``th_PP-OCRv5_mobile_rec`` (Apache-2.0), converted
  to ONNX by scripts/fetch-plate-models.sh and run through OpenCV DNN. Input: BGR, height 48,
  width by aspect ratio (max 320), scaled to [-1, 1]. Output: per time step probabilities over
  [blank] + dictionary + [space], decoded greedily (CTC).
"""

from __future__ import annotations

import os
from abc import ABC, abstractmethod

import cv2
import numpy as np

from app.detection.yunet import to_bgr
from app.plates.schemas import Point, TextLine

PLATE_WIDTH = 340
PLATE_HEIGHT = 150  # Thai car plate 34 x 15 cm


def order_corners(corners: tuple[Point, ...] | list[Point]) -> np.ndarray:
    """Top-left, top-right, bottom-right, bottom-left, whatever order the detector used."""
    pts = np.array(corners, dtype=np.float32).reshape(4, 2)
    s = pts.sum(axis=1)
    d = pts[:, 1] - pts[:, 0]
    return np.array(
        [pts[np.argmin(s)], pts[np.argmin(d)], pts[np.argmax(s)], pts[np.argmax(d)]],
        dtype=np.float32,
    )


def rectify(image: np.ndarray, corners: tuple[Point, ...] | list[Point]) -> np.ndarray:
    """Flat PLATE_WIDTH x PLATE_HEIGHT BGR crop of the plate."""
    src = order_corners(corners)
    dst = np.array(
        [[0, 0], [PLATE_WIDTH - 1, 0], [PLATE_WIDTH - 1, PLATE_HEIGHT - 1], [0, PLATE_HEIGHT - 1]],
        dtype=np.float32,
    )
    matrix = cv2.getPerspectiveTransform(src, dst)
    bgr = to_bgr(np.ascontiguousarray(image))
    if bgr.dtype != np.uint8:
        bgr = np.clip(bgr, 0, 255).astype(np.uint8)
    return cv2.warpPerspective(bgr, matrix, (PLATE_WIDTH, PLATE_HEIGHT))


def split_rows(plate: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """(registration row, province row) of a rectified plate."""
    height = plate.shape[0]
    gray = cv2.cvtColor(plate, cv2.COLOR_BGR2GRAY) if plate.ndim == 3 else plate
    _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)
    ink = binary.sum(axis=1).astype(np.float64)
    low, high = int(height * 0.45), int(height * 0.75)
    split = int(height * 0.62)
    if high > low and ink.max() > 0:
        band = ink[low:high]
        if band.min() < 0.5 * ink.max():
            split = low + int(np.argmin(band))
    return plate[:split], plate[split:]


class TextLineRecognizer(ABC):
    @property
    @abstractmethod
    def is_ready(self) -> bool: ...

    @abstractmethod
    def read(self, line_bgr: np.ndarray) -> TextLine:
        """Text of one line image (BGR)."""


class MockTextLineRecognizer(TextLineRecognizer):
    """Returns queued lines in order (tests), then empty text."""

    def __init__(self, lines: list[TextLine] | None = None):
        self.lines = list(lines or [])

    @property
    def is_ready(self) -> bool:
        return True

    def read(self, line_bgr: np.ndarray) -> TextLine:
        return self.lines.pop(0) if self.lines else TextLine("", 0.0)


def load_dictionary(path: str) -> list[str]:
    """CTC classes: blank, the dictionary file's characters (one per line), space."""
    with open(path, encoding="utf-8") as handle:
        chars = [line.rstrip("\n").rstrip("\r") for line in handle]
    chars = [c for c in chars if c != ""]
    return ["", *chars, " "]


def ctc_decode(probs: np.ndarray, classes: list[str]) -> TextLine:
    """Greedy CTC: best class per step, merge repeats, drop blanks. Score: mean probability of
    the kept characters (0 when nothing was read)."""
    probs = probs.reshape(probs.shape[-2], probs.shape[-1])
    best = probs.argmax(axis=1)
    best_p = probs.max(axis=1)
    text: list[str] = []
    scores: list[float] = []
    previous = -1
    for index, p in zip(best, best_p, strict=True):
        index = int(index)
        if index != previous and index != 0 and index < len(classes):
            text.append(classes[index])
            scores.append(float(p))
        previous = index
    if not text:
        return TextLine("", 0.0)
    return TextLine("".join(text), float(np.mean(scores)))


class PaddleTextLineRecognizer(TextLineRecognizer):
    HEIGHT = 48
    MAX_WIDTH = 320

    def __init__(self, model_path: str | None, dict_path: str | None):
        self.model_path = model_path
        self._net = None
        self._classes: list[str] = []
        if model_path and dict_path and os.path.isfile(model_path) and os.path.isfile(dict_path):
            try:
                self._classes = load_dictionary(dict_path)
                self._net = cv2.dnn.readNet(model_path)
            except (cv2.error, OSError, UnicodeDecodeError):
                self._net = None

    @property
    def is_ready(self) -> bool:
        return self._net is not None

    def _blob(self, line_bgr: np.ndarray) -> np.ndarray:
        h, w = line_bgr.shape[:2]
        width = int(min(self.MAX_WIDTH, max(8, round(self.HEIGHT * w / max(h, 1)))))
        resized = cv2.resize(line_bgr, (width, self.HEIGHT)).astype(np.float32)
        resized = (resized / 255.0 - 0.5) / 0.5
        padded = np.zeros((self.HEIGHT, self.MAX_WIDTH, 3), dtype=np.float32)
        padded[:, :width] = resized
        return padded.transpose(2, 0, 1)[None]

    def read(self, line_bgr: np.ndarray) -> TextLine:
        if self._net is None or line_bgr.size == 0:
            return TextLine("", 0.0)
        self._net.setInput(self._blob(line_bgr))
        probs = self._net.forward()
        return ctc_decode(probs, self._classes)
