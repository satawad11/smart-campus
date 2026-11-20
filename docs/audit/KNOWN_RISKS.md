# Known Risks — Commercial Edition Baseline

**Date:** 2026-10-13 · Status legend: see [CURRENT_STATE_AUDIT](CURRENT_STATE_AUDIT.md). Likelihood/Impact: H/M/L.
Earlier findings (A-xx, B-x, P08-xx) keep their IDs from [V1-OPEN-FINDINGS](V1-OPEN-FINDINGS.md).

| ID | Risk | L | I | Status | Mitigation / owner |
|---|---|---|---|---|---|
| **R-01** | The deployment runbook checks out `v1.0.0-pilot` (`ea2d69c`), which **predates the BLOCKER fixes** A-05 (REST scope enforcement) and A-01 (first-admin path) plus A-04/A-06/A-07/A-08/A-11/A-12. A deployment that follows the runbook today ships known BLOCKER defects | H | H | VERIFIED | Human change review of `5b04fac`..`2e4605a` (+ this branch), then a new RC tag and runbook update. Prepared: [PILOT-000B change review](../pilot/PILOT-000B-POST-APPROVAL-CHANGE-REVIEW.md). Owner: project owner |
| R-02 | Six behaviour-changing commits after the last human approval (B4, `e84d57f`) are unreviewed by a human; this branch adds more | H | M | VERIFIED | Same change review as R-01; don't deploy un-reviewed code to the pilot |
| B5 | No production face detection/embedding/PAD model; live biometric operation impossible | — | H | VERIFIED | Human model selection + licence + evaluation. Non-biometric pilot mode possible meanwhile |
| R-03 | Commercial tenancy model undecided; COM-01/COM-06 can't start | H | H | VERIFIED | [ACR-COM-01](../commercial/ACR-COM-01-TENANCY-MODEL.md) decision |
| R-04 | No git remote: no off-machine copy of the source history, no hosted CI execution, no PR review trail. A workstation disk failure loses everything not backed up elsewhere | M | H | VERIFIED | Owner to add a private remote (and backup of the repository). Agent won't push without approval |
| R-05 | CI ran only SQLite; PostgreSQL-only defects (A-16, C-01) shipped unseen | H | M | VERIFIED → mitigated in this branch | PostgreSQL CI job + `scripts/test-backend-pgsql.sh` |
| R-06 | License private signing key custody: if it leaks, anyone can mint licenses; if lost, no renewals | M | H | NOT VERIFIED (no key exists yet) | Key generated and held offline by the vendor (HSM/encrypted vault); never in the repo, frontend or node images. Rotation procedure in LICENSE_MODEL |
| R-07 | License enforcement mistakes could lock an institution out of safety-relevant monitoring or its own data | M | H | Design control | Enforcement never deletes data, never blocks export/audit/health, only blocks *growth* (new cameras/nodes) and new enrollment after grace; default mode `warn` on existing pilot |
| A-15 | No MFA enrollment UI: admins can't enrol MFA from the app | H | M | VERIFIED | Build UI (backlog P1-06) |
| A-14 | SECURITY_OFFICER lands on a 403 dashboard | H | L | RESOLVED | Dashboard accepts `dashboard.view` (`144164d`); owner decision 2026-11-20 (in chat): SECURITY_OFFICER keeps `dashboard.view`; no `report.*`/`system.*` added; migration `2026_11_20_700001` |
| A-18 | Ingestion trusts node-asserted MATCH without re-checking similarity vs thresholds; a stolen node token could forge MATCH events | L | H | REPORTED | Human decision on server-side consistency check (behaviour change) |
| A-21 | Camera "Snapshot" is a labelled mock placeholder | H | L | REPORTED | Accept for pilot or implement |
| B-RET | Backup retention values + object-storage RPO (≈60 min vs 15 min target) unapproved | — | M | REPORTED | Human approval |
| R-08 | Concurrent AI-agent sessions on the same machine (other worktrees, one empty checkout with every file staged as deleted) could produce conflicting branches | M | M | VERIFIED | Owner merges deliberately; this branch is rebased-free and self-contained |
| R-09 | Edge node kit (`deploy/ai-node`) never executed on real hardware or with a real RTSP camera | H | M | VERIFIED (no evidence) | Lab validation on target hardware; PILOT-006 |
| R-10 | PDPA compliance not established: no data inventory/flow map, DSR workflow, DPA template or legal review | H | H | VERIFIED | Phase 4 documents + legal review (human) |
| R-11 | Local test runs use Python 3.14 while CI/Docker pin 3.12 | L | L | VERIFIED | CI is authoritative for 3.12 |
| A-23 | `/health/database` takes ≈16 s to fail when DB is down | M | L | REPORTED | External probe timeout ≥ 20 s |
