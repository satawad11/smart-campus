# PILOT-002 — Deployment Report

**Status: PASS (deployment rehearsal) / PENDING (pilot production host)**
**Rehearsal executed:** 2026-09-30 02:12 +07:00 (2026-09-29T19:12:18Z)
**Executed by:** AI coding agent. Environment: **LOCAL DOCKER REHEARSAL, not production**

---

## 1. What Was Deployed

| Item | Value |
|---|---|
| Approved V1 baseline (SEC-024) | `acbad50` (docs-only gate commit) |
| Commits after the baseline | `75f98bb` adds `InitialAdminUserSeeder` (committed by the repository owner; **not** part of the approved seed; see §5) |
| Pilot fix commit (this phase) | `f36a347`: fix(pilot): locked-feature kill switch, backup integrity, alert producers, preflight, compose/nginx config |
| Deployed code | `f36a347` = approved V1 + Phase 08 pilot fixes. These fixes change runtime behaviour after SEC-024 approval (see §6); the human reviewer must acknowledge them in PILOT-011 |
| Backend image | Rehearsal runtime: `ad7e4bc33bb3`. Built from commit f36a347: `smart-campus/backend:pilot-f36a347` (`e53f0acd915d`). Runtime code (app, config, routes, database, bootstrap, public, resources, composer.lock) hashes identically between the two; only test files differ (P08-19 test fix). Built from a clean `git archive` (no untracked files) |
| Frontend image | `smart-campus/frontend:pilot-candidate` (`92153f29b2ef`); production bundle built with `npm run build` |
| Migrations | 49 of 49 applied (`migrate:status`: 49 Ran, 0 Pending), run as `campus_migration_user` |
| Seeders run | `DatabaseSeeder` (Permission, Role, RolePermission, EventType, AccessPolicy; DemoDataSeeder **skipped itself** in production) and `SystemSettingSeeder`. **No demo data. `InitialAdminUserSeeder` not run** |
| Seeded row counts | permissions 44, roles 5, role_permissions 110, event_types 3, access_policies 8, system_settings 38 |
| Caches | `config:cache`, `route:cache`, `view:cache`, `event:cache`: all succeeded |
| Services | backend (php-fpm image), PostgreSQL 16.15, Redis 7.4, MinIO, AI service (mock mode), Reverb, nginx |
| Post-deploy checks | final `pilot:preflight --require-backup` PASS (0 failures, 1 warning: Redis password); PILOT-005 smoke 30/30 PASS on the final image |

## 2. Rehearsal Procedure Actually Executed

1. Created database `pilot_rehearsal`; enabled `vector` and `pg_trgm` as superuser.
2. Applied `docs/security/LEAST-PRIVILEGE-ROLES.sql` with the database name substituted. One
   statement errored (P08-11); the resulting privileges were verified (no superuser,
   createrole or createdb on any `campus_*` role).
3. Started the backend container with a production env file (`APP_ENV=production`,
   `APP_DEBUG=false`, generated `APP_KEY`, `FEATURE_*_ENABLED=false`, secure cookies,
   explicit CORS, app DB user `campus_app_user`).
4. `php artisan migrate --force` and `db:seed --force` (+ `--class=SystemSettingSeeder`) as
   `campus_migration_user`.
5. `config:cache`, `route:cache`, `view:cache`, `event:cache`.
6. `php artisan pilot:preflight`: 1 FAIL (storage). Root cause: unprovisioned buckets (P08-13).
   Fixed, then **PASS**.
7. Initial backup with verification (PILOT-003), then `pilot:preflight --require-backup`: **PASS**.
8. Smoke test (PILOT-005): **30/30 PASS**.
9. More defects surfaced during steps 6–8 (P08-12, P08-16, the preflight default-secret and
   backup-file checks). After fixing them, the container was recreated on the **final** image
   `ad7e4bc33bb3`: the rehearsal-only secrets were rotated to random values, a fresh verified
   backup was taken, the container was recreated again with the storage volume, preflight
   passed (0 failures), the smoke test passed 30/30, and the full restore passed. Phase 08 changed no
   migrations, so the step-4 schema applies unchanged.

## 3. Test Gates for the Deployed Code

| Gate | Result |
|---|---|
| Backend PHPUnit, SQLite (full suite) | **1054 passed, 0 failed**, 8 incomplete (pre-existing `markTestIncomplete`), `docs/` mounted for the SEC-004/005 doc tests |
| Backend PHPUnit, PostgreSQL gate (full suite, `pilot_gate` DB) | **1062 passed, 0 failed** (two earlier gate runs hung on a Phase 08 test bug, P08-19, fixed before commit) |
| New Phase 08 tests | FeatureKillSwitchTest, BackupIntegrityTest, PilotPreflightCommandTest, PilotAlertProducersTest: all pass |
| Frontend vitest | 149 / 149 passed (14 files) |
| Frontend production build | succeeded |
| Pint | clean except a pre-existing issue in `routes/api.php` (P08-15) |

## 4. Pilot Host Deployment Procedure (PENDING, human-executed)

`docs/runbooks/DEPLOYMENT.md` (SEC-015) assumes a VM with systemd and supervisor, and checks
out tag `v1.0.0-pilot`, **which doesn't exist** (P08-09). The repository ships Docker Compose.
Until the operator picks a deployment model, the rehearsed equivalent is:

```bash
# 0. On the approved commit, create the release tag (human decision; not done by the agent)
git tag -a v1.0.0-pilot f36a347 -m "Pilot V1"

# 1. Provision object storage buckets (idempotent)
scripts/provision-minio.sh

# 2. Database roles: apply LEAST-PRIVILEGE-ROLES.sql with the real DB name and real passwords
#    (remove "DROP, TRUNCATE" from the schema REVOKE statement; see P08-11)

# 3. Build images from the tag and start the stack with the production env file
docker compose build backend frontend
docker compose up -d

# 4. Migrate and seed (approved seed only) as the migration user
docker compose exec -e DB_USERNAME=campus_migration_user -e DB_PASSWORD="$MIGRATION_PWD" backend php artisan migrate --force
docker compose exec -e DB_USERNAME=campus_migration_user -e DB_PASSWORD="$MIGRATION_PWD" backend php artisan db:seed --force
docker compose exec -e DB_USERNAME=campus_migration_user -e DB_PASSWORD="$MIGRATION_PWD" backend php artisan db:seed --class=SystemSettingSeeder --force

# 5. Caches
docker compose exec backend sh -c 'php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache'

# 6. Initial verified backup (SEC-024 condition 2), then the full go-live preflight
docker compose exec backend php artisan backup:run --type=ALL --verify
docker compose exec backend php artisan pilot:preflight --require-backup
```

Production differences still to be engineered on the pilot host: TLS termination, serving the
built frontend `dist/` instead of the Vite dev server (P08-14), and an off-host backup volume.

**Never run** `php artisan db:seed --class=InitialAdminUserSeeder` or `DemoDataSeeder` on the
pilot host. Create the first SUPER_ADMIN account through a controlled manual step with a
unique password and forced change at first login.

## 5. Findings Relevant to Deployment

- **P08-08:** `InitialAdminUserSeeder` (commit 75f98bb) creates 5 accounts with one shared known
  password. It isn't called by `DatabaseSeeder`, but it ships in the image. `pilot:preflight`
  fails if any of those accounts are active. Removing it or moving it to a dev-only path is a
  human decision.
- **P08-03:** the images running in the dev stack were built 2026-09-19/23 and lacked Module 07
  entirely, so earlier interactive checks on that stack didn't reflect the approved code. The
  dev stack was recreated from the candidate images (§7).

## 6. Post-Approval Changes the Human Reviewer Must Acknowledge

The pilot fix commit changes behaviour after SEC-024 approved V1. Each change is **strictly
tightening** or fixes a defect:

| Change | Effect |
|---|---|
| Kill switch for locked features | Locked features can no longer be enabled when `FEATURE_*_ENABLED=false` (previously possible via settings) |
| Backup integrity | Backups that previously "succeeded" with empty or simulated content now fail loudly |
| Storage backup includes MinIO buckets | Backups are larger and now contain biometric source images; protect the backup location accordingly |
| Alert producers | New CRITICAL alerts for stale AI nodes and failed backups |
| `pilot:preflight` | New read-only command |
| Compose `env_file`, nginx `/health` route, `.env.example` defaults | Deployment configuration |

## 7. Dev Stack Refresh (verification of P08-02 / P08-03 / P08-12)

Executed 2026-09-29T19:45Z on the development stack (**not** a pilot environment):

| Step | Result |
|---|---|
| Retag candidate images as `:dev`; `docker compose up -d --force-recreate backend queue scheduler websocket frontend` | all services started; backend and frontend healthy |
| nginx config test and reload (new `/health` location) | `nginx -t` successful; reloaded |
| Dev DB `smart_campus`: safety `pg_dump` (277,111 bytes, kept outside the repository), then `migrate --force` | 7 pending migrations applied (Module 06 summaries/exports, all Module 07 tables); `SystemSettingSeeder` → 38 settings |
| Queue worker | running, no restarts (was crash-looping: P08-02) |
| Scheduler | "Running scheduled tasks"; `schedule:list`: detect-stale every minute, refresh-summaries every 15 min, retention daily 02:00 |
| Gateway `/health`, `/health/database`, `/redis`, `/storage`, `/ai`, `/full` | all `200 application/json` (were SPA HTML: P08-12) |
| Gateway Reverb `/reverb/app/{key}` WebSocket upgrade | `101 Switching Protocols` |
| Gateway `/api/v1/features` | door, visitor, vehicle, alpr, ai_assistant all `false` |

The dev stack keeps `APP_ENV=local` and dev secrets by design. It isn't a pilot target.
