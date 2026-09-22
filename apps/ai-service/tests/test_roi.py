"""Tests for region-of-interest support (CAM-016)."""

import pytest
from pydantic import ValidationError

from app.detection.schemas import BoundingBox, FaceDetection
from app.roi.filter import is_detection_in_roi, point_in_polygon
from app.roi.schemas import RegionOfInterest, RoiPoint


def make_detection(x1: float, y1: float, x2: float, y2: float) -> FaceDetection:
    return FaceDetection(
        bbox=BoundingBox(x1=x1, y1=y1, x2=x2, y2=y2),
        confidence=0.95,
        landmarks=[],
        detector_name="mock_detector",
        detector_version="1.0.0",
    )


def square_roi(x0: float, y0: float, x1: float, y1: float) -> RegionOfInterest:
    return RegionOfInterest(
        points=[
            RoiPoint(x=x0, y=y0),
            RoiPoint(x=x1, y=y0),
            RoiPoint(x=x1, y=y1),
            RoiPoint(x=x0, y=y1),
        ]
    )


class TestRegionOfInterestSchema:
    def test_requires_at_least_three_points(self) -> None:
        with pytest.raises(ValidationError):
            RegionOfInterest(points=[RoiPoint(x=0.0, y=0.0), RoiPoint(x=1.0, y=1.0)])

    def test_rejects_out_of_bounds_coordinates(self) -> None:
        with pytest.raises(ValidationError):
            RoiPoint(x=1.5, y=0.5)

    def test_rejects_degenerate_polygon_with_duplicate_points(self) -> None:
        with pytest.raises(ValidationError):
            RegionOfInterest(
                points=[
                    RoiPoint(x=0.1, y=0.1),
                    RoiPoint(x=0.1, y=0.1),
                    RoiPoint(x=0.1, y=0.1),
                ]
            )

    def test_accepts_a_valid_triangle(self) -> None:
        roi = RegionOfInterest(
            points=[RoiPoint(x=0.0, y=0.0), RoiPoint(x=1.0, y=0.0), RoiPoint(x=0.5, y=1.0)]
        )
        assert len(roi.as_tuples()) == 3


class TestPointInPolygon:
    def test_point_inside_square(self) -> None:
        square = [(0.0, 0.0), (1.0, 0.0), (1.0, 1.0), (0.0, 1.0)]
        assert point_in_polygon(0.5, 0.5, square) is True

    def test_point_outside_square(self) -> None:
        square = [(0.0, 0.0), (1.0, 0.0), (1.0, 1.0), (0.0, 1.0)]
        assert point_in_polygon(1.5, 0.5, square) is False

    def test_point_inside_triangle(self) -> None:
        triangle = [(0.0, 0.0), (1.0, 0.0), (0.5, 1.0)]
        assert point_in_polygon(0.5, 0.3, triangle) is True

    def test_point_outside_triangle(self) -> None:
        triangle = [(0.0, 0.0), (1.0, 0.0), (0.5, 1.0)]
        assert point_in_polygon(0.05, 0.9, triangle) is False


class TestIsDetectionInRoi:
    def test_no_roi_keeps_every_detection(self) -> None:
        detection = make_detection(900, 900, 950, 950)  # far corner
        assert is_detection_in_roi(detection, None, frame_width=1000, frame_height=1000) is True

    def test_detection_centered_inside_roi_is_kept(self) -> None:
        roi = square_roi(0.0, 0.0, 0.5, 0.5)  # top-left quadrant
        detection = make_detection(x1=100, y1=100, x2=200, y2=200)  # center at (0.15, 0.15)
        assert is_detection_in_roi(detection, roi, frame_width=1000, frame_height=1000) is True

    def test_detection_centered_outside_roi_is_dropped(self) -> None:
        roi = square_roi(0.0, 0.0, 0.5, 0.5)  # top-left quadrant
        detection = make_detection(x1=800, y1=800, x2=900, y2=900)  # center at (0.85, 0.85)
        assert is_detection_in_roi(detection, roi, frame_width=1000, frame_height=1000) is False

    def test_detection_straddling_roi_boundary_uses_bbox_center(self) -> None:
        roi = square_roi(0.0, 0.0, 0.5, 0.5)
        # bbox spans across the ROI edge, but its center (0.4, 0.4) is inside.
        detection = make_detection(x1=200, y1=200, x2=600, y2=600)
        assert is_detection_in_roi(detection, roi, frame_width=1000, frame_height=1000) is True
