# Work Backlog — Existing System Completion → Commercial V1

**Updated:** 2026-10-13 · Ordering follows the master plan: 1 security/data loss, 2 core defects,
3 production reliability, 4 AI node/camera, 5 backup/recovery, 6 test coverage, 7 docs/debt; then commercial.
State: `DONE` (committed, tests pass) · `DOING` · `TODO` · `BLOCKED` (owner in brackets) · `HUMAN` (decision only).

## Phase 1 — Complete the existing system

| ID | Pri | Item | State | Evidence / blocker |
|---|---|---|---|---|
| P1-01 | 2 | C-01 malformed uuid filter → 500 on PostgreSQL (incl. merge of `zen-villani` fix) | DONE | `MalformedUuidFilterTest`, `EventHistoryApiTest` on PostgreSQL |
| P1-02 | 7 | C-02 ruff format drift fails CI | DONE | `b4f9b3a` |
| P1-03 | 6 | C-03 PostgreSQL test gate (script + CI job) | DONE | `scripts/test-backend-pgsql.sh`, `ci.yml` `backend-pgsql` |
| P1-04 | 1 | C-04 dev seeder allowlist (`local`/`testing`) | DONE | `PilotBackupPolicyTest` |
| P1-05 | 7 | A-24 ApiException log level; A-28 env fallback | DONE | `ApiExceptionLogLevelTest` |
| P1-06 | 1 | A-15 MFA enrollment UI (setup / confirm / disable) using existing API | TODO | — |
| P1-07 | 3 | A-17 audit-log viewer UI (also COM-05) | TODO | — |
| P1-08 | 3 | A-17 incidents UI, session management UI | TODO | — |
| P1-09 | 1 | R-01/R-02 post-approval change review pack | DONE (pack) / HUMAN (approval) | `docs/pilot/PILOT-000B-*` [project owner] |
| P1-10 | 1 | A-18 server-side MATCH consistency check | HUMAN | behaviour change after approval [project owner] |
| P1-11 | 2 | A-14 SECURITY_OFFICER dashboard permission | HUMAN | RBAC matrix change [project owner] |
| P1-12 | 4 | A-21 real camera snapshot | HUMAN | accept mock or implement |
| P1-13 | 6 | A-22 summary refresh from ~1M raw events | TODO | needs PostgreSQL scale run |
| P1-14 | 7 | P08-24 Prettier drift (71 files) | TODO (low) | not a CI gate |
| P1-15 | — | B5 production biometric models | BLOCKED | [project owner: model choice + licence] |
| P1-16 | — | B-RET retention + RPO approval | BLOCKED | [project owner] |
| P1-17 | — | Pilot host H1/H2/H10/H11, PILOT-001..011 | BLOCKED | [pilot host + site team] |

## Phase 3 — Commercial (instance-side work valid under every tenancy option)

| ID | Item | State | Notes |
|---|---|---|---|
| COM-00 | ACR-COM-01 tenancy model | HUMAN | [project owner] |
| COM-02a | License domain: installation identity, signed payload (Ed25519), offline verification, grace, revocation list, audit | TODO | first commercial item |
| COM-02b | Entitlement enforcement (camera/node limits, features can only narrow flags) — modes `off`/`warn`/`enforce` | TODO | never deletes data, never blocks export |
| COM-02c | License issuer CLI (private key from outside the repo), license status API + UI, expiry alerts | TODO | |
| COM-03a | Node telemetry: disk, FPS, dropped frames, software + model version | DONE | node `2bafdbf`; backend + alerts (this commit); FPS/drops via camera health (existing) |
| COM-03b | Versioned node configuration with validation and rollback | TODO | |
| COM-03c | Allowlisted remote commands (restart service, resync config) with audit, timeout, result; no shell | TODO | |
| COM-03d | Model compatibility check (node model vs registry) | DONE | `model_compatible` on node API + `AI_NODE_MODEL_MISMATCH` alert; real models BLOCKED by B5 |
| COM-03e | One-time enrollment code flow | TODO | |
| COM-04a | Generic installer + preflight, env separation, release versioning, upgrade with pre-upgrade backup | TODO | |
| COM-05a | Alerts: queue backlog, disk threshold, license expiry | TODO | |
| COM-05b | Redacted support diagnostics bundle | TODO | |
| COM-06a | Institution data export + offboarding guide | TODO | |
| COM-01 / COM-06 portal | Tenant model, central plane, portal | BLOCKED | ACR-COM-01 |
| COM-07 | Integration API (tokens, scopes, rate limit, OpenAPI) | TODO (design) | partner systems unknown: no external API assumed |

## Phase 4 / 5 / 7 — Documents

| ID | Item | State |
|---|---|---|
| PDPA-01 | Biometric erasure `face:purge` (PDPA G-1) | DONE — `BiometricPurgeTest` (10) SQLite + PostgreSQL |
| DOC-01 | Security & privacy checklist, data inventory, data-flow map (`SECURITY_BASELINE`, `PDPA_DATA_MAP`) | TODO |
| DOC-02 | `docs/commercial/*` packaging set (13 documents) | TODO |
| DOC-03 | Model validation checklist (Phase 5) | TODO — execution BLOCKED by B5 |

## Resume instructions (after a context reset)
1. `git -C E:/smart-campus/.claude/worktrees/vibrant-yalow-69fe65 log --oneline -15` and read this file.
2. Backend tests: `cd apps/backend && php artisan test` (SQLite) and `bash scripts/test-backend-pgsql.sh` (PostgreSQL; needs Docker and the `smart-campus/backend:dev` image).
3. AI: `cd apps/ai-service && .venv/Scripts/python -m pytest -q && .venv/Scripts/ruff check . && .venv/Scripts/ruff format --check .`
4. Frontend: `cd apps/frontend && npm run lint && npm run test && npm run build`.
5. Continue with the first `TODO` row in priority order.
