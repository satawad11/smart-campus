"""Fail-closed PAD provider used when no production PAD model is configured."""

import numpy as np

from app.liveness.base import FaceLivenessDetector, LivenessResult


class LivenessModelUnavailableError(RuntimeError):
    """Raised when PAD is requested outside mock mode without a PAD model."""


class UnavailableFaceLivenessDetector(FaceLivenessDetector):
    """Refuses to evaluate instead of reporting a fabricated REAL score.

    Before PILOT pre-deployment review, non-mock modes silently fell back to the
    mock detector, which scores every image 0.95 (REAL) — PAD failed open.
    """

    def evaluate(
        self,
        image: np.ndarray,
        bounding_box: tuple[float, float, float, float] | None = None,
    ) -> LivenessResult:
        raise LivenessModelUnavailableError(
            "No presentation attack detection model is configured for this AI mode"
        )
