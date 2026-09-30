"""Factory for obtaining Face Liveness / PAD detector (FACE-015)."""

from app.core.config import settings
from app.liveness.base import FaceLivenessDetector
from app.liveness.mock import MockFaceLivenessDetector
from app.liveness.unavailable import UnavailableFaceLivenessDetector

_detector_instance: FaceLivenessDetector | None = None


def get_face_liveness_detector() -> FaceLivenessDetector:
    """Obtain or instantiate the active Face Liveness detector."""
    global _detector_instance

    if _detector_instance is not None:
        return _detector_instance

    if settings.ai_mode == "mock":
        _detector_instance = MockFaceLivenessDetector()
    else:
        # Fail closed: never fall back to the always-REAL mock outside mock mode
        _detector_instance = UnavailableFaceLivenessDetector()

    return _detector_instance
