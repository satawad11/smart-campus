"""Whole-frame brightness/blur metrics for camera health (CAM-013).

Reuses FACE-006's grayscale/Laplacian-variance primitives (same formulas,
same 0.0..1.0 "higher is better" direction) but operates on the raw camera
frame, not an aligned face crop — there may be zero, one, or many faces (or
none at all) in a given sampled frame, and camera health must not depend on
a face being present.
"""

import numpy as np

from app.health.schemas import FrameMetrics
from app.quality.standard import compute_grayscale, compute_laplacian_variance


def compute_frame_metrics(frame_data: np.ndarray) -> FrameMetrics:
    gray = compute_grayscale(frame_data)

    lap_var = compute_laplacian_variance(gray)
    blur_score = float(np.clip(lap_var / (lap_var + 100.0), 0.0, 1.0))

    mean_luminance = float(np.mean(gray)) / 255.0
    brightness_score = float(np.clip(1.0 - 2.0 * abs(mean_luminance - 0.50), 0.0, 1.0))

    return FrameMetrics(brightness_score=brightness_score, blur_score=blur_score)
