# Current State Audit — Commercial Edition Baseline

**Audit date:** 2026-10-13 · **Branch:** `claude/vibrant-yalow-69fe65` (development worktree) · **Base commit:** `2e4605a`
**Auditor:** AI coding agent · **Companion documents:** [COMMERCIAL_GAP_ANALYSIS](COMMERCIAL_GAP_ANALYSIS.md),
[KNOWN_RISKS](KNOWN_RISKS.md), [WORK_BACKLOG](WORK_BACKLOG.md). Builds on the independent V1 re-audit of
2026-09-30 (`V1-*.md` in this folder), which is not repeated here; this document re-checks and extends it.

Status legend used in every audit document:

| Status | Meaning |
|---|---|
| **VERIFIED** | Checked against code, git history or a test run executed during this audit |
| **REPORTED** | Found only in an earlier report; not re-executed in this audit |
| **NOT VERIFIED** | No evidence either way yet |
| **BLOCKED** | Needs a permission, resource or human approval this agent doesn't have |

## 1. Repository and git state

| Item | Finding | Status |
|---|---|---|
| Repository | Local git repository `E:\smart-campus`; **no git remote configured** (`git remote -v` empty), so there is no hosted CI run history or release page to inspect | VERIFIED |
| Branches | `master` = `2e4605a` (default; CLAUDE.md calls it `main`, but the branch is `master`). Worktree branches: `claude/vibrant-yalow-69fe65` (this work), `claude/funny-dhawan-c17d79` (48 commits behind, nothing unmerged), `claude/zen-villani-495b6e` (**2 unmerged commits**: uuid validation fix, see §4), `claude/smart-campus-commercial-v1-dca2af` (empty checkout created 2026-10-13, every file staged as deleted, no content; left untouched) | VERIFIED |
| Uncommitted user work | None on `master` or in this worktree at the start | VERIFIED |
| Tags | `foundation-v1` (`9b70753`), `v1.0.0-pilot` → annotated tag on **`ea2d69c`** ("Pilot V1 production candidate (code 72ee47b; …)") | VERIFIED |
| Approved vs candidate | SEC-024 human GO WITH CONDITIONS on `acbad50`; Phase 08 corrective changes `f36a347`..`72ee47b` human-approved as B4 (`e84d57f`, 2026-09-30) | REPORTED (approval records in `docs/pilot/PILOT-000`, `docs/SEC-024-*`) |
| **Post-approval delta** | Six behaviour-changing commits after `e84d57f` have **no recorded human change review**: `5b04fac` (REST scope enforcement, BLOCKER A-05), `2ffbe9c` (server-side forced password change), `87604a6` (bootstrap CLI, login throttle), `21ac74e` (ingestion race, broadcast after commit), `d497fb2` (fail-fast deps, scheduler, preflight), `2e4605a` (edge node orchestrator). 63 files, +4,537/−114 vs `v1.0.0-pilot` | VERIFIED (git) |
| **Runbook vs fixes** | `docs/runbooks/DEPLOYMENT.md` deploys `git checkout tags/v1.0.0-pilot`. That tag **predates** the BLOCKER fixes A-05 and A-01: a host deployed by the runbook today would run REST endpoints with no SITE/ZONE scope enforcement | VERIFIED — see risk R-01 |
| Other projects | Docker also runs `dmt-central-platform-*` containers from `E:\DMT Central Platform`, a separate repository. Out of scope; not touched | VERIFIED |

## 2. Technology stack (as built)

| Layer | Locked spec | Actual | Status |
|---|---|---|---|
| Frontend | Vue 3, Vite, Tailwind, Pinia, Vue Router, Axios | Same (`apps/frontend`); production image `Dockerfile.prod` serves the static build through nginx, no Vite dev server | VERIFIED |
| Backend | Laravel REST, Reverb, Queue, Scheduler | Laravel 12, PHP 8.2+, Sanctum session auth, Reverb, database-cache scheduler locks | VERIFIED |
| Data | PostgreSQL + pgvector + pg_trgm, Redis, S3-compatible | `pgvector/pgvector:pg16`, Redis 7, MinIO | VERIFIED |
| AI | Python, FastAPI, OpenCV, ONNX Runtime | FastAPI + OpenCV + NumPy. **`onnxruntime` is not a dependency; only `AI_MODE=mock` is implemented** (B5) | VERIFIED |
| Infra | Docker Compose, Nginx | `docker-compose.yml` (dev), `docker-compose.pilot.yml` (production overlay), `deploy/ai-node/` (edge node kit) | VERIFIED |

## 3. Inventory

| Area | Count / notes | Status |
|---|---|---|
| Backend routes | `routes/api.php` 375 lines (≈157 routes per V1 audit), `routes/internal.php` (AI-node token surface: register, heartbeat, config, camera health, recognition events, face recognize) | VERIFIED |
| Migrations | 49; no tenant, organization or license tables | VERIFIED |
| Services | Academic, Audit, Auth (incl. MFA, sessions, scope), Camera (nodes, credentials, config sync), Event (ingestion, policy, review, notifications), Face, Report, Student, System (alerts, backup, flags, incidents, retention, health) | VERIFIED |
| AI service | Mock detector/embedder/liveness pipeline, RTSP source, tracking, recognition worker, durable offline buffer, node orchestrator (heartbeat, config sync, camera health, event delivery) | VERIFIED |
| Edge node kit | `deploy/ai-node/` compose + install/start/stop/update/healthcheck scripts | VERIFIED (files); NOT VERIFIED on real hardware |
| CI | `.github/workflows/ci.yml`: backend (Pint + **SQLite-only** tests), frontend (lint, vitest, build), AI (ruff check/format, pytest), advisory dependency scan, compose build. **No PostgreSQL job** and no remote to run it on | VERIFIED |

## 4. Defects found in this audit

| ID | Sev | Finding | Status / action |
|---|---|---|---|
| C-01 | MEDIUM | Malformed `*_uuid` filter values reach `exists:` on PostgreSQL native uuid columns → `22P02` → **HTTP 500** instead of 422. Fix existed only on unmerged branch `zen-villani` and missed 16 rules on History/Dashboard/Report endpoints added later | **Fixed in this branch**: cherry-picked `c3cdb3e`/`3e764cd` (author preserved) + extended to all 16 remaining rules; `MalformedUuidFilterTest` (14 endpoints) |
| C-02 | LOW | `ruff format --check` (a CI gate) fails on 6 files from `2e4605a` | **Fixed** `b4f9b3a` (formatting only; 297 AI tests pass) |
| C-03 | MEDIUM | CI and all documented test commands run the backend suite on SQLite only; PostgreSQL-only behaviour (C-01, A-16) is invisible to CI | **Fixed in this branch**: `scripts/test-backend-pgsql.sh` (disposable tmpfs database) + CI job (see WORK_BACKLOG P1-03) |
| C-04 | LOW | `InitialAdminUserSeeder` skipped only when `APP_ENV=production`; a `staging`/`pilot`/customer environment would get five accounts with one shared known password | **Fixed in this branch**: allowlist `local`/`testing`; tests for staging/pilot/demo |
| A-24 | LOW | Expected `ApiException` (409/422) logged at ERROR | **Fixed in this branch**: reported at INFO; `ApiExceptionLogLevelTest` |
| A-28 | INFO | Unreachable `env()` fallback in `FaceLivenessService` | **Fixed in this branch** |
| P08-25 | LOW | Hardcoded Reverb key in `scripts/verify-realtime-load.js` | Already resolved (key is a CLI argument): register entry was stale — VERIFIED |

## 5. Section 5.3 checklist (gaps previously reported)

| Check | Result | Status |
|---|---|---|
| Future feature flags stay off and can't be enabled through an unauthorized API | `FeatureFlagService::LOCKED_V1_FEATURES` (door, visitor, vehicle, alpr, ai_assistant) are kill-switched in code; V1 security audit §8 tested the settings API | VERIFIED (code) / REPORTED (API test) |
| Backups contain real data, are verifiable and survive container recreation | `backend-storage` volume, SHA-256 sidecars, off-host copy | REPORTED (V1 operations audit §3) |
| Restore works into an empty database | 3/3 biometric objects resolved after restore | REPORTED |
| Queue and scheduler run as configured | Real worker and scheduler on the pilot overlay; scheduler locks moved to the DB cache (A-11) | REPORTED; scheduler config VERIFIED in code |
| Health checks report correct status | `/health/*` 503 per failed dependency, probes outside session middleware | REPORTED; code VERIFIED |
| MinIO buckets and policies | `minio-init` one-shot creates buckets + least-privilege user; face samples not public | REPORTED |
| Alert generation | AI_SERVICE_HEALTH, STORAGE_HEALTH, REDIS_HEALTH, BACKUP_FAILED raised automatically | REPORTED |
| Production frontend doesn't depend on the Vite dev server | `Dockerfile.prod` → nginx static; pilot overlay uses it | VERIFIED |
| Runbook references a real tag and procedure | Tag exists, but **it's the wrong tag** for current code (§1, R-01) | VERIFIED — defect |
| Least-privilege roles and permissions | `LEAST-PRIVILEGE-ROLES.sql` idempotent; but SYSTEM_ADMIN = `*` (A-27) and SECURITY_OFFICER dashboard mismatch (A-14, resolved: owner decision 2026-11-20 (in chat): SECURITY_OFFICER keeps `dashboard.view`; no `report.*`/`system.*` added) | REPORTED + open decisions |
| Initial admin / default accounts have no shared known password | Production path is `access:bootstrap-admin` (forced change); dev seeder now allowlisted (C-04); preflight detects the dev password | VERIFIED |
| Events can be handled from API and dashboard | Review queue + append-only manual decisions (API + UI); incidents API only (A-17) | REPORTED |

## 6. Test evidence (executed in this audit, this worktree)

| Suite | Command | Result |
|---|---|---|
| Frontend lint | `npm run lint` | PASS (no findings) |
| Frontend unit | `npm run test` | **149 passed** (14 files) |
| Frontend build | `npm run build` | PASS |
| AI tests | `.venv/Scripts/python -m pytest -q` (Python 3.14 local; CI pins 3.12) | **297 passed** |
| AI lint | `ruff check .` / `ruff format --check .` | PASS / **FAIL (6 files)** before C-02, PASS after |
| Backend SQLite | `php artisan test` | see §6.1 |
| Backend PostgreSQL 16 | `scripts/test-backend-pgsql.sh` | see §6.1 |

### 6.1 Backend results
Recorded in [docs/progress/EXISTING_SYSTEM_COMPLETION.md](../progress/EXISTING_SYSTEM_COMPLETION.md) with the
exact commit they ran against. Earlier numbers (SQLite 1,068/1,088, PostgreSQL 1,076/1,095) are **REPORTED** only.

## 7. Items that can't be verified from the repository

| Item | Status |
|---|---|
| Pilot host readiness (H1, H2 TLS/DNS, H10 external probe, H11 NTP) | BLOCKED — no pilot host |
| Real camera / RTSP behaviour, 7/14-day pilot results, operator training | BLOCKED — physical / elapsed time |
| Production face/PAD model (B5) and its licensing | BLOCKED — human decision |
| Backup retention values and object-storage RPO exception | BLOCKED — human approval |
| Production backup/restore on a live host | BLOCKED — no live host |
