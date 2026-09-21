"""Tests for face alignment abstraction and implementation (FACE-005)."""

import numpy as np
import pytest

from app.alignment import (
    AlignedFace,
    FaceAligner,
    FacialLandmarks5Point,
    InvalidLandmarksError,
    MockFaceAligner,
    StandardFaceAligner,
    get_face_aligner,
)
from app.detection import (
    AIModelType,
    BoundingBox,
    FaceDetection,
    LandmarkPoint,
    MockFaceDetector,
)


def create_sample_detection(
    landmarks: list[LandmarkPoint] | None = None,
    detector_name: str = "mock_detector",
    detector_version: str = "1.0.0",
) -> FaceDetection:
    """Helper to construct a valid test FaceDetection with canonical landmarks."""
    if landmarks is None:
        landmarks = [
            LandmarkPoint(x=100.0, y=100.0),  # Left eye
            LandmarkPoint(x=150.0, y=100.0),  # Right eye
            LandmarkPoint(x=125.0, y=130.0),  # Nose
            LandmarkPoint(x=105.0, y=160.0),  # Left mouth
            LandmarkPoint(x=145.0, y=160.0),  # Right mouth
        ]
    return FaceDetection(
        bbox=BoundingBox(x1=70.0, y1=60.0, x2=180.0, y2=190.0),
        confidence=0.98,
        landmarks=landmarks,
        detector_name=detector_name,
        detector_version=detector_version,
    )


class TestFaceAlignerInterface:
    """Test requirement 1: FaceAligner interface exists and validates properties."""

    def test_aligner_interface_and_properties(self) -> None:
        aligner = StandardFaceAligner()
        assert isinstance(aligner, FaceAligner)
        assert aligner.name == "standard_5pt_aligner"
        assert aligner.version == "1.0.0"
        assert aligner.target_size == (112, 112)
        assert aligner.output_format == "RGB"
        assert aligner.model_type == AIModelType.FACE_ALIGNMENT


class TestFaceAlignmentPipeline:
    """Tests for canonical alignment execution and output contracts."""

    def test_valid_detection_aligns_successfully(self) -> None:
        """Requirements 2, 3, 4, 14: Valid detected face + landmarks aligns successfully."""
        aligner = StandardFaceAligner(target_size=(112, 112))
        image = np.full((300, 300, 3), 128, dtype=np.uint8)
        detection = create_sample_detection()

        result = aligner.align(image, detection)

        assert isinstance(result, AlignedFace)
        assert result.width == 112
        assert result.height == 112
        assert result.channels == 3
        assert result.format == "RGB"
        assert result.image.shape == (112, 112, 3)
        assert result.image.dtype == np.uint8
        assert result.transform_matrix is not None
        assert len(result.transform_matrix) == 2
        assert len(result.transform_matrix[0]) == 3

        # Safe traceability metadata
        assert result.detector_name == "mock_detector"
        assert result.detector_version == "1.0.0"
        assert result.aligner_name == "standard_5pt_aligner"
        assert result.aligner_version == "1.0.0"
        assert result.model_type == AIModelType.FACE_ALIGNMENT

    def test_original_image_is_not_mutated(self) -> None:
        """Requirement 5: Original image array is not mutated."""
        aligner = StandardFaceAligner()
        image = np.random.randint(0, 256, (300, 300, 3), dtype=np.uint8)
        image_copy = image.copy()
        detection = create_sample_detection()

        aligner.align(image, detection)

        assert np.array_equal(image, image_copy)

    def test_deterministic_output(self) -> None:
        """Requirement 13: Same input produces deterministic output."""
        aligner = StandardFaceAligner()
        image = np.random.randint(0, 256, (240, 320, 3), dtype=np.uint8)
        detection = create_sample_detection()

        res1 = aligner.align(image, detection)
        res2 = aligner.align(image, detection)

        assert np.array_equal(res1.image, res2.image)
        assert res1.transform_matrix == res2.transform_matrix

    def test_grayscale_and_rgba_sources_supported(self) -> None:
        """Grayscale (2D) and RGBA (4-channel) images align into 3-channel RGB output."""
        aligner = StandardFaceAligner()
        detection = create_sample_detection()

        # 2D Grayscale
        gray_img = np.full((300, 300), 100, dtype=np.uint8)
        res_gray = aligner.align(gray_img, detection)
        assert res_gray.channels == 3
        assert res_gray.image.shape == (112, 112, 3)

        # 4-channel RGBA
        rgba_img = np.full((300, 300, 4), 200, dtype=np.uint8)
        res_rgba = aligner.align(rgba_img, detection)
        assert res_rgba.channels == 3
        assert res_rgba.image.shape == (112, 112, 3)


class TestLandmarkValidationAndInvariants:
    """Tests for landmark schemas, counts, coordinates, and degenerate geometry."""

    def test_five_point_landmark_schema_and_mapping(self) -> None:
        """Requirement 6: 5-point landmark mapping/order validated."""
        pts = [
            LandmarkPoint(x=10.0, y=20.0),
            LandmarkPoint(x=50.0, y=20.0),
            LandmarkPoint(x=30.0, y=40.0),
            LandmarkPoint(x=15.0, y=60.0),
            LandmarkPoint(x=45.0, y=60.0),
        ]
        landmarks_5pt = FacialLandmarks5Point.from_list(pts)
        assert landmarks_5pt.left_eye.x == 10.0
        assert landmarks_5pt.right_eye.x == 50.0
        assert landmarks_5pt.nose.x == 30.0
        assert landmarks_5pt.left_mouth.x == 15.0
        assert landmarks_5pt.right_mouth.x == 45.0
        assert landmarks_5pt.to_numpy().shape == (5, 2)

    def test_missing_landmarks_rejected(self) -> None:
        """Requirement 7: Missing landmarks rejected."""
        aligner = StandardFaceAligner()
        image = np.zeros((300, 300, 3), dtype=np.uint8)
        detection = FaceDetection(
            bbox=BoundingBox(x1=10, y1=10, x2=50, y2=50),
            confidence=0.9,
            landmarks=None,
            detector_name="test",
            detector_version="1.0",
        )
        with pytest.raises(InvalidLandmarksError, match="does not contain facial landmarks"):
            aligner.align(image, detection)

    def test_wrong_landmark_count_rejected(self) -> None:
        """Requirement 8: Wrong landmark count rejected."""
        aligner = StandardFaceAligner()
        image = np.zeros((300, 300, 3), dtype=np.uint8)

        # 2 landmarks
        det_2 = create_sample_detection(
            landmarks=[LandmarkPoint(x=10, y=10), LandmarkPoint(x=20, y=20)]
        )
        with pytest.raises(InvalidLandmarksError, match="requires exactly 5 landmarks"):
            aligner.align(image, det_2)

        # 6 landmarks
        det_6 = create_sample_detection(
            landmarks=[
                LandmarkPoint(x=10, y=10),
                LandmarkPoint(x=20, y=20),
                LandmarkPoint(x=30, y=30),
                LandmarkPoint(x=40, y=40),
                LandmarkPoint(x=50, y=50),
                LandmarkPoint(x=60, y=60),
            ]
        )
        with pytest.raises(InvalidLandmarksError, match="requires exactly 5 landmarks"):
            aligner.align(image, det_6)

    def test_nan_and_infinite_landmarks_rejected(self) -> None:
        """Requirements 9, 10: NaN and infinite landmarks rejected."""
        # NaN is rejected by LandmarkPoint schema
        with pytest.raises(ValueError):
            LandmarkPoint(x=float("nan"), y=20.0)

        with pytest.raises(ValueError):
            LandmarkPoint(x=10.0, y=float("inf"))

    def test_degenerate_and_collapsed_geometry_rejected(self) -> None:
        """Requirement 11: Degenerate/collapsed landmark geometry rejected."""
        aligner = StandardFaceAligner()
        image = np.zeros((300, 300, 3), dtype=np.uint8)

        # All points identical
        det_collapsed = create_sample_detection(
            landmarks=[
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=100.0),
            ]
        )
        with pytest.raises(InvalidLandmarksError, match="Degenerate facial geometry"):
            aligner.align(image, det_collapsed)

        # Left eye and right eye coincident
        det_coincident_eyes = create_sample_detection(
            landmarks=[
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=100.0),
                LandmarkPoint(x=100.0, y=130.0),
                LandmarkPoint(x=90.0, y=150.0),
                LandmarkPoint(x=110.0, y=150.0),
            ]
        )
        with pytest.raises(InvalidLandmarksError, match="inter-ocular eye distance"):
            aligner.align(image, det_coincident_eyes)

    def test_boundary_handling_does_not_crash(self) -> None:
        """Requirement 12: Out-of-bound or edge faces handled gracefully without crash."""
        aligner = StandardFaceAligner()
        image = np.zeros((100, 100, 3), dtype=np.uint8)

        # Landmarks located partially outside or right on image boundary
        det_edge = create_sample_detection(
            landmarks=[
                LandmarkPoint(x=-10.0, y=-5.0),
                LandmarkPoint(x=30.0, y=-5.0),
                LandmarkPoint(x=10.0, y=20.0),
                LandmarkPoint(x=-5.0, y=40.0),
                LandmarkPoint(x=25.0, y=40.0),
            ]
        )
        res = aligner.align(image, det_edge)
        assert res.image.shape == (112, 112, 3)
        assert res.width == 112
        assert res.height == 112


class TestAlignerFactoryAndMock:
    """Tests for factory resolution and mock aligner."""

    def test_factory_resolves_standard(self) -> None:
        aligner = get_face_aligner("standard")
        assert isinstance(aligner, StandardFaceAligner)

    def test_factory_resolves_mock(self) -> None:
        aligner = get_face_aligner("mock")
        assert isinstance(aligner, MockFaceAligner)

    def test_factory_rejects_unsupported_provider(self) -> None:
        with pytest.raises(ValueError, match="Unsupported face aligner provider"):
            get_face_aligner("unsupported_aligner")

    def test_mock_aligner_aligns_synthetically(self) -> None:
        aligner = MockFaceAligner(target_size=(112, 112))
        image = np.zeros((200, 200, 3), dtype=np.uint8)
        detection = create_sample_detection()
        res = aligner.align(image, detection)

        assert res.width == 112
        assert res.height == 112
        assert res.image.shape == (112, 112, 3)
        assert res.aligner_name == "mock_face_aligner"


class TestDetectorToAlignerPipelineIntegration:
    """Integration test: FACE-004 MockFaceDetector -> FACE-005 StandardFaceAligner."""

    def test_detector_output_flows_directly_into_aligner(self) -> None:
        detector = MockFaceDetector(default_num_faces=1)
        aligner = StandardFaceAligner()

        image = np.zeros((480, 640, 3), dtype=np.uint8)
        detections = detector.detect(image)

        assert len(detections) == 1
        aligned_face = aligner.align(image, detections[0])

        assert aligned_face.width == 112
        assert aligned_face.height == 112
        assert aligned_face.detector_name == "mock_detector"
        assert aligned_face.aligner_name == "standard_5pt_aligner"


class TestStaticArchitectureAndScopeBoundaries:
    """Requirements 15-21: Static checks ensuring zero quality/embedding/identity/persistence."""

    def test_aligned_face_result_contracts_purity(self) -> None:
        aligner = StandardFaceAligner()
        image = np.zeros((300, 300, 3), dtype=np.uint8)
        detection = create_sample_detection()
        res = aligner.align(image, detection)

        # Verify prohibited fields are absent
        res_dict = res.model_dump()
        assert "quality_score" not in res_dict
        assert "blur_score" not in res_dict
        assert "brightness_score" not in res_dict
        assert "embedding" not in res_dict
        assert "vector" not in res_dict
        assert "person_id" not in res_dict
        assert "student_id" not in res_dict
        assert "match_status" not in res_dict
        assert "access_decision" not in res_dict

    def test_no_quality_or_embedding_modules_in_alignment(self) -> None:
        import app.alignment as align_mod

        exported = dir(align_mod)
        assert "QualityEvaluator" not in exported
        assert "ArcFace" not in exported
        assert "EmbeddingGenerator" not in exported
        assert "FaceEmbedding" not in exported
        assert "RecognitionClassifier" not in exported
