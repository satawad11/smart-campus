# PILOT-000 — Production Candidate Delta Review

**Status: APPROVED FOR PILOT DEPLOYMENT** (software delta review; the human SEC-024 approver still
acknowledges the post-approval changes in PILOT-011, issue B4). **Live biometric operation: HOLD, blocker B5 (§11.1).**
**Reviewed:** 2026-09-30, by the AI coding agent, from `git diff` and test runs. No result below is assumed.

| Item | Value |
|---|---|
| Approved baseline (SEC-024 GO WITH CONDITIONS) | `acbad50` |
| Commits reviewed | `75f98bb` (repository owner: InitialAdminUserSeeder), `f36a347` (Phase 08 fixes and tests), `a077eb2` (Phase 08 docs and TASKS) |
| Later commits | see §11 (pre-deployment fixes, reviewed with the same method) |

---

## 1. Changed Files (`git diff --stat acbad50 a077eb2`: 30 files, +2354 / −37)

| Area | Files |
|---|---|
| Runtime code | `FeatureFlagService.php`, `BackupService.php`, `RunBackupCommand.php`, `DetectStaleAiNodes.php`, new `PilotPreflightCommand.php` |
| Config | new `config/features.php`, new `config/backup.php`, `.env.example` |
| Seeders | new `InitialAdminUserSeeder.php` (75f98bb; not called by `DatabaseSeeder`) |
| Build and infra | `apps/backend/Dockerfile` (+`postgresql16-client`), `docker-compose.yml` (env_file path, storage volume), `infrastructure/nginx/default.conf` (+`/health` route) |
| Tests | 4 new test files; `phpunit.xml` (test-only env: kill switches unset, no MinIO in tests) |
| Style | `routes/console.php` (one trailing blank line removed) |
| Docs | `TASKS.md`, 11 `docs/pilot/PILOT-0xx` files |

### Verified unchanged (empty `git diff acbad50 a077eb2` on each path)
`database/migrations/`, `config/face.php`, `routes/api.php`, `routes/web.php`, `app/Http/` (controllers,
middleware, requests), `app/Models/`, `app/Services/Face/`, `app/Services/Event/`, `apps/frontend/`, `apps/ai-service/`.

---

## 2. Review Questions

| Question | Answer | Evidence |
|---|---|---|
| Architecture changed? | **NO** | Same services, stack and module boundaries. Additions are one console command, config files, a compose volume and an nginx location for existing FND-010 routes |
| Authentication / RBAC changed? | **NO** | No change under `app/Http`, `app/Models`, auth services, permission seeders or `routes/api.php` |
| Recognition thresholds changed? | **NO** | `config/face.php` unchanged; `pilot:preflight` asserts 0.80 / 0.60 / 0.05 / 0.65 / 0.70 / 0.40 |
| Access policy changed? | **NO** | `AccessPolicySeeder`, `app/Services/Event` and policy models unchanged |
| Module 05 ALLOW/REVIEW/DENY semantics changed? | **NO** | Event and decision code untouched; EVT test suites pass unchanged |
| Future modules unlocked? | **NO** | The only feature-flag change adds `return false` paths (kill switch). `getAll()` can only turn flags to `false`. `/api/v1/features` reports all five locked features false |
| Security weakened? | **NO** (one flagged item) | Runtime changes are strictly tightening. `75f98bb` adds a seeder with a shared known password; it isn't run by `DatabaseSeeder`, and preflight fails if its accounts are active (pre-deployment fixes add more guards, §11) |
| Biometric identity rules changed? | **NO** | `app/Services/Face` untouched; enrollment, duplicate review and PAD unchanged |
| Audit invariants changed? | **NO** | Audit service unchanged. New code only **adds** audit and alert rows (`alert.triggered`); nothing deletes or rewrites audit data |
| Backup correctness improved? | **YES** | Real pg_dump required; empty or simulated dumps fail; MinIO included; `--verify` exit code honest; backups persist across redeploys |
| Fail-closed enforcement improved? | **YES** | `FEATURE_*_ENABLED=false` now overrides the settings API (previously not enforced) |
| Operational readiness improved? | **YES** | Queue and scheduler configured, gateway health probes real, automatic alerts, `pilot:preflight` |

---

## 3. Behaviour Changes
1. `FeatureFlagService::isEnabled()` returns `false` for a locked feature when its `FEATURE_*_ENABLED`
   variable is explicitly false or unparseable. Unset → SEC-002 behaviour unchanged.
2. `backup:run`: pg_dump failure or empty output → job FAILED (previously COMPLETED). `--verify` exits 1
   on any unverified drill. Unknown `--type` exits 1. Failures raise a `BACKUP_FAILED` CRITICAL alert.
3. Storage backup archives the MinIO buckets `person_photos`, `face_samples` and `event_snapshots`.
4. The restore drill requires a pg_dump header for PostgreSQL backups.
5. `ai-nodes:detect-stale` raises one `AI_NODE_DOWN` CRITICAL alert per stale period.
6. New read-only `pilot:preflight`.

## 4. Security Impact
Positive: locked features become hard-disabled by the environment; the DB password moves out of the process
arguments; default secrets and shared-password accounts are detected. Negative risk: backups now contain
biometric source images, which calls for encrypted off-host storage (policy: PILOT-BACKUP-RETENTION-POLICY).

## 5. Architecture Impact
None. No new services, datastores, APIs or contracts.

## 6. Migration Impact
None. 49 migrations, identical to the baseline.

## 7. Operational Impact
Backend image +PostgreSQL 16 client. Compose now requires `apps/backend/.env` and adds the `backend-storage`
volume. `/health/*` is now served by the backend through the gateway.

## 8. Regression Evidence (f36a347)
| Gate | Result |
|---|---|
| Backend PHPUnit, SQLite | 1054 passed, 0 failed, 8 pre-existing incomplete |
| Backend PHPUnit, PostgreSQL | 1062 passed, 0 failed |
| Frontend vitest / build | 149/149 / OK (frontend code unchanged) |
| Rehearsal | preflight PASS, smoke 30/30, verified backup, 2 full restores |

## 9. Known Risks
- The post-approval code delta needs human acknowledgement (PILOT-011 B4).
- `InitialAdminUserSeeder` remains in the repository (guards in §11).

## 10. Conclusion
The delta contains no architecture, authentication, threshold, policy, biometric-rule, audit or
future-module change, and every runtime change fails closed or fixes a defect.
**APPROVED FOR PILOT DEPLOYMENT** (software review), subject to the human acknowledgement in PILOT-011.

---

## 11. Addendum — Pre-Deployment Fixes After a077eb2

Reviewed with the same method (`git diff a077eb2..<candidate>` and test runs). The production candidate
commit and tag `v1.0.0-pilot` are recorded in `docs/pilot/PILOT-HOST-DEPLOYMENT-CHECKLIST.md` and TASKS.md.

### 11.1 New blocker found during this review (pre-existing, not introduced by the delta)

**B5 — No production biometric pipeline in the repository (HUMAN DECISION REQUIRED).**
`apps/ai-service` only implements `AI_MODE=mock`. `/recognize` returns 503 in any other mode;
`onnxruntime` isn't in `requirements.txt`; there are no model-path settings and no model weights.
Worse, in any non-mock mode the PAD (liveness) factory **fell back to the mock detector, which scores
every image 0.95 = REAL**, so PAD failed open. Mock mode embeddings aren't real recognition. The pilot
can be deployed and operated for everything except live biometric recognition, which needs a human
decision on face detection, embedding and PAD models (selection, licensing, accuracy validation against
FACE-021 thresholds). That's a new capability, not a pilot fix, and the agent didn't implement it.
Mitigation shipped: PAD now fails closed (commit `808acf9`), and the pilot overlay refuses to start
without an explicit `AI_MODE`.

### 11.2 Changes after a077eb2

| Commit area | Change | Security / behaviour impact |
|---|---|---|
| AI service (`808acf9`) | Non-mock PAD → `PAD_MODEL_UNAVAILABLE` 503 instead of the always-REAL mock | **Tightening** (fail closed). Mock mode unchanged |
| Alerts | `system:monitor` every minute: REDIS/STORAGE/AI_SERVICE_HEALTH (CRITICAL), CAMERA_DOWN (WARNING, stale > 120 s), QUEUE_BACKLOG (WARNING); `triggerOnce` deduplication | Additive; alert rows only |
| Backups | schedule (opt-in `BACKUP_SCHEDULE_ENABLED`), `backup:prune` retention (job rows kept), verified off-host copy + `.sha256`, `campus_backup_user` for pg_dump, 0600 files | Additive; pruning deletes only local backup files outside the policy, never job or audit rows |
| Preflight | backup schedule, off-host target and copy, any active account with the shared dev password (hash compare) | Stricter go-live gate |
| Seeder | `InitialAdminUserSeeder` is a no-op in production | Tightening |
| DB roles SQL (SEC-005) | fixed invalid REVOKE; psql variables for DB name and passwords; idempotent; validated by execution | Fix. **Acceptance test changed:** `SecretsStrategyAndRolesTest` asserted the invalid statement verbatim; it now asserts the corrected REVOKEs, the absence of app-user DDL/TRUNCATE grants and no literal passwords (stricter) |
| Deployment | `docker-compose.pilot.yml` (TLS gateway, static frontend, no published datastores, Redis password, required secrets, minio-init least-privilege user, db-roles), env template, host preflight script; base compose env-file path configurable | Operational; no application behaviour change |
| Runbooks | DEPLOYMENT.md and ROLLBACK.md rewritten for Docker Compose; real release tag | Docs |
| Style | `routes/api.php` imports (pint): route table identical apart from closure line numbers; 5 unused frontend variables removed (lint) | None |

### 11.3 Re-check of the review questions for the candidate
Architecture: **NO** change (compose services are the same; `minio-init` and `db-roles` are one-shot setup jobs).
Auth/RBAC, thresholds, access policy, ALLOW/REVIEW/DENY, biometric rules, audit invariants: **unchanged**
(no diff under `app/Http`, `app/Models`, `app/Services/Face`, `app/Services/Event`, `config/face.php`,
`database/migrations`; route table identical). Future modules: **still locked**. Security weakened: **NO**
(every change is additive or tightening; PAD fail-open closed).

**Conclusion (candidate): APPROVED FOR PILOT DEPLOYMENT** of the software stack. **Live biometric
operation is on HOLD pending blocker B5 (human model decision).**

### 11.4 Regression Evidence for the Candidate (code `72ee47b`)

| Gate | Result |
|---|---|
| Backend PHPUnit, SQLite | **1068 passed, 0 failed** (8 pre-existing incomplete) |
| Backend PHPUnit, PostgreSQL 16 | **1076 passed, 0 failed** |
| Pint | PASS (514 files) |
| AI service pytest / ruff check / ruff format | **286 passed** / clean / clean |
| Frontend vitest / eslint / production build | **149/149** / clean / OK |
| `docker compose config` (base) and pilot overlay config | OK |
| Pilot overlay rehearsal (TEST DATA) | preflight: only the expected local-CORS FAIL; host preflight H3–H14 PASS (H1/H2 FAIL on a workstation, as expected); HTTPS smoke 26/26; verified + off-host backups; scheduled backup fired; off-host restore OK; alert raised on AI-service outage |
| DB roles SQL | executed twice (idempotent); privilege probes as documented in the d9af363 commit message |

