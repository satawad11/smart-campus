"""Tests for face detection abstraction (FACE-004)."""

import numpy as np
import pytest
from pydantic import ValidationError

from app.detection import (
    AIModelType,
    BoundingBox,
    DetectorError,
    DetectorNotReadyError,
    FaceDetection,
    FaceDetector,
    InvalidImageError,
    LandmarkPoint,
    MockFaceDetector,
    ONNXFaceDetector,
    get_face_detector,
)
from app.detection.yunet import YuNetFaceDetector


class TestBoundingBoxAndLandmarks:
    """Unit tests for geometric data contracts and invariants."""

    def test_normal_positive_area_box_accepted(self) -> None:
        """Requirement 5: normal positive-area box accepted."""
        bbox = BoundingBox(x1=10.0, y1=20.0, x2=110.0, y2=170.0)
        assert bbox.x1 == 10.0
        assert bbox.y1 == 20.0
        assert bbox.x2 == 110.0
        assert bbox.y2 == 170.0
        assert bbox.width == 100.0
        assert bbox.height == 150.0
        assert bbox.area == 15000.0
        assert bbox.width > 0
        assert bbox.height > 0
        assert bbox.area > 0

    def test_x1_equals_x2_rejected(self) -> None:
        """Requirement 1: x1 == x2 rejected."""
        with pytest.raises(ValidationError, match="must be strictly greater than x1"):
            BoundingBox(x1=50.0, y1=20.0, x2=50.0, y2=170.0)

    def test_y1_equals_y2_rejected(self) -> None:
        """Requirement 2: y1 == y2 rejected."""
        with pytest.raises(ValidationError, match="must be strictly greater than y1"):
            BoundingBox(x1=10.0, y1=100.0, x2=110.0, y2=100.0)

    def test_x2_less_than_x1_rejected(self) -> None:
        """Requirement 3: x2 < x1 rejected."""
        with pytest.raises(ValidationError, match="must be strictly greater than x1"):
            BoundingBox(x1=100.0, y1=20.0, x2=50.0, y2=170.0)

    def test_y2_less_than_y1_rejected(self) -> None:
        """Requirement 4: y2 < y1 rejected."""
        with pytest.raises(ValidationError, match="must be strictly greater than y1"):
            BoundingBox(x1=10.0, y1=200.0, x2=110.0, y2=50.0)

    def test_non_finite_coordinates_rejected(self) -> None:
        with pytest.raises(ValidationError):
            BoundingBox(x1=float("nan"), y1=20.0, x2=110.0, y2=170.0)

        with pytest.raises(ValidationError):
            BoundingBox(x1=10.0, y1=float("inf"), x2=110.0, y2=170.0)

    def test_clamp_preserves_valid_positive_area_box(self) -> None:
        """Requirement 6: clamp preserves valid positive-area box."""
        bbox = BoundingBox(x1=-10.0, y1=-20.0, x2=600.0, y2=800.0)
        clamped = bbox.clamp(max_width=500.0, max_height=700.0)
        assert clamped is not None
        assert clamped.x1 == 0.0
        assert clamped.y1 == 0.0
        assert clamped.x2 == 500.0
        assert clamped.y2 == 700.0
        assert clamped.width == 500.0
        assert clamped.height == 700.0
        assert clamped.area > 0

    def test_clamp_collapses_box_is_rejected_discarded(self) -> None:
        """Requirement 7: clamp that collapses box is rejected/discarded."""
        # Box entirely beyond right boundary
        box_out_right = BoundingBox(x1=600.0, y1=50.0, x2=700.0, y2=150.0)
        assert box_out_right.clamp(max_width=500.0, max_height=500.0) is None

        # Box entirely beyond bottom boundary
        box_out_bottom = BoundingBox(x1=50.0, y1=600.0, x2=150.0, y2=700.0)
        assert box_out_bottom.clamp(max_width=500.0, max_height=500.0) is None

        # Box entirely beyond left boundary
        box_out_left = BoundingBox(x1=-200.0, y1=50.0, x2=-50.0, y2=150.0)
        assert box_out_left.clamp(max_width=500.0, max_height=500.0) is None

        # Box collapsing on zero dimension
        box_zero_dim = BoundingBox(x1=0.0, y1=0.0, x2=10.0, y2=10.0)
        assert box_zero_dim.clamp(max_width=0.0, max_height=500.0) is None

    def test_landmark_point_validation(self) -> None:
        point = LandmarkPoint(x=12.5, y=34.5)
        assert point.x == 12.5
        assert point.y == 34.5

        with pytest.raises(ValidationError):
            LandmarkPoint(x=float("nan"), y=34.5)


class TestFaceDetectionSchema:
    """Unit tests for FaceDetection schema."""

    def test_valid_face_detection(self) -> None:
        bbox = BoundingBox(x1=10.0, y1=10.0, x2=50.0, y2=60.0)
        landmarks = [LandmarkPoint(x=20.0, y=25.0), LandmarkPoint(x=40.0, y=25.0)]
        detection = FaceDetection(
            bbox=bbox,
            confidence=0.97,
            landmarks=landmarks,
            detector_name="test_detector",
            detector_version="1.0.0",
        )
        assert detection.confidence == 0.97
        assert len(detection.landmarks or []) == 2
        assert detection.detector_name == "test_detector"
        assert detection.model_type == AIModelType.FACE_DETECTION

    def test_confidence_range_validation(self) -> None:
        bbox = BoundingBox(x1=10.0, y1=10.0, x2=50.0, y2=60.0)
        with pytest.raises(ValidationError):
            FaceDetection(
                bbox=bbox,
                confidence=1.5,
                detector_name="test",
                detector_version="1.0",
            )

        with pytest.raises(ValidationError):
            FaceDetection(
                bbox=bbox,
                confidence=-0.1,
                detector_name="test",
                detector_version="1.0",
            )


class TestAIModelTypeRegistryIntegration:
    """Unit tests for canonical AIModelType and model registry integration."""

    def test_canonical_face_detection_model_type(self) -> None:
        """Requirement 8: FACE_DETECTION registry type used."""
        assert AIModelType.FACE_DETECTION.value == "FACE_DETECTION"
        assert AIModelType.FACE_DETECTION == "FACE_DETECTION"

        detector = MockFaceDetector()
        assert detector.model_type == AIModelType.FACE_DETECTION
        assert detector.model_type.value == "FACE_DETECTION"

    def test_non_canonical_detection_type_rejected(self) -> None:
        """Ensure non-canonical 'DETECTION' or other aliases are not in AIModelType."""
        with pytest.raises(ValueError):
            AIModelType("DETECTION")

        # Test model_type validation on FaceDetection schema
        bbox = BoundingBox(x1=10.0, y1=10.0, x2=50.0, y2=60.0)
        with pytest.raises(ValidationError):
            FaceDetection(
                bbox=bbox,
                confidence=0.9,
                detector_name="test",
                detector_version="1.0",
                model_type="DETECTION",  # type: ignore
            )


class TestImageValidation:
    """Unit tests for detector input validation."""

    def test_none_image_rejected(self) -> None:
        detector = MockFaceDetector()
        with pytest.raises(InvalidImageError, match="cannot be None"):
            detector.detect(None)  # type: ignore

    def test_invalid_type_rejected(self) -> None:
        detector = MockFaceDetector()
        with pytest.raises(InvalidImageError, match="must be a numpy.ndarray"):
            detector.detect([[1, 2], [3, 4]])  # type: ignore

    def test_empty_array_rejected(self) -> None:
        detector = MockFaceDetector()
        with pytest.raises(InvalidImageError, match="is empty"):
            detector.detect(np.array([]))

    def test_invalid_dimensions_rejected(self) -> None:
        detector = MockFaceDetector()
        with pytest.raises(InvalidImageError, match="must have 2 or 3 dimensions"):
            detector.detect(np.zeros((10, 10, 3, 2)))

    def test_unsupported_channel_count_rejected(self) -> None:
        detector = MockFaceDetector()
        with pytest.raises(InvalidImageError, match="unsupported channel count"):
            detector.detect(np.zeros((100, 100, 2), dtype=np.uint8))

    def test_valid_image_formats_accepted(self) -> None:
        detector = MockFaceDetector(default_num_faces=0)
        # 2D grayscale
        res_gray = detector.detect(np.zeros((100, 100), dtype=np.uint8))
        assert res_gray == []

        # 3D RGB
        res_rgb = detector.detect(np.zeros((100, 100, 3), dtype=np.uint8))
        assert res_rgb == []

        # 3D RGBA
        res_rgba = detector.detect(np.zeros((100, 100, 4), dtype=np.uint8))
        assert res_rgba == []


class TestMockFaceDetector:
    """Unit tests for MockFaceDetector."""

    def test_default_single_face_detection(self) -> None:
        detector = MockFaceDetector(default_num_faces=1, default_confidence=0.95)
        image = np.zeros((480, 640, 3), dtype=np.uint8)
        detections = detector.detect(image)

        assert len(detections) == 1
        d = detections[0]
        assert d.detector_name == "mock_detector"
        assert d.detector_version == "1.0.0"
        assert d.confidence == 0.95
        assert d.bbox.x1 >= 0.0
        assert d.bbox.x2 <= 640.0
        assert d.bbox.y1 >= 0.0
        assert d.bbox.y2 <= 480.0
        assert d.bbox.width > 0
        assert d.bbox.height > 0
        assert d.bbox.area > 0
        assert d.model_type == AIModelType.FACE_DETECTION
        assert d.landmarks is not None
        assert len(d.landmarks) == 5

    def test_multiple_faces_detection(self) -> None:
        detector = MockFaceDetector(default_num_faces=3)
        image = np.zeros((480, 640, 3), dtype=np.uint8)
        detections = detector.detect(image)

        assert len(detections) == 3
        # Check that bounding boxes are separated and positive area
        assert detections[0].bbox.x1 < detections[1].bbox.x1 < detections[2].bbox.x1
        for d in detections:
            assert d.bbox.area > 0

    def test_custom_detections(self) -> None:
        custom = [
            FaceDetection(
                bbox=BoundingBox(x1=5, y1=5, x2=25, y2=35),
                confidence=0.99,
                detector_name="custom",
                detector_version="0.1",
            )
        ]
        detector = MockFaceDetector(custom_detections=custom)
        image = np.zeros((100, 100, 3), dtype=np.uint8)
        assert detector.detect(image) == custom

    def test_simulated_error(self) -> None:
        simulated = DetectorError("Simulated hardware failure")
        detector = MockFaceDetector(simulate_error=simulated)
        image = np.zeros((100, 100, 3), dtype=np.uint8)
        with pytest.raises(DetectorError, match="Simulated hardware failure"):
            detector.detect(image)


class TestONNXFaceDetector:
    """Unit tests for ONNXFaceDetector."""

    def test_uninitialized_onnx_detector_raises_not_ready(self) -> None:
        detector = ONNXFaceDetector(model_path="non_existent_model.onnx")
        assert not detector.is_ready
        assert detector.model_type == AIModelType.FACE_DETECTION
        image = np.zeros((100, 100, 3), dtype=np.uint8)
        with pytest.raises(DetectorNotReadyError, match="not ready"):
            detector.detect(image)


class TestDetectorFactory:
    """Unit tests for factory resolution."""

    def test_factory_resolves_mock(self) -> None:
        detector = get_face_detector("mock")
        assert isinstance(detector, MockFaceDetector)

    def test_factory_resolves_onnx(self) -> None:
        # ACR-AI-01: AI_MODE=onnx is the YuNet detector, not the former placeholder.
        detector = get_face_detector("onnx")
        assert isinstance(detector, YuNetFaceDetector)

    def test_factory_resolves_default(self) -> None:
        detector = get_face_detector()
        assert isinstance(detector, FaceDetector)

    def test_factory_unsupported_provider_raises(self) -> None:
        with pytest.raises(ValueError, match="Unsupported face detector provider"):
            get_face_detector("tensorflow_invalid")


class TestScopeCreepGuard:
    """Requirement 9: verify no alignment/quality/embedding code introduced."""

    def test_no_alignment_quality_embedding_in_detection_module(self) -> None:
        import app.detection as detection_module

        exported = dir(detection_module)
        assert "FaceAligner" not in exported
        assert "FaceQualityEvaluator" not in exported
        assert "EmbeddingGenerator" not in exported
        assert "FaceEmbedding" not in exported
        assert "RecognitionService" not in exported
