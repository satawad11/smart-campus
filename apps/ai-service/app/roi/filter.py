"""Region-of-interest filtering (CAM-016).

Filters already-produced FaceDetections by whether their location falls
inside the camera's configured ROI. Detection itself is unchanged (still
runs on the whole frame) — this only decides which detections continue on
to tracking/recognition, so a camera with no ROI processes everything
exactly as before CAM-016 existed.
"""

from app.detection.schemas import BoundingBox, FaceDetection
from app.roi.schemas import RegionOfInterest


def point_in_polygon(x: float, y: float, polygon: list[tuple[float, float]]) -> bool:
    """Standard ray-casting point-in-polygon test. Coordinates and polygon
    vertices must be in the same units (both normalized or both pixels)."""
    inside = False
    n = len(polygon)
    for i in range(n):
        x1, y1 = polygon[i]
        x2, y2 = polygon[(i + 1) % n]
        if (y1 > y) != (y2 > y):
            x_intersect = x1 + (y - y1) * (x2 - x1) / (y2 - y1)
            if x < x_intersect:
                inside = not inside
    return inside


def _bbox_center_normalized(
    bbox: BoundingBox, frame_width: int, frame_height: int
) -> tuple[float, float]:
    cx = (bbox.x1 + bbox.x2) / 2.0 / frame_width
    cy = (bbox.y1 + bbox.y2) / 2.0 / frame_height
    return cx, cy


def is_detection_in_roi(
    detection: FaceDetection,
    roi: RegionOfInterest | None,
    frame_width: int,
    frame_height: int,
) -> bool:
    """True if the detection should be kept. A None ROI keeps everything
    (unrestricted — the pre-CAM-016 default)."""
    if roi is None:
        return True

    cx, cy = _bbox_center_normalized(detection.bbox, frame_width, frame_height)
    return point_in_polygon(cx, cy, roi.as_tuples())
