"""YuNet face detector (ACR-AI-01).

OpenCV Zoo ``face_detection_yunet_2023mar.onnx`` (MIT), run through OpenCV's
``cv2.FaceDetectorYN``. Pipeline images are RGB; YuNet expects BGR, so the image is
converted here. The five landmarks come out in the order the aligner's ArcFace template
uses: eye, eye, nose tip, mouth corner, mouth corner, image-left first.
"""

import os

import cv2
import numpy as np

from app.detection.base import FaceDetector
from app.detection.exceptions import DetectionRuntimeError, DetectorNotReadyError
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint


def to_bgr(image: np.ndarray) -> np.ndarray:
    """Convert a pipeline image (RGB, RGBA or grayscale) to 3-channel BGR."""
    if image.ndim == 2:
        return cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
    channels = image.shape[2]
    if channels == 1:
        return cv2.cvtColor(image[:, :, 0], cv2.COLOR_GRAY2BGR)
    if channels == 4:
        return cv2.cvtColor(image, cv2.COLOR_RGBA2BGR)
    return cv2.cvtColor(image, cv2.COLOR_RGB2BGR)


class YuNetFaceDetector(FaceDetector):
    """Production face detector backed by YuNet."""

    def __init__(
        self,
        model_path: str | None = None,
        score_threshold: float = 0.6,
        nms_threshold: float = 0.3,
        top_k: int = 50,
        name: str = "yunet",
        version: str = "2023mar",
    ):
        self._name = name
        self._version = version
        self.model_path = model_path
        self.score_threshold = score_threshold
        self._detector = None
        if model_path and os.path.isfile(model_path):
            try:
                self._detector = cv2.FaceDetectorYN.create(
                    model_path, "", (320, 320), score_threshold, nms_threshold, top_k
                )
            except cv2.error:
                self._detector = None

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def is_ready(self) -> bool:
        return self._detector is not None

    def detect(self, image: np.ndarray) -> list[FaceDetection]:
        self.validate_image(image)
        if self._detector is None:
            raise DetectorNotReadyError(
                f"YuNet face detector is not ready: model '{self.model_path}' is missing or invalid"
            )

        try:
            bgr = to_bgr(np.ascontiguousarray(image))
            if bgr.dtype != np.uint8:
                bgr = np.clip(bgr, 0, 255).astype(np.uint8)
            height, width = bgr.shape[:2]
            self._detector.setInputSize((width, height))
            _, faces = self._detector.detect(bgr)
        except cv2.error as exc:
            raise DetectionRuntimeError(f"YuNet inference error: {exc}") from exc

        if faces is None:
            return []

        detections: list[FaceDetection] = []
        for row in faces:
            x, y, w, h = (float(v) for v in row[:4])
            bbox = BoundingBox(x1=x, y1=y, x2=x + max(w, 1e-3), y2=y + max(h, 1e-3)).clamp(
                width, height
            )
            if bbox is None:
                continue
            landmarks = [
                LandmarkPoint(x=float(row[4 + 2 * i]), y=float(row[5 + 2 * i])) for i in range(5)
            ]
            detections.append(
                FaceDetection(
                    bbox=bbox,
                    confidence=float(min(max(row[14], 0.0), 1.0)),
                    landmarks=landmarks,
                    detector_name=self.name,
                    detector_version=self.version,
                )
            )
        detections.sort(key=lambda d: d.bbox.area, reverse=True)
        return detections
