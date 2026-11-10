"""MiniFASNet presentation attack detection (ACR-AI-01).

Two models from Silent-Face-Anti-Spoofing (Minivision, Apache-2.0), exported to ONNX by
scripts/fetch-face-models.sh and run through OpenCV DNN. Preprocessing follows the
upstream ``test.py`` exactly: crop around the face box at the model's scale (2.7 and 4.0),
resize to 80x80, BGR, CHW float without /255, softmax over three classes
(0 = print attack, 1 = real, 2 = replay attack), and average the two models.

The status uses the FACE-021 liveness thresholds (REAL >= 0.70, SPOOF < 0.40, otherwise
UNCERTAIN). Only REAL counts as live, so an uncertain face is rejected (fail closed).
"""

import os
from dataclasses import dataclass

import cv2
import numpy as np

from app.liveness.base import FaceLivenessDetector, LivenessResult
from app.liveness.unavailable import LivenessModelUnavailableError

REAL_CLASS = 1


@dataclass(frozen=True)
class PadModelSpec:
    filename: str
    scale: float
    width: int = 80
    height: int = 80


DEFAULT_MODELS = (
    PadModelSpec("minifasnet_v2_2.7_80x80.onnx", 2.7),
    PadModelSpec("minifasnet_v1se_4.0_80x80.onnx", 4.0),
)


def crop_box(
    src_w: int, src_h: int, bbox_xywh: tuple[float, float, float, float], scale: float
) -> tuple[int, int, int, int]:
    """Port of upstream ``CropImage._get_new_box``: scaled box, shifted inside the image."""
    x, y, box_w, box_h = bbox_xywh
    scale = min((src_h - 1) / box_h, min((src_w - 1) / box_w, scale))
    new_w, new_h = box_w * scale, box_h * scale
    center_x, center_y = box_w / 2 + x, box_h / 2 + y
    left, top = center_x - new_w / 2, center_y - new_h / 2
    right, bottom = center_x + new_w / 2, center_y + new_h / 2
    if left < 0:
        right -= left
        left = 0
    if top < 0:
        bottom -= top
        top = 0
    if right > src_w - 1:
        left -= right - src_w + 1
        right = src_w - 1
    if bottom > src_h - 1:
        top -= bottom - src_h + 1
        bottom = src_h - 1
    return int(left), int(top), int(right), int(bottom)


def _softmax(logits: np.ndarray) -> np.ndarray:
    shifted = np.exp(logits - logits.max(axis=1, keepdims=True))
    return shifted / shifted.sum(axis=1, keepdims=True)


class MiniFASNetLivenessDetector(FaceLivenessDetector):
    """Production PAD provider backed by MiniFASNet V2 + V1SE."""

    def __init__(
        self,
        model_dir: str | None = None,
        threshold_real: float = 0.70,
        threshold_spoof: float = 0.40,
        models: tuple[PadModelSpec, ...] = DEFAULT_MODELS,
    ):
        self.model_dir = model_dir
        self.threshold_real = threshold_real
        self.threshold_spoof = threshold_spoof
        self._nets: list[tuple[PadModelSpec, cv2.dnn.Net]] = []
        if model_dir:
            try:
                for spec in models:
                    path = os.path.join(model_dir, spec.filename)
                    if not os.path.isfile(path):
                        raise FileNotFoundError(path)
                    self._nets.append((spec, cv2.dnn.readNetFromONNX(path)))
            except (FileNotFoundError, cv2.error):
                self._nets = []

    @property
    def is_ready(self) -> bool:
        return bool(self._nets)

    def evaluate(
        self,
        image: np.ndarray,
        bounding_box: tuple[float, float, float, float] | None = None,
    ) -> LivenessResult:
        """Score one face. ``bounding_box`` is (x1, y1, x2, y2) in image pixels."""
        if not self._nets:
            raise LivenessModelUnavailableError(
                f"MiniFASNet models are missing or invalid in '{self.model_dir}'"
            )
        if image is None or not isinstance(image, np.ndarray) or image.size == 0:
            return self._rejected("INVALID_IMAGE")
        if bounding_box is None:
            return self._rejected("FACE_BOX_REQUIRED")

        x1, y1, x2, y2 = (float(v) for v in bounding_box)
        box_w, box_h = x2 - x1 + 1, y2 - y1 + 1
        if box_w <= 1 or box_h <= 1:
            return self._rejected("INVALID_FACE_BOX")

        if image.ndim == 2:
            bgr = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
        elif image.shape[2] == 4:
            bgr = cv2.cvtColor(image, cv2.COLOR_RGBA2BGR)
        else:
            bgr = cv2.cvtColor(image, cv2.COLOR_RGB2BGR)
        src_h, src_w = bgr.shape[:2]

        total = np.zeros(3, dtype=np.float64)
        per_model: dict[str, float] = {}
        for spec, net in self._nets:
            left, top, right, bottom = crop_box(src_w, src_h, (x1, y1, box_w, box_h), spec.scale)
            crop = bgr[top : bottom + 1, left : right + 1]
            if crop.size == 0:
                return self._rejected("INVALID_FACE_BOX")
            crop = cv2.resize(crop, (spec.width, spec.height))
            blob = crop.transpose(2, 0, 1)[np.newaxis].astype(np.float32)
            net.setInput(blob)
            probs = _softmax(np.asarray(net.forward(), dtype=np.float64).reshape(1, -1))[0]
            total += probs
            per_model[spec.filename] = round(float(probs[REAL_CLASS]), 4)

        averaged = total / len(self._nets)
        score = float(averaged[REAL_CLASS])
        if score >= self.threshold_real:
            status = "REAL"
        elif score < self.threshold_spoof:
            status = "SPOOF"
        else:
            status = "UNCERTAIN"
        return LivenessResult(
            score=score,
            is_live=status == "REAL",
            method="PASSIVE_TEXTURE",
            status=status,
            rejection_reasons=[] if status == "REAL" else ["PRESENTATION_ATTACK_SUSPECTED"],
            details={
                "model": "minifasnet-v2+v1se",
                "real_score_per_model": per_model,
                "print_attack": round(float(averaged[0]), 4),
                "replay_attack": round(float(averaged[2]), 4),
            },
        )

    def _rejected(self, reason: str) -> LivenessResult:
        return LivenessResult(
            score=0.0,
            is_live=False,
            method="PASSIVE_TEXTURE",
            status="UNCERTAIN",
            rejection_reasons=[reason],
        )
