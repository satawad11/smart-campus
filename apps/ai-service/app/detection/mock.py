"""Mock face detector provider for testing and offline development (FACE-004)."""

import numpy as np

from app.detection.base import FaceDetector
from app.detection.schemas import BoundingBox, FaceDetection, LandmarkPoint


class MockFaceDetector(FaceDetector):
    """Deterministic mock detector returning synthetic face detections."""

    def __init__(
        self,
        default_num_faces: int = 1,
        default_confidence: float = 0.98,
        custom_detections: list[FaceDetection] | None = None,
        simulate_error: Exception | None = None,
        name: str = "mock_detector",
        version: str = "1.0.0",
    ):
        self._name = name
        self._version = version
        self.default_num_faces = default_num_faces
        self.default_confidence = default_confidence
        self.custom_detections = custom_detections
        self.simulate_error = simulate_error

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    def detect(self, image: np.ndarray) -> list[FaceDetection]:
        """Validate input image and return deterministic face detections."""
        self.validate_image(image)

        if self.simulate_error is not None:
            raise self.simulate_error

        if self.custom_detections is not None:
            return self.custom_detections

        if self.default_num_faces <= 0:
            return []

        h, w = image.shape[:2]
        detections: list[FaceDetection] = []

        for i in range(self.default_num_faces):
            # Generate deterministic proportional coordinates across the frame
            step = 1.0 / (self.default_num_faces + 1)
            cx = w * step * (i + 1)
            cy = h * 0.5
            box_w = min(w * 0.3, 150.0)
            box_h = min(h * 0.4, 200.0)

            x1 = max(0.0, cx - box_w / 2)
            y1 = max(0.0, cy - box_h / 2)
            x2 = min(float(w), cx + box_w / 2)
            y2 = min(float(h), cy + box_h / 2)

            landmarks = [
                LandmarkPoint(x=x1 + (x2 - x1) * 0.3, y=y1 + (y2 - y1) * 0.35),  # Left eye
                LandmarkPoint(x=x1 + (x2 - x1) * 0.7, y=y1 + (y2 - y1) * 0.35),  # Right eye
                LandmarkPoint(x=x1 + (x2 - x1) * 0.5, y=y1 + (y2 - y1) * 0.55),  # Nose
                LandmarkPoint(x=x1 + (x2 - x1) * 0.35, y=y1 + (y2 - y1) * 0.75),  # Left mouth
                LandmarkPoint(x=x1 + (x2 - x1) * 0.65, y=y1 + (y2 - y1) * 0.75),  # Right mouth
            ]

            detection = FaceDetection(
                bbox=BoundingBox(x1=x1, y1=y1, x2=x2, y2=y2),
                confidence=float(self.default_confidence),
                landmarks=landmarks,
                detector_name=self.name,
                detector_version=self.version,
            )
            detections.append(detection)

        return detections
