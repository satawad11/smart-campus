# Owner Decision — 2026-11-23: make CCTV face recognition work for real

**How this was made:** in chat on 2026-11-23 the owner instructed (Thai): "If any process conflicts with the
established rules, delete the old rule. Do whatever it takes so the system really detects faces from CCTV cameras,
analyses and compares them with the data, and displays the results correctly — any method is fine."
This is an explicit owner instruction. It supersedes the rules listed below; nothing else is changed.

## Why recognition did not work
Measured on real face photos (5 identities) run through the real models (YuNet, SFace, MiniFASNet) at full size and
scaled down to CCTV sizes (120 → 24 px faces, JPEG q55–60, 1080p frame):

| Stage | Problem found | Effect |
|---|---|---|
| Deployment defaults | `AI_MODE` defaulted to `mock` in `docker-compose.yml` and `deploy/ai-node/docker-compose.yml` | synthetic results, no real detection |
| Backend thresholds | MATCH 0.80 / REVIEW 0.60 (FACE-021 placeholders) | SFace genuine pairs score 0.43–0.84 → most real matches became REVIEW/UNKNOWN |
| Liveness (node) | fail-closed: only REAL proceeds; MiniFASNet is a close-range model | real faces scored SPOOF/UNCERTAIN at 85–180 px → silently UNKNOWN |
| Quality gate (node) | enrollment-grade gate (30° yaw on an estimator that over-reads yaw ~1.5–2x, 100 px reference, blur ≥ 0.35) | usable frontal-ish faces rejected as BAD_POSE / IMAGE_BLUR |
| Cache (node) | a rejected / UNKNOWN attempt was cached for the whole 30 s TTL | a person first seen far away was never re-tried when closer |

Separation measured with SFace: same person 0.43–0.84, different people ≤ 0.31 (all scales ≥ 24 px).

## Decisions
| # | Rule replaced | New rule |
|---|---|---|
| R1 | FACE-021 thresholds 0.80 / 0.60 and the lab-only scope of OWNER-DECISIONS-2026-11-16-LAB-THRESHOLDS | Defaults for SFace: `FACE_RECOGNITION_MATCH_THRESHOLD=0.45`, `FACE_RECOGNITION_REVIEW_THRESHOLD=0.36` everywhere; `pilot:preflight` checks these values. Environment overrides still work. |
| R2 | Node liveness always fail-closed | `LIVENESS_MODE=advisory` default on the AI node: PAD runs on faces ≥ `LIVENESS_MIN_FACE_PX` (80) and is reported; SPOOF still becomes a `LIVENESS_FAILED` event; UNCERTAIN no longer hides the identity. `enforce` remains available for close-range access points and must be used before any door is driven (door stays disabled). Enrollment / webcam scan APIs are unchanged (still enforce). |
| R3 | One quality gate for enrollment and CCTV | AI node uses a CCTV gate (`CCTV_*` settings: 24 px, blur 0.20, yaw 65°, pitch/roll 35°, quality 0.45). Enrollment keeps the strict gate. |
| R4 | `AI_MODE=mock` default | `AI_MODE=onnx` default in compose files and `.env.example`; `mock` is for UI work only. |

## Unchanged
AI identifies → backend decides; no raw vectors leave the node/backend; door control stays off; audit and
historical-integrity rules apply. A site evaluation with consenting volunteers on the real cameras is still advised
to fine-tune R1; `python -m app.diagnose_camera` prints the per-face similarities needed for it.
