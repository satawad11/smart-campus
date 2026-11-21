# Owner decisions — 2026-11-20

Given in chat by the project owner. The owner wrote "อนุมัติทุกอย่าง" (approve everything); each item
was then confirmed separately with the options below. The chosen option is recorded as given.

| # | Item | Decision | What it allows | What it does not |
|---|---|---|---|---|
| 1 | VEH-007 / ACR-VEH-01 D4 — ALPR model | **Build with the shortlisted open models**: LPD-YuNet (plate detector, OpenCV Zoo) + PaddleOCR `th_PP-OCRv5_mobile_rec` (Thai OCR), both Apache-2.0 per `VEH-007-ALPR-LICENCE-CHECK.md` | Start VEH-008 (node plate pipeline) with these models, vendored with licence texts and SHA-256 like ACR-AI-01 | Plate reading stays off on every camera until the models are evaluated on about 100 real gate-camera plate photos (day, night, backlight) and the owner approves the thresholds. The 2 images sent on 2026-11-20 (a sample plate graphic and a plate-type chart) are reference only, not an evaluation set |
| 2 | ACR-EVT-24 — face image on unknown/review alerts, enrollment from an alert | **Approved as written** (the four proposals in "Decisions needed") | EVT-025 may start | Raw frames in event JSON stay rejected (alternative C); enrollment only through the existing person selection, consent, quality and audit workflow |
| 3 | AUDIT-A14 — SECURITY_OFFICER lands on a 403 dashboard | **Approved**: give SECURITY_OFFICER `dashboard.view` | A small RBAC change (seed + least-privilege SQL), its own task | — |
| 4 | AUDIT-A15 — MFA enrollment UI | **Not approved this time** (not selected) | — | Stays open |
| 5 | PR #19 — escape one-time password console output | **Merge into master** | Merge, then bring master into PR #18 | — |

Not covered by "approve everything" because they are not approvals the owner can give in chat, or were
already decided: PDPA legal review (counsel), picomatch (owner chose to wait for an upstream patch),
pilot acceptance PILOT-011 (signed after the pilot), barrier control (Phase 2, own ACR).

## Plate formats seen in the reference chart (input for VEH-008)

The chart lists plate colours by vehicle type (private car, taxi, pickup, motorcycle, public transport,
special and temporary plates) and two formats the shared rules (`contracts/thai-plates.json`) do not
accept yet: one Thai letter followed by `99-1193`-style digits (special-purpose plates shown in the chart).
Plate colour is not stored. Whether to add those formats or a plate category is a VEH-008 design item;
the current rules cover ordinary private, commercial and numeric plates.
