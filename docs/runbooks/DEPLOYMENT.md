# Production Deployment Runbook (SEC-015, revised for Docker Compose)

> Revised in the pilot pre-deployment review. The original SEC-015 procedure
> assumed a VM with systemd, supervisor and `/var/www/smart-campus`, and checked
> out the tag `v1.0.0-pilot`, which didn't exist. The repository ships Docker
> Compose. This runbook is the Compose workflow that was rehearsed end to end
> (docs/pilot/PILOT-HOST-DEPLOYMENT-CHECKLIST.md).

## 1. Scope & Objective
This runbook governs the deployment of **Production V1** of the AI Smart Campus Security & Access Platform. Production V1 enables core identity, student administration, biometric face recognition access control, historical search, and audit reporting, while keeping experimental and hardware-coupled actuators (Door, Visitor, Vehicle, ALPR, AI Assistant) fail-closed and disabled.

Files: `docker-compose.yml` (base) + `docker-compose.pilot.yml` (production overlay).
Environment: `apps/backend/.env`, created from `infrastructure/pilot/pilot.env.example`.
Shorthand used below:

```bash
DC="docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml"
```

## 2. Pre-Deployment Checklist
- [ ] Release tag exists on the approved candidate commit (`git tag -l v1.0.0-pilot`) and the working tree is clean.
- [ ] `apps/backend/.env` created from `infrastructure/pilot/pilot.env.example`, mode 0600, every empty value filled with real secrets (secret manager or protected file). No development defaults.
- [ ] `AI_MODE` decided by a human (the repository provides no production face or PAD model; see PILOT-000 B5).
- [ ] TLS certificate installed: `infrastructure/pilot/certs/fullchain.pem` and `privkey.pem` for the pilot DNS name.
- [ ] Off-host backup mount present at `BACKUP_OFFHOST_HOST_PATH` (separate disk or machine).
- [ ] `CORS_ALLOWED_ORIGINS` and `APP_URL` are the pilot HTTPS origin (no `*`, no localhost).
- [ ] Future feature flags confirmed disabled in production configuration:
  - `FEATURE_DOOR_ENABLED=false`
  - `FEATURE_VISITOR_ENABLED=false`
  - `FEATURE_VEHICLE_ENABLED=false`
  - `FEATURE_ALPR_ENABLED=false`
  - `FEATURE_AI_ASSISTANT_ENABLED=false`
- [ ] For upgrades only: a verified pre-deployment backup exists (`$DC exec backend php artisan backup:run --type=ALL --verify`).

## 3. Step-by-Step Deployment Procedure

### Step 3.1: Maintenance Mode (upgrades only)
```bash
$DC exec backend php artisan down --retry=60 --secret="$MAINTENANCE_BYPASS_TOKEN"
# Keep the current images for rollback (see ROLLBACK.md)
docker tag smart-campus/backend:pilot  smart-campus/backend:pilot-previous
docker tag smart-campus/frontend:pilot smart-campus/frontend:pilot-previous
```

### Step 3.2: Code Checkout & Image Build
```bash
git fetch origin --tags
git checkout tags/v1.0.0-pilot
git status --porcelain            # must print nothing
$DC config --quiet                # fails fast on any missing required secret
$DC build backend frontend ai-service
```

### Step 3.3: Datastores, Object Storage and Database Roles
```bash
$DC up -d --wait postgres redis minio
$DC up minio-init                 # buckets + least-privilege MinIO app user (idempotent)
$DC run --rm db-roles             # extensions + SEC-005 roles (idempotent)
```

### Step 3.4: Database Migration and Approved Seed
```bash
$DC up -d --wait backend
M="-e DB_USERNAME=campus_migration_user -e DB_PASSWORD=$MIGRATION_DB_PASSWORD"
$DC exec -T $M backend php artisan migrate --force
$DC exec -T $M backend php artisan db:seed --force
$DC exec -T $M backend php artisan db:seed --class=SystemSettingSeeder --force
```
Approved seed only: `DatabaseSeeder` (DemoDataSeeder skips itself in production) and
`SystemSettingSeeder`. **Never run** `InitialAdminUserSeeder` (it's a no-op in production and is
guarded by preflight) or any demo data. Create the first SUPER_ADMIN manually with a unique
password and a forced change at first login.

### Step 3.5: Cache Optimization
```bash
$DC exec backend sh -c 'php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache'
```

### Step 3.6: Start All Services
```bash
$DC up -d --wait
$DC ps                             # only nginx publishes ports (80, 443)
```

### Step 3.7: Initial Verified Backup (SEC-024 condition 2)
```bash
$DC exec backend php artisan backup:run --type=ALL --verify   # must exit 0; copies off-host
```
If this fails, **stop: the deployment status is HOLD**.

### Step 3.8: Post-Deployment Verification
```bash
$DC exec backend php artisan pilot:preflight --require-backup   # must PASS
PILOT_HOST=<pilot dns name> scripts/pilot-host-preflight.sh     # H1–H14, no FAIL
curl -fsS https://<pilot dns name>/health/full                  # JSON, 200
curl -s -o /dev/null -w "%{http_code}\n" -H "Accept: application/json" https://<pilot dns name>/api/v1/doors   # 401
```

### Step 3.9: Deactivate Maintenance Mode (upgrades only)
```bash
$DC exec backend php artisan up
```
