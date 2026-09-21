"""Mock face quality evaluator for testing and offline development (FACE-006)."""

import numpy as np

from app.alignment.schemas import AlignedFace
from app.detection.schemas import FaceDetection
from app.quality.base import FaceQualityEvaluator
from app.quality.schemas import FaceQualityResult, QualityRejectionReason, QualityThresholds


class MockFaceQualityEvaluator(FaceQualityEvaluator):
    """Deterministic mock quality evaluator with configurable outcomes."""

    def __init__(
        self,
        thresholds: QualityThresholds | None = None,
        force_accepted: bool = True,
        custom_result: FaceQualityResult | None = None,
        simulate_error: Exception | None = None,
        name: str = "mock_quality_evaluator",
        version: str = "1.0.0",
    ):
        self._thresholds = thresholds or QualityThresholds()
        self.force_accepted = force_accepted
        self.custom_result = custom_result
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
    def thresholds(self) -> QualityThresholds:
        return self._thresholds

    def evaluate(
        self,
        aligned_face: AlignedFace | np.ndarray,
        detection: FaceDetection | None = None,
    ) -> FaceQualityResult:
        """Validate input image and return deterministic mock quality assessment."""
        self.extract_and_validate_image(aligned_face, detection)

        if self.simulate_error is not None:
            raise self.simulate_error

        if self.custom_result is not None:
            return self.custom_result

        if self.force_accepted:
            return FaceQualityResult(
                quality_score=0.92,
                blur_score=0.95,
                brightness_score=0.90,
                face_size_score=0.90,
                pose_score=0.95,
                yaw=1.2,
                pitch=-0.8,
                roll=0.5,
                accepted=True,
                rejection_reasons=[],
                evaluator_name=self.name,
                evaluator_version=self.version,
                model_type=self.model_type,
            )

        return FaceQualityResult(
            quality_score=0.30,
            blur_score=0.20,
            brightness_score=0.40,
            face_size_score=0.30,
            pose_score=0.35,
            yaw=35.0,
            pitch=15.0,
            roll=5.0,
            accepted=False,
            rejection_reasons=[
                QualityRejectionReason.IMAGE_BLUR,
                QualityRejectionReason.BAD_POSE,
            ],
            evaluator_name=self.name,
            evaluator_version=self.version,
            model_type=self.model_type,
        )
