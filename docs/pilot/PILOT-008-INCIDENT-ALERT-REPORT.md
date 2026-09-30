# PILOT-008 — Incident and Alert Report

**Status: PARTIAL**
- Pre-pilot technical incidents (Phase 08 rehearsal): **recorded and remediated** (§3)
- Alert producers for AI-node outage and backup failure: **implemented and tested** (§2)
- Live pilot incident log: **AWAITING PILOT ELAPSED TIME / REAL DATA** (§4)
- External paging (email, PagerDuty): **PENDING**. Not implemented in V1 (§2.3)
- Pre-deployment review (2026-09-30): dependency, camera and queue alert producers added (§2.2); findings P08-20 to P08-24 added (§3)

**Prepared:** 2026-09-30 (Phase 08)

---

## 1. Severity Model

| System alert severity (`system_alerts`) | Incident severity (`system_incidents`) | Pilot meaning | Response target |
|---|---|---|---|
| CRITICAL | SEV1 | Pilot gate can't be monitored, data-integrity or security exposure | Acknowledge ≤ 15 min, pilot lead informed immediately |
| WARNING | SEV2 | Degraded: one camera down, elevated REVIEW rate, delayed events | Acknowledge ≤ 1 h |
| INFO | SEV3 | Cosmetic, or has a workaround | Next business day |

Lifecycle (SEC-012): alert `OPEN → ACKNOWLEDGED → RESOLVED`; incident with post-mortem
(SEC-014). Both are audited. Alerts and incidents must never be deleted to tidy up the record.

---

## 2. Alert Coverage

### 2.1 Before Phase 08
`AlertLifecycleService::triggerAlert()` had **no production caller**. The alert
dashboard could only show alerts created manually. A stale AI node wrote an OFFLINE
health-log row but raised no alert. A failed backup raised nothing. This contradicted the
alert table in `docs/security/MONITORING-OBSERVABILITY.md` (SEC-013). Issue P08-06.

### 2.2 Implemented in Phase 08 (automatic producers)

| Condition | Producer | Alert type / severity | Deduplication | Test |
|---|---|---|---|---|
| AI node heartbeat stale | `ai-nodes:detect-stale` (every minute) | `AI_NODE_DOWN` / CRITICAL | one per stale period (same rule as the OFFLINE health log) | `PilotAlertProducersTest` |
| Backup job, restore drill or off-host copy failed | `backup:run` | `BACKUP_FAILED` / CRITICAL | one per failed run | `PilotAlertProducersTest`, `BackupIntegrityTest`, `PilotBackupPolicyTest` |
| Redis, object storage or AI service unreachable | `system:monitor` (every minute) | `REDIS_HEALTH` / `STORAGE_HEALTH` / `AI_SERVICE_HEALTH`, CRITICAL | one unresolved alert per dependency | `MonitorSystemHealthTest`; rehearsal: stopping ai-service raised 1 alert over 2 runs |
| ACTIVE camera with no health report > 120 s | `system:monitor` | `CAMERA_DOWN` / WARNING | one unresolved alert per camera | `MonitorSystemHealthTest` |
| Default queue > 1000 jobs | `system:monitor` | `QUEUE_BACKLOG` / WARNING | one unresolved alert | — |

### 2.3 Not covered (PENDING, human decision)

| Condition in SEC-013 table | Current state | Pilot mitigation |
|---|---|---|
| PostgreSQL unavailable | Can't be stored as an alert (the alert store is PostgreSQL); `system:monitor` logs CRITICAL and exits 1; `/health/full` returns 503 | External uptime probe on `/health/full`. PENDING (host) |
| Camera stream offline > 2 min | **Covered** by `CAMERA_DOWN` (§2.2) | — |
| Login failure surge | Account lockout (AUTH-006) and login history; no alert row | SYSTEM_ADMIN reviews login history daily |
| Email / PagerDuty dispatch | Not implemented | SYSTEM_ADMIN checks `/system/health` at shift start and mid-shift. External paging is a candidate post-pilot change, not a pilot blocker, if the human accepts the risk |

---

## 3. Phase 08 Technical Incident Log (pre-pilot, rehearsal environment)

These are **real defects** found while validating the environment and deployment for the
pilot. They were found on the local Docker rehearsal stack, not on the pilot host. None
affected pilot users, because the pilot hasn't started.

| ID | Found in | Severity | Summary | Resolution | Status |
|---|---|---|---|---|---|
| P08-01 | PILOT-001 | HIGH (security) | `FEATURE_*_ENABLED` variables required by SEC-024 condition 1 weren't read by any code. An admin could enable `door`/`visitor`/`vehicle`/`alpr`/`ai_assistant` through the settings API. The SEC-024 claim "strictly hard-disabled" didn't hold | Env kill switch `config/features.php`: explicit false overrides settings; unparseable values fail closed; `pilot:preflight` fails unless all five are explicitly false | FIXED, TESTED |
| P08-02 | PILOT-001 | HIGH | Queue container crash-looped (no config, fell back to sqlite); scheduler ran with no configuration and a stale image, so no scheduled job (stale-node sweep, summaries, retention) had ever run in this stack | Compose backend `env_file` now points to `apps/backend/.env`; containers recreated | FIXED, VERIFIED IN REHEARSAL |
| P08-03 | PILOT-002 | MEDIUM | Running backend and frontend images predated the approved commit (built 2026-09-19/23, missing Module 07 code); the dev database was missing 7 migrations (Module 06 summaries/exports and all Module 07 tables) | Images rebuilt from a clean export of the release commit; dev stack recreated; dev DB dumped, then migrated | FIXED IN REHEARSAL |
| P08-04 | PILOT-003 | HIGH (data integrity) | `pg_dump` wasn't installed in the backend image. The PostgreSQL backup wrote an empty file (or a one-line "simulated export") and recorded it COMPLETED. `backup:run --verify` exited 0 even when the restore drill failed. SEC-024 condition 2 couldn't have been met truthfully | `postgresql16-client` added to the image. A pg_dump failure or empty output fails the job. The drill requires a real pg_dump header. `--verify` fails on any unverified drill. Password passed via the environment, not argv | FIXED, TESTED |
| P08-05 | PILOT-003 | HIGH (data integrity) | `backup:run --type=STORAGE` archived only the local `public` disk. Face samples, person photos and event snapshots in MinIO weren't backed up, although SEC-008 said they were | Storage backup now includes the configured object-storage disks (`config/backup.php`); an unreadable disk fails the job | FIXED, TESTED |
| P08-06 | PILOT-008 | HIGH | No automatic alert producers (§2.1) | AI-node-down and backup-failed producers | FIXED (partial, see §2.3) |
| P08-07 | PILOT-006 | MEDIUM | SEC-020 physical test results have no raw evidence in the repository | Re-execute on pilot hardware (PILOT-006 §5) | OPEN, human action |
| P08-08 | PILOT-002 | MEDIUM (security) | Commit 75f98bb added `InitialAdminUserSeeder` (5 accounts, one shared known password, MFA off, no forced change) | Not called by `DatabaseSeeder`. `pilot:preflight` fails if any of those accounts are active. Removal or restriction is a human decision | MITIGATED, human decision |
| P08-09 | PILOT-002 | MEDIUM | `DEPLOYMENT.md` checks out tag `v1.0.0-pilot`, which doesn't exist, and assumes systemd/supervisor, while the repository ships Docker Compose | DEPLOYMENT.md and ROLLBACK.md rewritten for Docker Compose; tag `v1.0.0-pilot` created on the production candidate | FIXED (pre-deployment review) |
| P08-10 | PILOT-004 | LOW | No incident UI; incidents are managed through the API only | Documented in PILOT-004 E3 | ACCEPTED FOR PILOT (proposed) |
| P08-11 | PILOT-001 | MEDIUM | `docs/security/LEAST-PRIVILEGE-ROLES.sql` (SEC-005) fails at `REVOKE CREATE, DROP, TRUNCATE ON SCHEMA public` (`unrecognized privilege type "drop"`) and hardcodes database `smart_campus`. Effective privileges were still correct in rehearsal (app user: no superuser, no DDL) | Script fixed (valid REVOKEs, psql variables, idempotent) and validated by execution: app role DML only, backup role read-only, monitoring role no table data | FIXED (pre-deployment review) |
| P08-12 | PILOT-005 | HIGH | nginx didn't route `/health/*` to the backend: the SPA answered 200 HTML, so the runbook health probes couldn't detect an outage | `location ~ ^/health(...)$` added to `infrastructure/nginx/default.conf` | FIXED |
| P08-13 | PILOT-001 | HIGH | Dev MinIO had none of the approved buckets. The default `s3` disk bucket (`AWS_BUCKET=smart-campus`), which `/health/storage` probes, isn't an approved bucket, so storage health could never pass | Buckets provisioned from `infrastructure/minio/buckets.txt`; `.env.example` `AWS_BUCKET=face-samples`. FND-010 probe and its test unchanged | FIXED |
| P08-14 | PILOT-001 | MEDIUM | Compose `frontend` service runs the Vite **dev server**; no production static-serving path exists in the repository | `apps/frontend/Dockerfile.prod` (static nginx image) used by `docker-compose.pilot.yml`; verified through the TLS gateway | FIXED (pre-deployment review) |
| P08-16 | PILOT-003 | HIGH (data integrity) | Backups were written to `storage/app/backups` in the backend container's writable layer, with no volume. Recreating the container (every deploy) destroyed them, while their `backup_jobs` rows still said VERIFIED. Reproduced in rehearsal: after a recreate, both earlier VERIFIED backups were gone | Compose `backend-storage` volume shared by all backend processes; `pilot:preflight` now requires the backup file to exist with a matching checksum. Off-host copy still required (PILOT-001 H9) | FIXED, VERIFIED IN REHEARSAL |
| P08-17 | PILOT-003 | LOW | Backup files are created with mode 0644 inside a root-only 0750 directory | Backup files and off-host copies created 0600 | FIXED (pre-deployment review) |
| P08-18 | PILOT-009 | MEDIUM | `backup:run` isn't scheduled anywhere, although SEC-007 says "daily"; backup files have no retention | Scheduled backups (DB 10 min, storage hourly), `backup:prune` retention, verified off-host copy; values AWAITING HUMAN RETENTION APPROVAL | FIXED (mechanism); values awaiting approval |
| P08-19 | PILOT-002 | LOW (test) | Phase 08's own new `PilotPreflightCommandTest` switched `database.default` mid-test. Under the PostgreSQL gate this left the RefreshDatabase transaction open and hung the suite (two consecutive gate runs stalled until stopped). Found before commit | The test no longer switches the connection; it expects the driver-dependent result | FIXED BEFORE COMMIT |
| P08-15 | PILOT-002 | LOW | `pint --test` reports a pre-existing style issue in `routes/api.php` (fully qualified controller names) | Fixed with pint (imports only); route table verified identical apart from closure line numbers | FIXED (pre-deployment review) |
| P08-20 | PRE-DEPLOY | HIGH (security) | Outside `AI_MODE=mock`, the PAD factory fell back to the mock detector, which scores every image 0.95 (REAL): **PAD failed open** | `UnavailableFaceLivenessDetector`; `/internal/v1/face/liveness` → 503 `PAD_MODEL_UNAVAILABLE` (commit 808acf9) | FIXED |
| P08-21 | PRE-DEPLOY | **BLOCKER** | No production biometric pipeline: ai-service implements only mock; no onnxruntime dependency, model paths or weights | Human decision on models (PILOT-000 B5). Pilot overlay requires an explicit `AI_MODE` | OPEN, human decision |
| P08-22 | PRE-DEPLOY | MEDIUM | `minio-init` used `grep`, which the `minio/mc` image lacks: no buckets were created, yet it reported success (found in rehearsal) | Shell builtins only; fails when no buckets are read; re-run idempotently | FIXED |
| P08-23 | PRE-DEPLOY | LOW | `npm run lint` (a CI gate) failed with 5 unused-variable errors at the approved baseline | Unused declarations removed | FIXED |
| P08-24 | PRE-DEPLOY | LOW | `prettier --check` reports 71 files (not a CI gate) | Not reformatted, to avoid churn | ACCEPTED (proposed) |
| P08-25 | PRE-DEPLOY | LOW | `scripts/verify-realtime-load.js` (EVT-021) hardcodes the development stack's Reverb app key. It's a public, browser-visible key, not a secret; pilot keys are generated per host and aren't in the repository | Read the key from the environment when the script is next touched | OPEN (LOW) |

---

## 4. Live Pilot Incident and Alert Log (REAL PILOT DATA only)

| Date/time (production TZ) | Alert or incident ID | Type | Severity | Detected by | Acknowledged (who/when) | Resolved (who/when) | Root cause | Follow-up |
|---|---|---|---|---|---|---|---|---|
| | | | | | | | | |

Weekly totals (fill in from `GET /api/v1/system/alerts` and `/system/incidents`):

| Week | Alerts CRITICAL | Alerts WARNING | Median time to acknowledge | Incidents SEV1 | SEV2 | SEV3 | Open at week end |
|---|---|---|---|---|---|---|---|
| 1 | | | | | | | |
| 2 | | | | | | | |
