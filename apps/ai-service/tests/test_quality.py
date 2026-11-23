"""Tests for face quality evaluation abstraction and implementation (FACE-006)."""

import numpy as np
import pytest
from pydantic import ValidationError

from app.alignment import AlignedFace, StandardFaceAligner
from app.detection import (
    AIModelType,
    BoundingBox,
    FaceDetection,
    LandmarkPoint,
    MockFaceDetector,
)
from app.quality import (
    FaceQualityEvaluator,
    FaceQualityResult,
    InvalidQualityInputError,
    MockFaceQualityEvaluator,
    QualityRejectionReason,
    QualityThresholds,
    StandardFaceQualityEvaluator,
    get_face_quality_evaluator,
)


def create_sample_aligned_face(
    image: np.ndarray | None = None,
    width: int = 112,
    height: int = 112,
) -> AlignedFace:
    """Helper to construct a valid AlignedFace instance."""
    if image is None:
        # Construct synthetic high-contrast face pattern (sharp, well-lit)
        image = np.full((height, width, 3), 128, dtype=np.uint8)
        # Add high-frequency textures for sharpness
        image[20:40, 20:40] = 50
        image[20:40, 70:90] = 50
        image[50:70, 45:65] = 200
        image[80:95, 30:80] = 30
        image[::4, ::4] = 220
        image[1::4, 1::4] = 40

    return AlignedFace(
        image=image,
        width=width,
        height=height,
        channels=3,
        format="RGB",
        detector_name="test_detector",
        detector_version="1.0.0",
        aligner_name="test_aligner",
        aligner_version="1.0.0",
        model_type=AIModelType.FACE_ALIGNMENT,
    )


def create_sample_detection(
    bbox: BoundingBox | None = None,
    landmarks: list[LandmarkPoint] | None = None,
) -> FaceDetection:
    """Helper to construct a valid FaceDetection."""
    if bbox is None:
        bbox = BoundingBox(x1=100.0, y1=100.0, x2=250.0, y2=280.0)
    if landmarks is None:
        landmarks = [
            LandmarkPoint(x=140.0, y=150.0),  # Left eye
            LandmarkPoint(x=210.0, y=150.0),  # Right eye
            LandmarkPoint(x=175.0, y=190.0),  # Nose
            LandmarkPoint(x=150.0, y=240.0),  # Left mouth
            LandmarkPoint(x=200.0, y=240.0),  # Right mouth
        ]
    return FaceDetection(
        bbox=bbox,
        confidence=0.98,
        landmarks=landmarks,
        detector_name="test_detector",
        detector_version="1.0.0",
    )


class TestFaceQualityEvaluatorInterface:
    """Requirement 1: FaceQualityEvaluator interface exists and validates properties."""

    def test_evaluator_interface_and_properties(self) -> None:
        evaluator = StandardFaceQualityEvaluator()
        assert isinstance(evaluator, FaceQualityEvaluator)
        assert evaluator.name == "standard_quality_evaluator"
        assert evaluator.version == "1.0.0"
        assert evaluator.model_type == AIModelType.FACE_QUALITY
        assert isinstance(evaluator.thresholds, QualityThresholds)


class TestFaceQualityEvaluationContracts:
    """Requirements 2-6: Structured result contracts, score ranges, finite values."""

    def test_valid_aligned_face_produces_structured_result(self) -> None:
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()
        detection = create_sample_detection()

        result = evaluator.evaluate(aligned, detection)

        assert isinstance(result, FaceQualityResult)
        assert 0.0 <= result.quality_score <= 1.0
        assert 0.0 <= result.blur_score <= 1.0
        assert 0.0 <= result.brightness_score <= 1.0
        assert 0.0 <= result.face_size_score <= 1.0
        assert 0.0 <= result.pose_score <= 1.0
        assert isinstance(result.accepted, bool)
        assert isinstance(result.rejection_reasons, list)
        assert result.evaluator_name == "standard_quality_evaluator"
        assert result.evaluator_version == "1.0.0"
        assert result.model_type == AIModelType.FACE_QUALITY

    def test_score_ranges_and_non_finite_validation(self) -> None:
        # Out-of-bounds score in schema is rejected
        with pytest.raises(ValidationError):
            FaceQualityResult(
                quality_score=1.5,  # > 1.0
                blur_score=0.9,
                brightness_score=0.9,
                face_size_score=0.9,
                pose_score=0.9,
                accepted=True,
                evaluator_name="test",
                evaluator_version="1.0",
            )

        with pytest.raises(ValidationError):
            FaceQualityResult(
                quality_score=float("nan"),
                blur_score=0.9,
                brightness_score=0.9,
                face_size_score=0.9,
                pose_score=0.9,
                accepted=True,
                evaluator_name="test",
                evaluator_version="1.0",
            )


class TestTechnicalQualityCriteria:
    """Requirements 7-13: Pass/fail evaluation across blur, brightness, face size, pose."""

    def test_good_quality_sample_accepted(self) -> None:
        """Requirement 7: Good quality sample accepted with zero rejection reasons."""
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()
        detection = create_sample_detection()

        result = evaluator.evaluate(aligned, detection)
        assert result.accepted is True
        assert len(result.rejection_reasons) == 0
        assert result.quality_score >= evaluator.thresholds.min_quality_score

    def test_blurry_sample_rejected(self) -> None:
        """Requirement 8: Blurry / flat sample rejected with IMAGE_BLUR."""
        evaluator = StandardFaceQualityEvaluator()
        # Completely uniform flat image -> zero Laplacian variance
        flat_img = np.full((112, 112, 3), 128, dtype=np.uint8)
        aligned = create_sample_aligned_face(image=flat_img)

        result = evaluator.evaluate(aligned)
        assert result.accepted is False
        assert QualityRejectionReason.IMAGE_BLUR in result.rejection_reasons
        assert result.blur_score < evaluator.thresholds.min_blur_score

    def test_dark_sample_rejected(self) -> None:
        """Requirement 9: Underexposed / dark sample rejected with IMAGE_TOO_DARK."""
        evaluator = StandardFaceQualityEvaluator()
        # Very dark image
        dark_img = np.full((112, 112, 3), 10, dtype=np.uint8)
        aligned = create_sample_aligned_face(image=dark_img)

        result = evaluator.evaluate(aligned)
        assert result.accepted is False
        assert QualityRejectionReason.IMAGE_TOO_DARK in result.rejection_reasons

    def test_overbright_sample_rejected(self) -> None:
        """Requirement 10: Overexposed sample rejected with IMAGE_TOO_BRIGHT."""
        evaluator = StandardFaceQualityEvaluator()
        # Extremely bright washed-out image
        bright_img = np.full((112, 112, 3), 250, dtype=np.uint8)
        aligned = create_sample_aligned_face(image=bright_img)

        result = evaluator.evaluate(aligned)
        assert result.accepted is False
        assert QualityRejectionReason.IMAGE_TOO_BRIGHT in result.rejection_reasons

    def test_small_face_sample_rejected(self) -> None:
        """Requirement 11: Small face bounding box rejected with FACE_TOO_SMALL."""
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()
        # Tiny detection box (10x10 px)
        tiny_detection = FaceDetection(
            bbox=BoundingBox(x1=10.0, y1=10.0, x2=20.0, y2=20.0),
            confidence=0.9,
            landmarks=None,
            detector_name="test",
            detector_version="1.0",
        )

        result = evaluator.evaluate(aligned, tiny_detection)
        assert result.accepted is False
        assert QualityRejectionReason.FACE_TOO_SMALL in result.rejection_reasons

    def test_excessive_pose_rejected(self) -> None:
        """Requirement 12: High yaw / pitch pose rejected with BAD_POSE."""
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()

        # Extreme yaw landmark configuration (nose shifted far to the left eye)
        extreme_yaw_landmarks = [
            LandmarkPoint(x=100.0, y=100.0),  # Left eye
            LandmarkPoint(x=200.0, y=100.0),  # Right eye
            LandmarkPoint(x=105.0, y=150.0),  # Nose right next to left eye (extreme yaw)
            LandmarkPoint(x=110.0, y=180.0),  # Left mouth
            LandmarkPoint(x=190.0, y=180.0),  # Right mouth
        ]
        detection_bad_pose = create_sample_detection(landmarks=extreme_yaw_landmarks)

        result = evaluator.evaluate(aligned, detection_bad_pose)
        assert result.accepted is False
        assert QualityRejectionReason.BAD_POSE in result.rejection_reasons
        assert result.yaw is not None
        assert abs(result.yaw) > evaluator.thresholds.max_yaw_degrees

    def test_multiple_simultaneous_failures_produce_multiple_reasons(self) -> None:
        """Requirement 13: Multiple simultaneous flaws produce multiple rejection reasons."""
        evaluator = StandardFaceQualityEvaluator()
        # Blurry + dark
        dark_flat_img = np.full((112, 112, 3), 5, dtype=np.uint8)
        aligned = create_sample_aligned_face(image=dark_flat_img)

        result = evaluator.evaluate(aligned)
        assert result.accepted is False
        assert QualityRejectionReason.IMAGE_BLUR in result.rejection_reasons
        assert QualityRejectionReason.IMAGE_TOO_DARK in result.rejection_reasons


class TestDeterminismAndNonMutation:
    """Requirements 14-17: Deterministic execution, non-mutation, invalid input validation."""

    def test_deterministic_same_input_output(self) -> None:
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()
        detection = create_sample_detection()

        res1 = evaluator.evaluate(aligned, detection)
        res2 = evaluator.evaluate(aligned, detection)

        assert res1.model_dump() == res2.model_dump()

    def test_original_image_is_not_mutated(self) -> None:
        evaluator = StandardFaceQualityEvaluator()
        raw_img = np.random.randint(0, 256, (112, 112, 3), dtype=np.uint8)
        raw_img_copy = raw_img.copy()

        evaluator.evaluate(raw_img)
        assert np.array_equal(raw_img, raw_img_copy)

    def test_invalid_input_rejected(self) -> None:
        evaluator = StandardFaceQualityEvaluator()

        with pytest.raises(InvalidQualityInputError, match="cannot be None"):
            evaluator.evaluate(None)  # type: ignore

        with pytest.raises(InvalidQualityInputError, match="empty"):
            evaluator.evaluate(np.array([]))

        with pytest.raises(InvalidQualityInputError, match="empty"):
            evaluator.evaluate(np.zeros((0, 100, 3), dtype=np.uint8))

        with pytest.raises(InvalidQualityInputError, match="NaN or Inf"):
            nan_img = np.full((10, 10, 3), float("nan"), dtype=np.float32)
            evaluator.evaluate(nan_img)


class TestConfigurableThresholdsAndFactory:
    """Requirements 18, 26: Threshold configuration and factory resolution."""

    def test_custom_thresholds_override_defaults(self) -> None:
        custom_thresholds = QualityThresholds(
            min_blur_score=0.9999,  # Ultra strict blur
            min_quality_score=0.9999,
        )
        evaluator = StandardFaceQualityEvaluator(thresholds=custom_thresholds)
        aligned = create_sample_aligned_face()

        result = evaluator.evaluate(aligned)
        # Even sharp image should fail ultra-strict 0.9999 threshold
        assert result.accepted is False
        assert QualityRejectionReason.IMAGE_BLUR in result.rejection_reasons

    def test_factory_resolves_standard(self) -> None:
        evaluator = get_face_quality_evaluator("standard")
        assert isinstance(evaluator, StandardFaceQualityEvaluator)

    def test_factory_resolves_mock(self) -> None:
        evaluator = get_face_quality_evaluator("mock")
        assert isinstance(evaluator, MockFaceQualityEvaluator)

    def test_factory_rejects_unsupported(self) -> None:
        with pytest.raises(ValueError, match="Unsupported face quality evaluator provider"):
            get_face_quality_evaluator("unknown_provider")

    def test_mock_evaluator_outcomes(self) -> None:
        mock_pass = MockFaceQualityEvaluator(force_accepted=True)
        res_pass = mock_pass.evaluate(np.zeros((100, 100, 3), dtype=np.uint8))
        assert res_pass.accepted is True
        assert len(res_pass.rejection_reasons) == 0

        mock_fail = MockFaceQualityEvaluator(force_accepted=False)
        res_fail = mock_fail.evaluate(np.zeros((100, 100, 3), dtype=np.uint8))
        assert res_fail.accepted is False
        assert len(res_fail.rejection_reasons) > 0


class TestPipelineFlowDetectionToAlignmentToQuality:
    """Integration test: FACE-004 Detector -> FACE-005 Aligner -> FACE-006 Quality."""

    def test_end_to_end_pipeline_flow(self) -> None:
        detector = MockFaceDetector(default_num_faces=1)
        aligner = StandardFaceAligner()
        evaluator = StandardFaceQualityEvaluator()

        source_frame = np.full((480, 640, 3), 128, dtype=np.uint8)
        # Add facial texture pattern in the frame
        source_frame[100:300, 200:400] = 60
        source_frame[150:200, 250:350] = 220

        # Step 1: Detect
        detections = detector.detect(source_frame)
        assert len(detections) == 1
        detection = detections[0]

        # Step 2: Align
        aligned = aligner.align(source_frame, detection)
        assert aligned.width == 112
        assert aligned.height == 112

        # Step 3: Evaluate Quality
        quality = evaluator.evaluate(aligned, detection)
        assert isinstance(quality, FaceQualityResult)
        assert 0.0 <= quality.quality_score <= 1.0
        assert quality.model_type == AIModelType.FACE_QUALITY


class TestStaticArchitectureAndScopeBoundaries:
    """Requirements 20-25: Static checks ensuring zero embedding/recognition/identity/access."""

    def test_quality_result_contains_no_identity_or_embedding(self) -> None:
        evaluator = StandardFaceQualityEvaluator()
        aligned = create_sample_aligned_face()
        res = evaluator.evaluate(aligned)

        res_dict = res.model_dump()
        assert "embedding" not in res_dict
        assert "vector" not in res_dict
        assert "person_id" not in res_dict
        assert "student_id" not in res_dict
        assert "similarity" not in res_dict
        assert "match" not in res_dict
        assert "access_decision" not in res_dict
        assert "door_action" not in res_dict

    def test_no_embedding_or_recognition_code_in_quality_module(self) -> None:
        import app.quality as quality_mod

        exported = dir(quality_mod)
        assert "ArcFace" not in exported
        assert "EmbeddingGenerator" not in exported
        assert "FaceEmbedding" not in exported
        assert "RecognitionClassifier" not in exported
        assert "PolicyEngine" not in exported


def test_luminance_limits_come_from_thresholds():
    """CAM-022: the dark/bright limits are configurable instead of fixed at 0.20/0.85."""
    import numpy as np

    from app.quality.schemas import QualityRejectionReason, QualityThresholds
    from app.quality.standard import StandardFaceQualityEvaluator

    dim = np.full((112, 112, 3), 38, dtype=np.uint8)  # mean luminance ~0.15
    strict = StandardFaceQualityEvaluator().evaluate(dim)
    assert QualityRejectionReason.IMAGE_TOO_DARK in strict.rejection_reasons

    evaluator = StandardFaceQualityEvaluator()
    evaluator.set_thresholds(QualityThresholds(min_luminance=0.10))
    relaxed = evaluator.evaluate(dim)
    assert QualityRejectionReason.IMAGE_TOO_DARK not in relaxed.rejection_reasons
