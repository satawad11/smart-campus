"""Deterministic Mock Liveness / PAD Detector (FACE-015)."""

import numpy as np

from app.liveness.base import FaceLivenessDetector, LivenessResult


class MockFaceLivenessDetector(FaceLivenessDetector):
    """Deterministic mock provider for presentation attack detection."""

    def __init__(self, default_score: float = 0.95):
        self.default_score = default_score

    def evaluate(
        self,
        image: np.ndarray,
        bounding_box: tuple[float, float, float, float] | None = None,
    ) -> LivenessResult:
        if image is None or image.size == 0:
            return LivenessResult(
                score=0.0,
                is_live=False,
                method="MOCK",
                status="SPOOF",
                rejection_reasons=["INVALID_IMAGE"],
                details={"error": "Empty image array"},
            )

        # In mock mode, if the image brightness/variance is artificially 0 or specific flag
        score = self.default_score
        status = "REAL" if score >= 0.70 else ("SPOOF" if score < 0.40 else "UNCERTAIN")

        return LivenessResult(
            score=score,
            is_live=status == "REAL",
            method="MOCK",
            status=status,
            rejection_reasons=[] if status == "REAL" else ["PRESENTATION_ATTACK_SUSPECTED"],
            details={
                "resolution": f"{image.shape[1]}x{image.shape[0]}",
                "channels": image.shape[2] if len(image.shape) > 2 else 1,
            },
        )
