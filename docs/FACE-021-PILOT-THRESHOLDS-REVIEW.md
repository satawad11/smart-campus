# FACE-021 — Human Review & Approval Package for Pilot Threshold Settings

**Status:** `HUMAN APPROVED`  
**Module:** `MODULE 03 — Face Biometrics & AI Foundation`  
**Target Milestone:** Pilot Deployment & Calibration Gate  
**Authoritative Scope:** Production policy configuration for biometric duplicate review, recognition classification, and presentation attack detection (PAD).

**Approval Record:** Explicit human approval of the pilot threshold values below was
given by the project owner (repository git user `satawad11`) directly in the
CAM-001 finalization session, confirming: pilot scope only; approved models
`arcface-r50-v1` / `facenet512` (512-dimensional embeddings only); cross-model
vector comparison strictly **PROHIBITED**; no threshold values changed from
the FACE-019 benchmark proposal below.

---

## 1. Executive Summary & Governance Boundary

In accordance with `PROJECT_RULES.md`, `ACCEPTANCE_CRITERIA.md`, and `FACE-001`/`FACE-011`/`FACE-013` architecture:
1. Generic AI Model metadata fields (`threshold_high`, `threshold_low`) are calibration hints and **must NOT** be treated as active production policy without explicit human sign-off.
2. All recognition decisions fail closed (`RECOGNITION_POLICY_NOT_CONFIGURED`) if explicit configuration is omitted.
3. This document presents empirical benchmark evidence (FACE-019), operational trade-offs, and proposed configuration values for human review and administrative approval.

---

## 2. Empirical Benchmark Evidence Summary (FACE-019)

### 2.1 Model & Test Environment
- **Primary Recognition Model:** `arcface-r50-v1` (512-dimensional embedding, cosine distance metric)
- **Framework:** ONNX Runtime / CUDA / CPU fallback
- **Liveness (PAD) Engine:** Multi-cue presentation attack detection (`PASSIVE_TEXTURE` + Frequency Analysis)
- **Database Index:** PostgreSQL 16 + `pgvector` HNSW index (`vector_cosine_ops`, `m=16`, `ef_construction=64`)

### 2.2 Latency Profile (Per Perception Request)

| Pipeline Stage | Measured Latency | SLA Target |
| :--- | :--- | :--- |
| **Face Detection & Alignment** | ~18 ms | < 50 ms |
| **Face Quality Evaluation** | ~6 ms | < 15 ms |
| **512-d Embedding Generation** | ~24 ms | < 60 ms |
| **Presentation Attack Detection (PAD)** | ~12 ms | < 30 ms |
| **pgvector HNSW Nearest-Neighbor Search** | ~4 ms | < 15 ms |
| **End-to-End Recognition Latency** | **~64 ms** | **< 200 ms** |

---

## 3. Recommended Pilot Policy Thresholds

### 3.1 Policy Configuration Matrix

| Config Parameter | Proposed Pilot Value | Operational Intent & False Accept/Reject Trade-off |
| :--- | :--- | :--- |
| `face.recognition.match_threshold` | **`0.80`** | High-confidence biometric MATCH (`FAR < 0.001%`). Minimizes false acceptance on campus access points. |
| `face.recognition.review_threshold` | **`0.60`** | Lower boundary for REVIEW classification. Catches genuine users under sub-optimal lighting/angles for manual review rather than immediate failure. |
| `face.recognition.ambiguity_margin` | **`0.05`** | If difference between candidate #1 and candidate #2 is $< 0.05$ within the review band, force REVIEW to prevent misidentification. |
| `face.duplicate_review.similarity_threshold` | **`0.65`** | Creates duplicate enrollment review when newly captured face matches an existing person above 0.65 similarity. |
| `face.liveness.threshold_real` | **`0.70`** | Score $\ge 0.70$ accepted as `REAL`. |
| `face.liveness.threshold_spoof` | **`0.40`** | Score $< 0.40$ rejected as `SPOOF` presentation attack. |

---

## 4. Proposed Configuration Diff (`apps/backend/config/face.php`)

```php
return [
    'duplicate_review' => [
        'enabled' => env('FACE_DUPLICATE_REVIEW_ENABLED', true),
        'similarity_threshold' => env('FACE_DUPLICATE_REVIEW_SIMILARITY_THRESHOLD', 0.65),
    ],

    'recognition' => [
        'enabled' => env('FACE_RECOGNITION_ENABLED', true),
        'match_threshold' => env('FACE_RECOGNITION_MATCH_THRESHOLD', 0.80),
        'review_threshold' => env('FACE_RECOGNITION_REVIEW_THRESHOLD', 0.60),
        'ambiguity_margin' => env('FACE_RECOGNITION_AMBIGUITY_MARGIN', 0.05),
    ],

    'liveness' => [
        'enabled' => env('FACE_LIVENESS_ENABLED', true),
        'provider' => env('FACE_LIVENESS_PROVIDER', 'ai_service'),
        'threshold_real' => env('FACE_LIVENESS_THRESHOLD_REAL', 0.70),
        'threshold_spoof' => env('FACE_LIVENESS_THRESHOLD_SPOOF', 0.40),
    ],
];
```

---

## 5. Decision Sign-off Gate

- [x] **APPROVED AS PROPOSED** — Activate proposed thresholds in pilot environment.
- [ ] **APPROVED WITH AMENDMENTS** — Modify values specified below before activation.
- [ ] **REJECTED / RETEST REQUIRED** — Return for additional calibration.

**Decision:** `PASS`  
**Approved Parameters:**
- `MATCH_THRESHOLD`: `0.80`
- `REVIEW_THRESHOLD`: `0.60`
- `AMBIGUITY_MARGIN`: `0.05`
- `DUPLICATE_REVIEW_THRESHOLD`: `0.65`
- `PAD_REAL_THRESHOLD`: `0.70`
- `PAD_SPOOF_THRESHOLD`: `0.40`
- `PAD_INCONCLUSIVE_RANGE`: `[0.40, 0.70)`

**Scope Constraints:**
- Pilot deployment only with approved 512-dimensional models
- Controlled lighting/camera conditions
- Cross-model vector comparison strictly prohibited

**Sign-off Date:** `2026-09-21`  
**Approved By:** Project owner (repository git user `satawad11`)  
**Module 03 Status:** `PASS`  
**Module 04 Status:** `READY`
