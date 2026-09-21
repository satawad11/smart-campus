"""Bounding-box IoU (Intersection over Union) geometry (CAM-008)."""

from app.detection.schemas import BoundingBox


def compute_iou(a: BoundingBox, b: BoundingBox) -> float:
    """Intersection-over-union of two axis-aligned boxes, in [0.0, 1.0]."""
    inter_x1 = max(a.x1, b.x1)
    inter_y1 = max(a.y1, b.y1)
    inter_x2 = min(a.x2, b.x2)
    inter_y2 = min(a.y2, b.y2)

    if inter_x2 <= inter_x1 or inter_y2 <= inter_y1:
        return 0.0

    intersection = (inter_x2 - inter_x1) * (inter_y2 - inter_y1)
    union = a.area + b.area - intersection

    if union <= 0:
        return 0.0

    return intersection / union
