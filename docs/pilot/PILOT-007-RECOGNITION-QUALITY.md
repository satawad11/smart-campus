# PILOT-007 — Recognition Quality Review

**Status: PENDING — AWAITING PILOT ELAPSED TIME / REAL DATA**
**Threshold change proposed: NO** (no real pilot data exists to support any change)
**Prepared:** 2026-09-30 (Phase 08)

No recognition-quality measurement is recorded here. FAR, FRR and the match/non-match counts
below are computed only from **REAL PILOT DATA** with human-labelled ground truth. They are
never estimated, extrapolated from synthetic data, or back-filled.

---

## 1. Locked Thresholds (FACE-021, human-approved; must not be self-tuned)

| Parameter | Value | Config key | Verified by |
|---|---|---|---|
| MATCH | similarity ≥ 0.80 | `face.recognition.match_threshold` | `pilot:preflight` |
| REVIEW | 0.60 ≤ similarity < 0.80 | `face.recognition.review_threshold` | `pilot:preflight` |
| UNKNOWN | similarity < 0.60 | (derived) | — |
| Ambiguity margin | 0.05 (top-1 minus top-2 below the margin forces REVIEW) | `face.recognition.ambiguity_margin` | `pilot:preflight` |
| Duplicate enrollment review | ≥ 0.65 | `face.duplicate_review.similarity_threshold` | `pilot:preflight` |
| PAD REAL | score ≥ 0.70 | `face.liveness.threshold_real` | `pilot:preflight` |
| PAD SPOOF | score < 0.40 | `face.liveness.threshold_spoof` | `pilot:preflight` |
| PAD INCONCLUSIVE | 0.40 ≤ score < 0.70 | (derived; stored as `UNCERTAIN`) | — |

`pilot:preflight` fails if any configured value differs from this table. Recognition
classification (MATCH/REVIEW/UNKNOWN) and the access decision (ALLOW/REVIEW/DENY) stay separate.
This review measures **classification** quality only.

---

## 2. Data Labelling

| Label | Meaning | Allowed in the metrics below |
|---|---|---|
| REAL PILOT DATA | Events from the pilot cameras during the pilot, ground truth confirmed by an operator | YES |
| TEST DATA | Scripted walk-throughs by pilot staff on pilot hardware (e.g. SEC-020 re-execution) | Only in a separate, labelled column |
| SYNTHETIC DATA | Generated or simulated events (load tests, unit tests, rehearsal stack) | NO |

### Ground-truth procedure
1. Each pilot day, take a random sample of events from `GET /api/v1/history/search`
   (all classifications), up to 50 per day, plus **every** REVIEW and UNKNOWN event.
2. An operator marks each sampled event *genuine enrolled person X*, *different enrolled
   person*, *not enrolled*, or *presentation attack*, using the event snapshot in the
   restricted event detail view.
3. Store the labels as aggregate counts only. Don't copy snapshots, embeddings or
   student names into this document. Reference events by public UUID if needed.
4. Keep every failed recognition event. **Don't delete failed-recognition evidence.**

---

## 3. Metrics (fill in from labelled REAL PILOT DATA)

| Metric | Definition | Day 1–7 | Day 8–14 | Total |
|---|---|---|---|---|
| Labelled genuine attempts (N_g) | enrolled person presented to camera | | | |
| Labelled impostor attempts (N_i) | not enrolled, or a different person than the top match | | | |
| True matches | genuine → MATCH to the correct person | | | |
| False matches (count) | impostor or wrong person → MATCH | | | |
| False non-matches (count) | genuine → UNKNOWN | | | |
| Genuine → REVIEW | genuine routed to human review | | | |
| **FAR** | false matches / N_i | | | |
| **FRR** | false non-matches / N_g | | | |
| REVIEW rate | REVIEW / all events | | | |
| PAD: spoof attempts detected | SPOOF or INCONCLUSIVE / labelled attacks | | | |
| PAD: genuine flagged SPOOF | count | | | |

Targets from SEC-018 (for comparison, not pass/fail on their own): FAR ≤ 0.05 %,
TAR ≥ 98.5 %, PAD ≥ 99.0 %. With N_i in the low hundreds, a FAR of 0.05 % can't be
demonstrated statistically. Report the observed count together with the sample size.

---

## 4. Threshold Change Proposal

**Current proposal: NONE.** Real pilot data doesn't exist yet.

If the 7/14-day data justifies a change, the proposal must use the template below,
must **not** be activated, and needs FACE-021-style human approval before any
config change.

| Field | Value |
|---|---|
| Parameter | |
| Current value | |
| Proposed value | |
| Sample size (N_g / N_i) | |
| FAR at current / proposed | |
| FRR at current / proposed | |
| False match count at current / proposed | |
| False non-match count at current / proposed | |
| Impact analysis (REVIEW workload, security exposure, affected cohorts) | |
| Data window and label source | |
| Proposed by / date | |
| Human approval (name / date / decision) | |

---

## 5. Current Status per Item

| Item | Status |
|---|---|
| Thresholds match FACE-021 in config | PASS in rehearsal (`pilot:preflight`); PENDING on the pilot host |
| Ground-truth labelling procedure defined | DONE |
| Labelled real pilot data | AWAITING PILOT ELAPSED TIME / REAL DATA |
| FAR / FRR computed | AWAITING PILOT ELAPSED TIME / REAL DATA |
| Threshold change proposal | NONE (no data) |
