"""Biometric & AI Perception Evaluation Metrics (FACE-019).

Computes FAR (False Acceptance Rate), FRR (False Rejection Rate),
TAR (True Acceptance Rate), EER (Equal Error Rate), and latency statistics.
"""

from collections.abc import Sequence
from dataclasses import dataclass


@dataclass
class ThresholdOperatingPoint:
    threshold: float
    far: float  # False Acceptance Rate
    frr: float  # False Rejection Rate
    tar: float  # True Acceptance Rate (1 - FRR)
    accuracy: float


@dataclass
class EvaluationMetricsSummary:
    eer: float  # Equal Error Rate
    eer_threshold: float
    tar_at_far_0_01: float  # TAR @ FAR = 0.01% (0.0001)
    tar_at_far_0_1: float  # TAR @ FAR = 0.1% (0.001)
    tar_at_far_1_0: float  # TAR @ FAR = 1.0% (0.01)
    recommended_match_threshold: float
    recommended_review_threshold: float
    operating_points: list[ThresholdOperatingPoint]


def compute_roc_curve(
    genuine_scores: Sequence[float],
    impostor_scores: Sequence[float],
    steps: int = 100,
) -> EvaluationMetricsSummary:
    """Compute ROC operating points, EER, and recommended thresholds from score distributions."""
    if not genuine_scores or not impostor_scores:
        raise ValueError("Genuine and impostor score lists cannot be empty.")

    num_genuine = len(genuine_scores)
    num_impostor = len(impostor_scores)

    operating_points: list[ThresholdOperatingPoint] = []
    min_score = min(min(genuine_scores), min(impostor_scores))
    max_score = max(max(genuine_scores), max(impostor_scores))

    step_size = (max_score - min_score) / max(steps, 1)

    eer = 1.0
    eer_threshold = 0.5
    min_eer_diff = 1.0

    tar_at_far_0_01 = 0.0
    tar_at_far_0_1 = 0.0
    tar_at_far_1_0 = 0.0

    thresholds = [min_score + i * step_size for i in range(steps + 1)]

    for t in thresholds:
        # False Rejection: Genuine score < threshold
        fr_count = sum(1 for s in genuine_scores if s < t)
        frr = fr_count / num_genuine

        # False Acceptance: Impostor score >= threshold
        fa_count = sum(1 for s in impostor_scores if s >= t)
        far = fa_count / num_impostor

        tar = 1.0 - frr
        acc = (num_genuine - fr_count + num_impostor - fa_count) / (num_genuine + num_impostor)

        operating_points.append(
            ThresholdOperatingPoint(
                threshold=round(t, 4),
                far=round(far, 6),
                frr=round(frr, 6),
                tar=round(tar, 6),
                accuracy=round(acc, 6),
            )
        )

        # Track EER
        diff = abs(far - frr)
        if diff < min_eer_diff:
            min_eer_diff = diff
            eer = (far + frr) / 2.0
            eer_threshold = t

        # Track TAR at specific FAR points
        if far <= 0.0001 and tar > tar_at_far_0_01:
            tar_at_far_0_01 = tar
        if far <= 0.001 and tar > tar_at_far_0_1:
            tar_at_far_0_1 = tar
        if far <= 0.01 and tar > tar_at_far_1_0:
            tar_at_far_1_0 = tar

    # Recommended thresholds:
    # Match threshold: Aim for FAR <= 0.1% or strict separation
    match_point = next((p for p in reversed(operating_points) if p.far <= 0.005), None)
    recommended_match = match_point.threshold if match_point else round(eer_threshold + 0.15, 2)

    # Review threshold: Set below match threshold to catch borderline genuine samples
    recommended_review = max(round(recommended_match - 0.20, 2), 0.50)

    return EvaluationMetricsSummary(
        eer=round(eer, 4),
        eer_threshold=round(eer_threshold, 4),
        tar_at_far_0_01=round(tar_at_far_0_01, 4),
        tar_at_far_0_1=round(tar_at_far_0_1, 4),
        tar_at_far_1_0=round(tar_at_far_1_0, 4),
        recommended_match_threshold=round(recommended_match, 2),
        recommended_review_threshold=round(recommended_review, 2),
        operating_points=operating_points,
    )
