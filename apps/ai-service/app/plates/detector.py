"""Plate detectors (VEH-008, ACR-VEH-01 D4).

LPD-YuNet from OpenCV Zoo (``license_plate_detection_lpd_yunet_2023mar.onnx``, Apache-2.0), run
through OpenCV DNN like the face models. The prior boxes and decoding follow the upstream
``lpd_yunet.py`` at the pinned opencv_zoo commit. The model was trained on Chinese plates; how
well it finds Thai plates is what the VEH-007 evaluation on real gate photos measures.

Pipeline images are RGB; the model was trained on BGR (OpenCV) images, so frames are converted.
"""

from __future__ import annotations

import os
from abc import ABC, abstractmethod
from itertools import product

import cv2
import numpy as np

from app.detection.schemas import BoundingBox
from app.detection.yunet import to_bgr
from app.plates.schemas import PlateDetection


class PlateDetector(ABC):
    @property
    @abstractmethod
    def is_ready(self) -> bool: ...

    @abstractmethod
    def detect(self, image: np.ndarray) -> list[PlateDetection]:
        """Plates in an RGB frame, largest first."""


class UnavailablePlateDetector(PlateDetector):
    """No model: finds nothing and says it is not ready (fail closed)."""

    @property
    def is_ready(self) -> bool:
        return False

    def detect(self, image: np.ndarray) -> list[PlateDetection]:
        return []


class MockPlateDetector(PlateDetector):
    """Returns the detections it was given (tests). Finds nothing by default, so a node in
    mock mode never invents plates."""

    def __init__(self, detections: list[PlateDetection] | None = None):
        self.detections = list(detections or [])

    @property
    def is_ready(self) -> bool:
        return True

    def detect(self, image: np.ndarray) -> list[PlateDetection]:
        return list(self.detections)


def detection_from_corners(
    corners: list[tuple[float, float]], score: float, width: int, height: int
) -> PlateDetection | None:
    """PlateDetection with the box around the corners, clamped to the frame; None if empty."""
    xs = [p[0] for p in corners]
    ys = [p[1] for p in corners]
    try:
        bbox = BoundingBox(
            x1=min(xs), y1=min(ys), x2=max(max(xs), min(xs) + 1e-3), y2=max(max(ys), min(ys) + 1e-3)
        ).clamp(width, height)
    except ValueError:
        return None
    if bbox is None:
        return None
    pts = tuple((float(x), float(y)) for x, y in corners)
    return PlateDetection(
        corners=pts,  # type: ignore[arg-type]
        bbox=bbox,
        confidence=float(min(max(score, 0.0), 1.0)),
    )


class LpdYuNetPlateDetector(PlateDetector):
    """Production plate detector backed by LPD-YuNet."""

    MIN_SIZES = [[10, 16, 24], [32, 48], [64, 96], [128, 192, 256]]
    STEPS = [8, 16, 32, 64]
    VARIANCE = (0.1, 0.2)

    def __init__(
        self,
        model_path: str | None,
        score_threshold: float = 0.8,
        nms_threshold: float = 0.3,
        top_k: int = 20,
        input_width: int = 640,
    ):
        self.model_path = model_path
        self.score_threshold = score_threshold
        self.nms_threshold = nms_threshold
        self.top_k = top_k
        self.input_width = input_width
        self._net = None
        self._priors: dict[tuple[int, int], np.ndarray] = {}
        if model_path and os.path.isfile(model_path):
            try:
                self._net = cv2.dnn.readNet(model_path)
            except cv2.error:
                self._net = None

    @property
    def is_ready(self) -> bool:
        return self._net is not None

    def detect(self, image: np.ndarray) -> list[PlateDetection]:
        if self._net is None:
            return []
        bgr = to_bgr(np.ascontiguousarray(image))
        if bgr.dtype != np.uint8:
            bgr = np.clip(bgr, 0, 255).astype(np.uint8)
        height, width = bgr.shape[:2]
        # Work at a fixed width (keeps CPU cost per frame bounded), sizes multiple of 32.
        scale = min(1.0, self.input_width / width)
        in_w = max(32, int(round(width * scale / 32)) * 32)
        in_h = max(32, int(round(height * scale / 32)) * 32)
        resized = cv2.resize(bgr, (in_w, in_h))

        self._net.setInput(cv2.dnn.blobFromImage(resized))
        loc, conf, iou = self._net.forward(["loc", "conf", "iou"])
        dets = self._decode(loc, conf, iou, in_w, in_h)
        if dets.shape[0] == 0:
            return []

        sx, sy = width / in_w, height / in_h
        dets[:, 0:8:2] *= sx
        dets[:, 1:8:2] *= sy

        boxes = []
        for row in dets:
            xs, ys = row[0:8:2], row[1:8:2]
            boxes.append([float(xs.min()), float(ys.min()), float(np.ptp(xs)), float(np.ptp(ys))])
        keep = cv2.dnn.NMSBoxes(
            boxes, dets[:, 8].tolist(), self.score_threshold, self.nms_threshold, top_k=self.top_k
        )
        results: list[PlateDetection] = []
        for idx in np.array(keep).reshape(-1):
            row = dets[int(idx)]
            corners = [(float(row[2 * i]), float(row[2 * i + 1])) for i in range(4)]
            det = detection_from_corners(corners, float(row[8]), width, height)
            if det is not None:
                results.append(det)
        results.sort(key=lambda d: d.bbox.area, reverse=True)
        return results

    def _priors_for(self, w: int, h: int) -> np.ndarray:
        key = (w, h)
        if key not in self._priors:
            fm2 = [int(int((h + 1) / 2) / 2), int(int((w + 1) / 2) / 2)]
            fm3 = [int(fm2[0] / 2), int(fm2[1] / 2)]
            fm4 = [int(fm3[0] / 2), int(fm3[1] / 2)]
            fm5 = [int(fm4[0] / 2), int(fm4[1] / 2)]
            fm6 = [int(fm5[0] / 2), int(fm5[1] / 2)]
            priors = []
            for k, f in enumerate([fm3, fm4, fm5, fm6]):
                for i, j in product(range(f[0]), range(f[1])):
                    for min_size in self.MIN_SIZES[k]:
                        priors.append(
                            [
                                (j + 0.5) * self.STEPS[k] / w,
                                (i + 0.5) * self.STEPS[k] / h,
                                min_size / w,
                                min_size / h,
                            ]
                        )
            self._priors[key] = np.array(priors, dtype=np.float32)
        return self._priors[key]

    def _decode(
        self, loc: np.ndarray, conf: np.ndarray, iou: np.ndarray, w: int, h: int
    ) -> np.ndarray:
        """Rows of 4 corners (x, y) * 4 + score, in input-image pixels, above the threshold."""
        priors = self._priors_for(w, h)
        loc = loc.reshape(-1, 14)
        conf = conf.reshape(-1, 2)
        iou = np.clip(iou.reshape(-1), 0.0, 1.0)
        scores = np.sqrt(conf[:, 1] * iou)
        mask = scores >= self.score_threshold
        if not mask.any():
            return np.empty((0, 9), dtype=np.float32)
        loc, priors, scores = loc[mask], priors[mask], scores[mask]
        scale = np.array([w, h], dtype=np.float32)
        v = self.VARIANCE[0]
        # Upstream corner order in loc: 4:6, 6:8, 10:12, 12:14.
        corners = [
            (priors[:, 0:2] + loc[:, a:b] * v * priors[:, 2:4]) * scale
            for a, b in ((4, 6), (6, 8), (10, 12), (12, 14))
        ]
        return np.hstack([*corners, scores[:, None]]).astype(np.float32)
