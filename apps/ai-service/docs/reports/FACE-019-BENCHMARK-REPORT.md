# AI Biometrics & Perception Benchmark Report (FACE-019)

**Generated:** 2026-09-20T07:46:57Z
**Model:** `arcface-r50-v1` (512 dimensions)

## 1. Biometric Separation & Accuracy Metrics

| Metric | Measured Value | Standard Target |
| :--- | :--- | :--- |
| **Equal Error Rate (EER)** | **38.08%** | < 1.0% |
| **EER Threshold** | `0.0136` | ~ 0.65 - 0.75 |
| **TAR @ FAR = 0.01%** | **2.00%** | > 95.0% |
| **TAR @ FAR = 0.1%** | **2.00%** | > 98.0% |
| **TAR @ FAR = 1.0%** | **5.00%** | > 99.0% |
| **Genuine Score Distribution** | μ = 0.0255, σ = 0.0447 | Separation > 0.40 |
| **Impostor Score Distribution** | μ = -0.0001, σ = 0.0447 | |

## 2. Recommended Threshold Settings (for FACE-021 Human Review)

- **Match Threshold (`face.recognition.match_threshold`):** `0.14`
- **Review Threshold (`face.recognition.review_threshold`):** `0.50`
- **Ambiguity Margin (`face.recognition.ambiguity_margin`):** `0.05`
- **Duplicate Review Threshold (`face.duplicate_review.similarity_threshold`):** `0.65`

## 3. Pipeline Latency Breakdown

- **Face Detection:** 0.06 ms
- **Face Quality Evaluation:** 0.03 ms
- **Feature Embedding Extraction:** 0.43 ms
- **Liveness / PAD Analysis:** 0.0 ms
- **Total Pipeline Latency:** **0.52 ms**
