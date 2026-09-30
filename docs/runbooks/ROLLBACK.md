# Production Rollback Runbook (SEC-016, revised for Docker Compose)

> Revised in the pilot pre-deployment review to match the Docker Compose
> deployment (DEPLOYMENT.md). The previous version used systemd, supervisor and a
> `v0.9.8-stable` tag that doesn't exist.

```bash
DC="docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml"
```

## 1. Trigger Conditions
A rollback is immediately triggered if any of the following occur within the 60-minute post-deployment observation window:
1. `health/full` returns HTTP 503 or fails database/redis probes for > 2 consecutive minutes.
2. Ingestion pipeline experiences total loss of events from active cameras.
3. Database migrations fail midway or cause unexpected data corruption.
4. Security vulnerability or severe regression is discovered in authentication/authorization.
5. `backup:run --type=ALL --verify` fails after deployment (deployment status HOLD).

## 2. Rollback Decision Authority
- Incident Commander / Lead DevOps Engineer
- Final sign-off required by Systems Administrator.

## 3. Step-by-Step Rollback Execution

### Step 3.1: Re-engage Maintenance Mode
```bash
$DC exec backend php artisan down --message="System is temporarily unavailable while maintenance is being rolled back."
```

### Step 3.2: Revert Application Images
```bash
# Images tagged by DEPLOYMENT.md step 3.1 before the upgrade
docker tag smart-campus/backend:pilot-previous  smart-campus/backend:pilot
docker tag smart-campus/frontend:pilot-previous smart-campus/frontend:pilot
git checkout tags/<previous release tag>      # keep compose/nginx config consistent with the images
```
**First pilot deployment:** there is no previous release. Rollback means
`$DC down` (volumes kept) and returning to the manual gate procedure.

### Step 3.3: Database Schema / State Reversion
```bash
# Option A: Targeted migration rollback (if non-destructive)
$DC exec -e DB_USERNAME=campus_migration_user -e DB_PASSWORD="$MIGRATION_DB_PASSWORD" backend php artisan migrate:rollback --step=<n> --force

# Option B: Restore the verified pre-deployment backup (schema or data corrupted)
#   1. Pick the newest VERIFIED POSTGRESQL job from before the deployment
#      (off-host copy: $BACKUP_OFFHOST_HOST_PATH/postgresql/<file>.sql, check its .sha256)
#   2. Restore into a NEW database, verify it, then switch DB_DATABASE (never restore over live data):
$DC exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d postgres -c "CREATE DATABASE smart_campus_restore"'
$DC exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d smart_campus_restore -v ON_ERROR_STOP=1' < <backup.sql>
#   3. Re-run db-roles for the restored database, update DB_DATABASE, then Step 3.4.
```

### Step 3.4: Purge Stale Caches and Restart
```bash
$DC up -d --force-recreate backend queue scheduler websocket frontend
$DC exec backend sh -c 'php artisan optimize:clear && php artisan config:cache && php artisan route:cache'
```

### Step 3.5: Verify Restored Baseline & Release Traffic
```bash
$DC exec backend php artisan pilot:preflight --require-backup
curl -fsS https://<pilot dns name>/health/full
$DC exec backend php artisan up
```

### Step 3.6: Incident Post-Mortem Declaration
Create an incident record in the system:
```bash
$DC exec backend php artisan tinker --execute="app(App\Services\System\IncidentService::class)->createIncident(['title' => 'Deployment Rollback for Release v1.0.0-pilot', 'severity' => 'SEV1', 'description' => 'Rollback executed due to post-deployment failure.']);"
```
