"""Mock face aligner for testing and offline development (FACE-005)."""

import numpy as np

from app.alignment.base import FaceAligner
from app.alignment.schemas import AlignedFace
from app.detection.schemas import FaceDetection


class MockFaceAligner(FaceAligner):
    """Deterministic mock face aligner returning synthetic normalized face crops."""

    def __init__(
        self,
        target_size: tuple[int, int] = (112, 112),
        output_format: str = "RGB",
        simulate_error: Exception | None = None,
        name: str = "mock_face_aligner",
        version: str = "1.0.0",
    ):
        self._target_size = target_size
        self._output_format = output_format
        self.simulate_error = simulate_error
        self._name = name
        self._version = version

    @property
    def name(self) -> str:
        return self._name

    @property
    def version(self) -> str:
        return self._version

    @property
    def target_size(self) -> tuple[int, int]:
        return self._target_size

    @property
    def output_format(self) -> str:
        return self._output_format

    def align(self, image: np.ndarray, detection: FaceDetection) -> AlignedFace:
        """Validate input image and detection and return synthetic aligned face."""
        self.validate_inputs(image, detection)

        if self.simulate_error is not None:
            raise self.simulate_error

        tw, th = self.target_size
        # Generate deterministic synthetic RGB face array
        synthetic_img = np.zeros((th, tw, 3), dtype=np.uint8)
        # Create a simple deterministic pattern based on target dimensions
        synthetic_img[10:30, 20:40, 0] = 200  # synthetic left eye area
        synthetic_img[10:30, 72:92, 0] = 200  # synthetic right eye area
        synthetic_img[45:65, 46:66, 1] = 220  # synthetic nose area
        synthetic_img[80:95, 30:82, 2] = 240  # synthetic mouth area

        synthetic_matrix = [
            [1.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
        ]

        return AlignedFace(
            image=synthetic_img,
            width=tw,
            height=th,
            channels=3,
            format=self.output_format,
            transform_matrix=synthetic_matrix,
            detector_name=detection.detector_name,
            detector_version=detection.detector_version,
            aligner_name=self.name,
            aligner_version=self.version,
            model_type=self.model_type,
        )
