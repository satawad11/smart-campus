# V1 Open Findings Register (Independent Pre-Pilot Re-Audit)

**Audit date:** 2026-09-30 · **Audited code:** `d497fb2` (fixes) on top of `v1.0.0-pilot` · **Auditor:** AI coding agent
Classification: severity BLOCKER / HIGH / MEDIUM / LOW / INFO; type CONFIRMED DEFECT, MISSING EVIDENCE,
DESIGN GAP, TEST GAP, DOCUMENTATION DRIFT, OPERATIONAL GAP, ACCEPTED RISK. Previously known Phase 08 items
(P08-xx, B-x) are repeated here, not hidden.

## 1. Fixed in this audit (confirmed defects)

| ID | Sev | Type | Finding | Evidence of defect | Fix / commit | Verification |
|---|---|---|---|---|---|---|
| A-05 | **BLOCKER** | CONFIRMED DEFECT | GLOBAL/SITE/ZONE scope enforced **only** on the Reverb channel. `PermissionService::applyScope()` had **no caller**; every REST endpoint treated a SITE/ZONE role as GLOBAL (AUTH-009 partially complete) | Real stack: officer scoped to site OTHER listed and opened a PILOT-site event (HTTP 200) | `EnforcesScope` applied to events, history, timeline, cameras, AI nodes and credentials, reports, dashboard, charts and exports (`5b04fac`) | `DataScopeEnforcementTest` (9 tests incl. zone and cross-site negatives); real stack: other-site list excludes the event and the open returns 404 |
| A-01 | **BLOCKER** | DESIGN GAP | No API, UI or production seeder can create the first administrator or assign any role (`user_roles` never written by application code). A fresh pilot host would have no usable account | Code search: only reads of `user_roles`; API contract lists no assignment endpoint | `access:bootstrap-admin`, `access:assign-role` (audited, idempotent CLI; no API contract change) (`87604a6`) | `AccessBootstrapCommandsTest` (SQLite + PostgreSQL); real stack: first admin bootstrapped, scoped officers created |
| A-04 | HIGH | CONFIRMED DEFECT | `must_change_password` enforced only by the frontend router | Real stack: bootstrap admin with pending forced change → `GET /api/v1/users` 200 | `RequirePermission` → 403 `PASSWORD_CHANGE_REQUIRED`; channel authorizer refuses (`2ffbe9c`) | `ForcedPasswordChangeTest`; real stack 403 |
| A-06 | HIGH | CONFIRMED DEFECT | Report export show/download had no owner check (IDOR for any `report.export` holder) | Code: only `index` filtered `user_id` | Owner-only, 404 (`5b04fac`) | `DataScopeEnforcementTest` |
| A-07 | HIGH | CONFIRMED DEFECT | Concurrent duplicate deliveries of one `event_uuid` → 500 for the race loser (unique index held, no duplicate row) | Real stack, 12 parallel deliveries: codes `[200, 201, 500]`; DB rows: 1 | Lost race treated as idempotent retry / 409 (`21ac74e`) | Real stack: `[200, 201]`; `IngestionResilienceTest` |
| A-08 | HIGH | CONFIRMED DEFECT | Broadcast + notification ran **inside** the ingestion transaction (docs/09 says commit → broadcast → notify): a Reverb outage would roll back and lose decided events; an event could be broadcast before commit | Code review | Broadcast/notify after commit; failures logged (`21ac74e`) | Failure injection: websocket stopped → ingest 201, event + decision persisted; `IngestionResilienceTest` |
| A-11 | HIGH | CONFIRMED DEFECT | Scheduler `withoutOverlapping` mutexes stored in Redis: a Redis outage stopped every guarded job (system:monitor → no REDIS_HEALTH alert; scheduled backups) | Failure injection: Redis down 75 s → no alert, `RedisException` in scheduler log | `Schedule::useCache('database')` (`d497fb2`) | Failure injection rerun: scheduler raised REDIS_HEALTH by itself |
| A-12 | HIGH | CONFIRMED DEFECT | `FACE_LIVENESS_ENABLED=false` (bypass REAL), `FACE_LIVENESS_PROVIDER=mock` and ai-service `AI_MODE=mock` weren't detected by any go-live check | Code: `FaceLivenessService` bypass branch | `pilot:preflight` FAILs each (`d497fb2`) | `PilotPreflightCommandTest`; real stack: preflight FAIL "AI_MODE=mock (blocker B5)" |
| A-02 | HIGH | DESIGN GAP | No way to create the Site / Gate zone that cameras and SITE/ZONE scopes require | Only `LoadTestEventIngestion` created sites | `locations:provision` (`87604a6`) | Tests + real stack |
| A-09 | MEDIUM | CONFIRMED DEFECT | Health probes went through session middleware (Redis): Redis outage → `/health/redis` opaque 500 | Failure injection: 500 | Probes without session/cookie/CSRF middleware (`d497fb2`) | Failure injection: 503 |
| A-10 | MEDIUM | CONFIRMED DEFECT | pdo_pgsql default 30 s connect timeout; requests hung when DB unreachable | Failure injection: > 15 s timeouts | `DB_CONNECT_TIMEOUT=5`, Redis timeouts 2 s (`d497fb2`) | Residual A-23 |
| A-03 | MEDIUM | CONFIRMED DEFECT / DOC DRIFT | Login had account lockout but no request throttle; SEC-013 table claims an IP throttle | Route list: `POST auth/login` without throttle | `throttle:login` 30/min/IP, 10/min/IP+user (`87604a6`) | `AccessBootstrapCommandsTest::test_login_is_throttled_per_ip` |
| A-13 | LOW | CONFIRMED DEFECT | Export list serialized raw models (numeric `id`, `user_id`, internal `file_path`) | Static scan of controllers | `ReportExport::$hidden` (`5b04fac`) | `DataScopeEnforcementTest` |
| A-16 | LOW | TEST GAP | The new `access:assign-role` passed on SQLite but failed on PostgreSQL (uuid column vs code string, 22P02), exactly the SQLite blind spot this audit targets | Real stack error | Fixed before commit; UUID case added to the test; PostgreSQL gate green | — |

## 2. Open findings

| ID | Sev | Type | Finding | Owner / decision needed |
|---|---|---|---|---|
| B5 | **BLOCKER** (live biometrics only) | DESIGN GAP | No production face detection, embedding or PAD model. ai-service implements only `AI_MODE=mock`; `onnxruntime` isn't a dependency; there are no model paths or weights. Live recognition and PAD aren't possible. PAD fail-open fixed (808acf9); preflight now FAILs on mock | Human: model selection, licensing, validation against FACE-021 |
| A-15 | HIGH | DESIGN GAP / FALSE COMPLETE (UI) | No MFA enrollment UI (setup/confirm/disable exist only as API; AUTH-015 UI covers MFA *verify* only). Operators and admins can't enroll MFA from the app | Human: build the UI before go-live, or accept API-only enrollment |
| A-14 | MEDIUM | DESIGN GAP | Dashboard API requires `report.view`; SECURITY_OFFICER holds only `dashboard.view`, and `/dashboard` is every user's landing page, so security officers land on 403 errors. Spec is ambiguous (Module 01 defines `dashboard.view`, Module 06 lists `report.view`) | Human RBAC decision: dashboard routes accept `dashboard.view`, or grant `report.view` to SECURITY_OFFICER |
| A-18 | MEDIUM | DESIGN GAP (security) | Ingestion trusts the node-asserted `classification=MATCH` + `candidate_person_uuid` without re-checking `similarity` against FACE-021 thresholds. A stolen node token could forge MATCH events for any person | Human: add server-side threshold consistency check (defense in depth) |
| A-17 | MEDIUM | DESIGN GAP (UI) | Backend-only capabilities with no UI: session management (self and admin), login history, audit-log viewer, notifications, student archive and photo, incidents | Human: prioritise; pilot operates via API/CLI where needed |
| A-19 | MEDIUM | MISSING EVIDENCE / FALSE COMPLETE | SEC-019 "Execute AI calibration workflow" is `[x]`, but `docs/pilot/AI-CALIBRATION-WORKFLOW.md` is a procedure with no results. It couldn't have been executed without a model (B5) | Execute after B5; treat SEC-019 as not evidenced |
| A-20 | MEDIUM | MISSING EVIDENCE | SEC-020 physical camera results have no raw evidence (P08-07) | Site team re-execution |
| A-21 | MEDIUM | DESIGN GAP | Camera snapshot always returns a labelled 1×1 mock placeholder; `CAMERA_SNAPSHOT_PROVIDER` is never read. The "Snapshot" UI action isn't a real capture | Human: accept for pilot (labelled MOCK) or implement |
| A-22 | MEDIUM | MISSING EVIDENCE | RPT-023 "million-event" test seeds pre-aggregated summary rows; refresh and backfill from ~1M raw events are untested | Test at scale before expansion |
| B-RET | MEDIUM | OPERATIONAL GAP | Backup retention values and the AR4 object-storage RPO exception are still unapproved; object storage RPO ≈ 60 min > 15 min target | Human approval (PILOT-BACKUP-RETENTION-POLICY) |
| A-23 | LOW | OPERATIONAL GAP | With the DB container stopped, `/health/database` answers 503 only after ≈ 16 s (DNS failure × connection retries) | External probe timeout ≥ 20 s |
| A-24 | LOW | CONFIRMED DEFECT | Expected business errors (e.g. 409 `EVENT_ALREADY_EXISTS`) are logged at ERROR level, adding log noise for alerting | Log at INFO/WARNING |
| A-29 | LOW | DESIGN GAP | FACE thresholds, liveness and session flags are env-overridable; preflight guards thresholds and PAD, the rest rely on safe defaults | Accept |
| P08-24 | LOW | TEST GAP | 71 files fail Prettier (not a CI gate) | Accept or reformat |
| P08-25 | LOW | CONFIRMED DEFECT | Dev Reverb public key hardcoded in `scripts/verify-realtime-load.js` | Read from env |
| A-25 | INFO | UNUSED | 9 seeded permissions never enforced: `dashboard.view`, `event.note`, `face.model.manage`, `face.model.view`, `policy.manage`, `policy.view`, `report.executive`, `report.system`, `student.export` | Review |
| A-26 | INFO | UNUSED/ALIAS | Duplicate route aliases: `faces/{}/duplicate-review` ≡ `face-profiles/{}/duplicate-review`; `faces/duplicate-reviews/{}/decision` ≡ `face-duplicate-reviews/{}/decision`; `faces/{}/verify` ≡ `face-profiles/{}/verify` | Keep or deprecate |
| A-27 | INFO | DESIGN | SYSTEM_ADMIN holds `*`, identical to SUPER_ADMIN | Review separation of duties |
| A-28 | INFO | CODE SMELL | `env()` fallback inside `FaceLivenessService` (unreachable because `services.ai.url` is set) | Remove |
| A-30 | INFO | TEST GAP (justified) | 8 SQLite-incomplete tests (enum CHECK enforcement is PostgreSQL-only); the PostgreSQL gate runs them (0 incomplete there) | Justified |
| A-31 | INFO | TEST GAP | Browser click-through smoke not performed (the built-in browser refused the self-signed rehearsal certificate); API-level real-path probe covers the backend flows | Do on the pilot host with the trusted certificate |
