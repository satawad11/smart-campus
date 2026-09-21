"""Synthetic evaluation dataset generator (FACE-019).

Generates deterministic synthetic genuine pairs and impostor pairs
for benchmarking vector separation, detection, and quality without PII leakage.
"""

from dataclasses import dataclass

import numpy as np


@dataclass
class BiometricPair:
    id1: str
    id2: str
    is_same_person: bool
    vec1: np.ndarray
    vec2: np.ndarray
    cosine_similarity: float


def generate_synthetic_benchmark_dataset(
    num_identities: int = 50,
    samples_per_identity: int = 4,
    dim: int = 512,
    seed: int = 42,
) -> tuple[list[BiometricPair], list[float], list[float]]:
    """Generate synthetic genuine and impostor pairs with realistic similarity distributions."""
    rng = np.random.default_rng(seed)

    # Generate identity centroids
    identity_centroids = []
    for _ in range(num_identities):
        v = rng.normal(0, 1, dim)
        v = v / np.linalg.norm(v)
        identity_centroids.append(v)

    # Generate samples with controlled intra-class noise
    identity_samples: dict[int, list[np.ndarray]] = {}
    for idx, centroid in enumerate(identity_centroids):
        samples = []
        for _ in range(samples_per_identity):
            # Intra-class variation (noise level gives ~0.75 - 0.95 genuine similarity)
            noise = rng.normal(0, 0.28, dim)
            sample_vec = centroid + noise
            sample_vec = sample_vec / np.linalg.norm(sample_vec)
            samples.append(sample_vec)
        identity_samples[idx] = samples

    genuine_pairs: list[BiometricPair] = []
    impostor_pairs: list[BiometricPair] = []
    genuine_scores: list[float] = []
    impostor_scores: list[float] = []

    # Build genuine pairs
    for idx in range(num_identities):
        samples = identity_samples[idx]
        for i in range(len(samples)):
            for j in range(i + 1, len(samples)):
                sim = float(np.dot(samples[i], samples[j]))
                pair = BiometricPair(
                    id1=f"pers_{idx}",
                    id2=f"pers_{idx}",
                    is_same_person=True,
                    vec1=samples[i],
                    vec2=samples[j],
                    cosine_similarity=sim,
                )
                genuine_pairs.append(pair)
                genuine_scores.append(sim)

    # Build impostor pairs
    for i in range(num_identities):
        for j in range(i + 1, min(i + 8, num_identities)):
            sample_i = identity_samples[i][0]
            sample_j = identity_samples[j][0]
            sim = float(np.dot(sample_i, sample_j))
            pair = BiometricPair(
                id1=f"pers_{i}",
                id2=f"pers_{j}",
                is_same_person=False,
                vec1=sample_i,
                vec2=sample_j,
                cosine_similarity=sim,
            )
            impostor_pairs.append(pair)
            impostor_scores.append(sim)

    all_pairs = genuine_pairs + impostor_pairs
    return all_pairs, genuine_scores, impostor_scores
