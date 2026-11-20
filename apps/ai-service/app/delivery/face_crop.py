"""Event face crop (EVT-025, ACR-EVT-24 approved 2026-11-20).

Cuts the detected face (with a small margin) out of the camera frame and
encodes it as a small JPEG for an UNKNOWN or REVIEW event, so an operator can
see who triggered the alert. Never a full frame, never an embedding: the crop
is bounded to CROP_MAX_SIDE pixels, sent once to the backend's private
event-snapshot endpoint after the event itself was accepted, and never kept on
the node (not cached, not written to the offline buffer).
"""

import logging
from typing import Any

logger = logging.getLogger(__name__)

CROP_CLASSIFICATIONS = frozenset({"UNKNOWN", "REVIEW"})
CROP_MARGIN = 0.2
CROP_MAX_SIDE = 256
CROP_MIN_SIDE = 32
JPEG_QUALITY = 85


def encode_face_crop(frame: Any, bbox: tuple[float, float, float, float]) -> bytes | None:
    """JPEG bytes of the face region, or None when it cannot be produced.

    Best effort: any failure yields None and the event is delivered without
    an image. bbox is (x1, y1, x2, y2) in frame pixels.
    """
    try:
        import cv2  # noqa: PLC0415 (lazy, same convention as app.alignment.standard)

        height, width = frame.shape[:2]
        x1, y1, x2, y2 = bbox
        margin_x = (x2 - x1) * CROP_MARGIN
        margin_y = (y2 - y1) * CROP_MARGIN
        left = max(0, int(x1 - margin_x))
        top = max(0, int(y1 - margin_y))
        right = min(width, int(x2 + margin_x))
        bottom = min(height, int(y2 + margin_y))
        if right - left < CROP_MIN_SIDE or bottom - top < CROP_MIN_SIDE:
            return None

        crop = frame[top:bottom, left:right]
        longest = max(crop.shape[0], crop.shape[1])
        if longest > CROP_MAX_SIDE:
            scale = CROP_MAX_SIDE / longest
            new_size = (
                max(CROP_MIN_SIDE, round(crop.shape[1] * scale)),
                max(CROP_MIN_SIDE, round(crop.shape[0] * scale)),
            )
            crop = cv2.resize(crop, new_size, interpolation=cv2.INTER_AREA)

        ok, encoded = cv2.imencode(".jpg", crop, [int(cv2.IMWRITE_JPEG_QUALITY), JPEG_QUALITY])
        if not ok:
            return None
        return encoded.tobytes()
    except Exception as exc:  # noqa: BLE001 — a crop must never break recognition
        logger.debug("Face crop could not be encoded: %s", type(exc).__name__)
        return None
