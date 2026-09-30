# V1 Independent Completeness & Defect Audit — Pre-Pilot Final Re-Audit

**Date:** 2026-09-30 · **Baseline audited:** `v1.0.0-pilot` (`ea2d69c`) + `e84d57f` · **Fixes:** `5b04fac`..`d497fb2`
**Auditor:** AI coding agent (adversarial; prior PASS decisions and TASKS.md checkmarks not trusted)
**Companion documents:** V1-REQUIREMENT-TRACEABILITY, V1-SECURITY-AUDIT, V1-OPERATIONS-AUDIT, V1-OPEN-FINDINGS

## 1. Method
1. Inventory: 157 routes, 49 migrations, 9 seeders, 115 backend test files, TASKS.md (159 complete).
2. Static analysis: route/permission matrix, scope-helper usage, env/config, frontend↔backend API
   contract (65 distinct frontend calls, all matched to routes; 55 backend routes unused by the UI),
   resources for ID/embedding leaks, secrets scan, seeders, migrations.
3. Real-path probes on the production-mode pilot overlay (TLS gateway, PostgreSQL 16, Redis, MinIO,
   Reverb, real queue worker and scheduler): browser-style auth, RBAC and scope over real sessions,
   AI-node credential through nginx, ingestion incl. 12 concurrent duplicates, WSS private-channel delivery,
   cross-site isolation, backup → off-host → restore into an empty DB with biometric object resolution.
4. Failure injection: Reverb, MinIO, Redis, PostgreSQL, AI service, queue stopped; full restart.
5. Full regression on both databases plus AI, frontend and container checks.

## 2. What the previous PASS decisions missed
The earlier suites (incl. SEC-021 UAT and SEC-023 security suite) passed, but they are mostly in-process
`actingAs` tests (703 calls) without cross-scope negatives. They therefore couldn't see:
- **A-05 BLOCKER:** data scope enforced only on the WebSocket channel, not on any REST endpoint.
- **A-01 BLOCKER:** no path to create the first admin or assign any role on a fresh host.
- **A-04:** forced password change enforced only in the browser.
- **A-06:** export IDOR.
- **A-07 / A-08:** a concurrency race and broadcast inside the transaction, visible only with real
  parallel HTTP and a stopped Reverb.
- **A-09 / A-10 / A-11:** failure-mode defects visible only with real Redis/PostgreSQL outages. The
  scheduler couldn't alert or back up during a Redis outage.
- **A-12:** PAD can be disabled or mocked by configuration without any go-live check.
The audit also caught its own SQLite-only blind spot (A-16) before commit.

## 3. Results
| Metric | Value |
|---|---|
| Requirements reviewed | 159 completed tasks across FND, M01–M07 and Phase 08 |
| Requirements traceable to implementation + tests | 159 by behaviour (132 by ID reference; the rest traced by behaviour) |
| FALSE COMPLETE / MISSING EVIDENCE | AUTH-009 scope (fixed), SEC-019 calibration, SEC-020 camera tests, RPT-023 raw-event scale, FACE providers for production (B5), MFA enrollment UI (A-15) |
| Confirmed defects found | 13 CONFIRMED DEFECT: A-03, A-04, A-05, A-06, A-07, A-08, A-09, A-10, A-11, A-12, A-13, A-24, P08-25; plus 2 blocking/high DESIGN GAPs fixed operationally (A-01, A-02) and 1 TEST GAP in this audit's own code (A-16) |
| Defects auto-fixed | 11 confirmed defects (A-03..A-13) + A-01, A-02 (CLI path) + A-16 (pre-commit). Not auto-fixed: A-24, P08-25 (LOW) |
| Open BLOCKER | B5 (no production biometric model), which blocks **live biometric operation** only |
| Open HIGH | A-15 (no MFA enrollment UI) |
| Open MEDIUM | A-14, A-17, A-18, A-19, A-20, A-21, A-22, backup retention/RPO approval |
| Open LOW / INFO | A-23, A-24, A-29, P08-24, P08-25 / A-25..A-28, A-30, A-31 |

## 4. Architecture invariants (re-verified)
| Invariant | Result |
|---|---|
| Person is the identity root; StudentProfile academic; User login-only (`person_id` prohibited in user requests) | HOLDS |
| FaceProfile belongs to Person; model-scoped vector search | HOLDS |
| MATCH/REVIEW/UNKNOWN (classification, event metadata) separate from ALLOW/REVIEW/DENY (decisions) | HOLDS |
| Only the Event module creates access decisions; no Module 03/04 decision path | HOLDS |
| Academic-status changes don't modify biometric or login identity | HOLDS |
| GLOBAL/SITE/ZONE scope enforced centrally | **Did not hold** (A-05); **holds after fix** via the central `PermissionService::applyScope` |
| Future modules locked | HOLDS (kill switches, 403 gates, no implementation) |

## 5. Final repository gate

## **READY FOR PILOT WITH CONDITIONS**

The repository can be placed on the real pilot host with the Phase 08 deployment package. Conditions:
1. **B5:** live biometric recognition and PAD stay **HOLD** until a human approves production models. With
   `AI_MODE=mock`, `pilot:preflight` FAILs by design, so a compliant live go-live is impossible until then.
2. **A-15:** decide MFA enrollment (build the UI or accept API-only enrollment) before admin accounts go live.
3. **A-14:** decide the dashboard permission for SECURITY_OFFICER.
4. **Backup retention values and the AR4 RPO exception:** human approval.
5. Pilot host checks H1–H14 and PILOT-001..005 on the real host; operator onboarding (PILOT-004).

Not self-approved: pilot acceptance, scope expansion, threshold changes, retention policy, RBAC-matrix
changes and model selection remain human decisions. Future modules remain **LOCKED**.
