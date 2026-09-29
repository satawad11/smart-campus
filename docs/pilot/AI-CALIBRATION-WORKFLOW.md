# AI Threshold Calibration Workflow (SEC-019)

## 1. Overview & Regulatory Precedents
This workflow establishes the mathematical and operational procedures for validating and adjusting facial recognition and liveness thresholds based on pilot data.
As approved in **FACE-021** (`docs/FACE-021-PILOT-THRESHOLDS-REVIEW.md`), baseline thresholds for the pilot are:
- `face.match_threshold`: **0.80**
- `face.review_threshold`: **0.60**
- `face.ambiguity_margin`: **0.05**
- `face.duplicate_review_similarity_threshold`: **0.65**
- `face.liveness_threshold_real`: **0.70**
- `face.liveness_threshold_spoof`: **0.40**

## 2. Calibration Procedure

### Phase 1: Distribution Analysis
1. Extract cosine similarity scores from pilot walkthrough logs:
   - Genuine pairs (same enrolled person vs live probe)
   - Impostor pairs (different persons vs live probe)
2. Generate genuine vs impostor score distribution histograms.
3. Compute False Match Rate (FMR) and False Non-Match Rate (FNMR) at 0.01 increments between 0.50 and 0.90.

### Phase 2: Boundary Verification
- Verify that `MATCH_THRESHOLD` (0.80) satisfies the campus safety policy requirement of FMR < 0.01%.
- Verify that `REVIEW_THRESHOLD` (0.60) captures ambiguous attempts (e.g. heavy shadows or partial occlusions) into operator review queue without auto-allowing or auto-denying.
- Verify that duplicate-review threshold (0.65) successfully catches cross-student re-enrollments.

### Phase 3: Change Control & Governance
Threshold updates cannot be applied directly to production by automated jobs.
1. All recommended adjustments must be documented in a calibration report.
2. Changes require review and sign-off by:
   - Lead AI Research Engineer
   - Chief Information Security Officer / System Admin
3. Approved values are committed via `SystemSettingService` under the `FACE` configuration group and audited.
