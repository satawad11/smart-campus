# PILOT-001 — Production Environment Validation

**Status: PASS (software checks, rehearsal environment) / PENDING (pilot host and physical infrastructure)**
**Executed:** 2026-09-30 02:12–02:19 +07:00 (2026-09-29 19:12–19:19 UTC)
**Executed by:** AI coding agent (Phase 08). No human operator was present.

---

## 1. Environment Identity (read this first)

| Item | Value |
|---|---|
| Environment validated | **LOCAL DOCKER REHEARSAL** on the development workstation (Windows 11, Docker Engine 29.8.1, compose project `smart-campus`). **This is not the pilot production host.** |
| Rehearsal database | `pilot_rehearsal` (separate from the dev database `smart_campus`) |
| Runtime | PHP 8.2.34, Laravel 12.69.2, PostgreSQL 16.15 + pgvector 0.8.6 + pg_trgm 1.6, Redis 7.4.10, MinIO (latest) |
| App config | `APP_ENV=production`, `APP_DEBUG=false`, least-privilege DB roles, `FEATURE_*_ENABLED=false`. Rehearsal env file kept outside the repository (it holds a generated `APP_KEY`) |
| Pilot production host | **Not available to the agent. Every host-level and physical check is PENDING (§4).** |

Everything marked PASS below is **TEST DATA / REHEARSAL** evidence. It shows that the
software and configuration are able to pass. It does not prove the pilot host passes.
On the pilot host, the SYSTEM_ADMIN reruns the same checks with
`php artisan pilot:preflight --require-backup` (added in this task).

---

## 2. Software and Configuration Checks

`pilot:preflight` is a new read-only command. It covers the SEC-024 conditions that software can
verify and exits non-zero on any failure.

| Preflight run (rehearsal) | Result | Why |
|---|---|---|
| First run | FAIL (1) | storage unreachable: buckets never provisioned (P08-13) |
| After bucket fix, before the default-secret check existed | PASS, 2 WARN | no backup yet |
| Default-secret check added; rehearsal still on dev secrets | **FAIL (5)** | `DB_PASSWORD` placeholder, MinIO `minioadmin` key and secret, and 2 backups whose files were lost (P08-16). This is the result the pilot host gets if dev defaults are left in place |
| **Final**: rehearsal secrets rotated to random values; persistent storage volume; fresh backup; container recreated | **PASS, 0 failures, 1 WARN** (exit 0) | WARN: Redis has no password (acceptable only if Redis isn't reachable outside the private network) |

| # | Check | Rehearsal result | Evidence |
|---|---|---|---|
| 1 | `FEATURE_DOOR/VISITOR/VEHICLE/ALPR/AI_ASSISTANT_ENABLED` explicitly false | **PASS** | preflight, all 5 "kill switch engaged" |
| 2 | Locked features effectively disabled | **PASS** | preflight; `/api/v1/features` returns all five `false`; locked routes return 401 unauthenticated and 403 `FEATURE_DISABLED` when authenticated (`FeatureKillSwitchTest`) |
| 3 | Admin can't enable a locked feature through settings | **PASS after fix** | smoke: `updateGroup(feature.door/alpr=true)` → still BLOCKED (transaction rolled back). **Before the fix this FAILED (P08-01)** |
| 4 | `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` set | **PASS** | preflight; 404 response carries no stack trace |
| 5 | CORS explicit, non-local, no wildcard | **PASS** | preflight; foreign origin not echoed |
| 6 | Secure session cookie | **PASS** | preflight `SESSION_SECURE_COOKIE=true` |
| 7 | Security headers (X-Frame-Options, nosniff, CSP, HSTS) | **PASS** | PILOT-005 smoke |
| 8 | DB connection is PostgreSQL through a least-privilege role | **PASS with defect** | app runs as `campus_app_user` (no superuser, createrole or createdb); migrations as `campus_migration_user`. `LEAST-PRIVILEGE-ROLES.sql` errors on `REVOKE ... DROP, TRUNCATE ON SCHEMA` (P08-11); the effective privileges are still correct |
| 9 | Queue not `sync`; queue and scheduler workers configured | **PASS after fix** | queue crash-loop and unconfigured scheduler fixed (P08-02); `schedule:list` shows `ai-nodes:detect-stale`, `events:refresh-summaries`, `retention:cleanup` |
| 10 | FACE-021 thresholds unchanged (0.80 / 0.60 / 0.05 / 0.65 / PAD 0.70 / 0.40) | **PASS** | preflight threshold group |
| 11 | Health: database, redis, storage, AI service | **PASS after fix** | `/health/*` all 200. Storage was FAIL until the buckets were provisioned and `AWS_BUCKET` pointed at an approved bucket (P08-13) |
| 12 | Health probes reachable through the nginx gateway | **PASS after fix (dev stack)** | `/health/*` fell through to the SPA and returned 200 HTML, so the runbook probes could never fail (P08-12). After the fix all six return `200 application/json` through nginx |
| 13 | No development test accounts active | **PASS** | preflight (guards against `InitialAdminUserSeeder`, P08-08) |
| 14 | Pilot scope ≤ 1 site / 2 cameras / 1 AI node / 80 students | **PASS (empty rehearsal DB)** | preflight scope group. Recheck on the pilot host after master data load |
| 15 | pg_dump available for backups | **PASS after fix** | `postgresql16-client` added to the image (P08-04) |
| 16a | No default or dev secrets (DB, MinIO key and secret, Reverb secret; placeholders `CHANGE_IN_PRODUCTION_*`) | **PASS (final)** | preflight `secrets` group; FAILs while dev defaults are present. Values are compared, never printed |
| 16b | Redis password | **WARN** | unset in the dev/rehearsal stack; set it on the pilot host or keep Redis on an isolated network |
| 16c | Production logging (level not `debug`) | **PASS** | `LOG_CHANNEL=stderr`, `LOG_LEVEL=info` (preflight) |
| 16d | Time sync: app vs database clock drift ≤ 5 s | **PASS** | 0.0 s drift; app timezone UTC. Host NTP itself is PENDING (H8) |
| 16e | Storage capacity ≥ 10 GiB free for storage and backups | **PASS** | 881.8 GiB free (workstation disk; the pilot host must be re-measured) |
| 16f | Backups persist across container recreation | **PASS after fix** | `backend-storage` volume (P08-16) |
| 16g | Reverb/WebSocket through the gateway | **PASS (dev stack)** | `101 Switching Protocols` at `/reverb/app/{key}` |
| 16h | Queue and scheduler running | **PASS (dev stack)** | queue up with no restarts; scheduler "Running scheduled tasks"; `schedule:list` shows 3 jobs |
| 16 | Production frontend build | **PASS** | `npm run build` succeeds; vitest 149/149. The compose `frontend` service runs the **Vite dev server** and must not be used in production (P08-14) |

### Dev stack (for contrast, not a pilot target)
The day-to-day development stack runs `APP_ENV=local`, `APP_DEBUG=true` and the owner DB user
`smart_campus`. That is correct for development and would **FAIL** `pilot:preflight`, as designed.

---

## 3. Defects Found and Repaired in This Task

Full details are in PILOT-008 §3. Summary:

| ID | Defect | Fix |
|---|---|---|
| P08-01 | FEATURE_* env vars ignored; locked features could be enabled via settings | `config/features.php` kill switch in `FeatureFlagService` (tests: `FeatureKillSwitchTest`) |
| P08-02 | Queue and scheduler containers had no configuration | `docker-compose.yml` `env_file: ./apps/backend/.env` |
| P08-12 | nginx didn't route `/health/*` | `infrastructure/nginx/default.conf` |
| P08-13 | MinIO buckets never provisioned; default `AWS_BUCKET` not an approved bucket | ran `scripts/provision-minio.sh` bucket list; `.env.example` `AWS_BUCKET=face-samples` |
| P08-16 | Backups stored in the container's writable layer, lost on recreate | compose `backend-storage` volume; preflight checks backup file and checksum |
| —      | No repeatable go-live check | new `php artisan pilot:preflight` (tests: `PilotPreflightCommandTest`) |

---

## 4. PENDING: Pilot Host and Physical Infrastructure (human or site action)

| # | Check | Status | Owner |
|---|---|---|---|
| H1 | Pilot host provisioned (OS, Docker or PHP-FPM stack per the chosen deployment model) | PENDING | infrastructure |
| H2 | DNS name and valid TLS certificate; HTTPS enforced at the gateway (nginx config is HTTP-only today) | PENDING | infrastructure |
| H3 | Production secrets in a secret store or protected env file (APP_KEY, DB, Redis, MinIO, Reverb, node tokens) with real passwords replacing `CHANGE_IN_PRODUCTION_*` | PENDING | SYSTEM_ADMIN |
| H4 | `pilot:preflight --require-backup` PASS on the pilot host | PENDING | SYSTEM_ADMIN |
| H5 | Reverb websocket over TLS (`wss://`) through the gateway | PENDING | infrastructure |
| H6 | 2 RTSP cameras reachable from the AI node; credentials stored encrypted | PENDING | site |
| H7 | Edge AI node installed, registered, heartbeat ONLINE | PENDING | site |
| H8 | Host time sync (NTP) and timezone; event timestamps match wall clock | PENDING | infrastructure |
| H9 | Backup destination off the host (off-site or separate volume) with retention | PENDING | infrastructure |
| H10 | External uptime and paging for `/health/full` (see PILOT-008 §2.3) | PENDING | infrastructure |
| H11 | Physical gate procedure stays manual (door control disabled) | PENDING confirmation | pilot lead |

**Do not enable the live gate until H1–H11 are resolved and PILOT-004 is signed.**

## Pre-Deployment Update (2026-09-30)

Production candidate: code at `72ee47b`, release tag `v1.0.0-pilot`. Full package and rehearsal: `PILOT-HOST-DEPLOYMENT-CHECKLIST.md`; delta review: `PILOT-000`.

**Status: PENDING HOST.** The pilot overlay (`docker-compose.pilot.yml`) was rehearsed as a separate compose
project on the workstation. Host checks H1–H14 are automated in `scripts/pilot-host-preflight.sh`. Rehearsal
result: H3–H10 and H12–H14 PASS; H1 (self-signed certificate) and H2 (no DNS name) FAIL, as they should on a workstation;
H10 external probe and H11 NTP are MANUAL. `pilot:preflight --require-backup` on the rehearsal fails only on
the local CORS origin `https://localhost:8443` (correct: local origins are refused).

Items from §4 now addressed in software: H2/H5 TLS gateway and wss (overlay), H3 secrets (template + preflight), H9 off-host
backups (implemented), H10 dependency/camera alerts (`system:monitor`); paging remains external (MANUAL).
**New blocker B5:** no production face or PAD model in the repository (PILOT-000 §11.1).
