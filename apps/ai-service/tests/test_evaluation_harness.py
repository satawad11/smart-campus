"""Tests for AI Benchmark & Evaluation Harness (FACE-019)."""

from app.evaluation.harness import AIModelBenchmarkHarness
from app.evaluation.metrics import compute_roc_curve
from app.evaluation.synthetic_dataset import generate_synthetic_benchmark_dataset


def test_synthetic_dataset_generation():
    pairs, genuine_scores, impostor_scores = generate_synthetic_benchmark_dataset(
        num_identities=10,
        samples_per_identity=3,
        dim=128,
        seed=123,
    )

    assert len(pairs) > 0
    assert len(genuine_scores) > 0
    assert len(impostor_scores) > 0
    assert max(genuine_scores) > max(impostor_scores) - 0.2


def test_roc_metrics_computation():
    genuine = [0.85, 0.90, 0.92, 0.88, 0.95, 0.78, 0.82]
    impostor = [0.10, 0.25, 0.35, 0.20, 0.45, 0.30, 0.15]

    roc = compute_roc_curve(genuine, impostor, steps=20)

    assert roc.eer <= 0.10
    assert roc.recommended_match_threshold >= 0.50
    assert roc.recommended_review_threshold <= roc.recommended_match_threshold


def test_full_benchmark_harness_run():
    harness = AIModelBenchmarkHarness(num_identities=10, samples_per_identity=3)
    results = harness.run_benchmark()

    assert "eer" in results
    assert "latencies_ms" in results
    assert "recommended_match_threshold" in results
    assert "recommended_review_threshold" in results

    md_report = harness.generate_markdown_report(results)
    assert "# AI Biometrics & Perception Benchmark Report" in md_report
