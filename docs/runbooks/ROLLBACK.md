# Production Rollback Runbook (SEC-016)

## 1. Trigger Conditions
A rollback is immediately triggered if any of the following occur within the 60-minute post-deployment observation window:
1. `health/full` returns HTTP 503 or fails database/redis probes for > 2 consecutive minutes.
2. Ingestion pipeline experiences total loss of events from active cameras.
3. Database migrations fail midway or cause unexpected data corruption.
4. Security vulnerability or severe regression is discovered in authentication/authorization.

## 2. Rollback Decision Authority
- Incident Commander / Lead DevOps Engineer
- Final sign-off required by Systems Administrator.

## 3. Step-by-Step Rollback Execution

### Step 3.1: Re-engage Maintenance Mode
```bash
cd /var/www/smart-campus/apps/backend
php artisan down --message="System is temporarily unavailable while maintenance is being rolled back."
```

### Step 3.2: Revert Application Code
```bash
# Revert git checkout to previous stable tag
git checkout tags/v0.9.8-stable

composer install --no-dev --optimize-autoloader --no-interaction

cd ../frontend
npm ci
npm run build
cp -r dist/* /var/www/smart-campus/public_html/
```

### Step 3.3: Database Schema / State Reversion
```bash
cd /var/www/smart-campus/apps/backend

# Option A: Targeted migration rollback (if non-destructive)
php artisan migrate:rollback --step=3 --force

# Option B: Restore from pre-deployment snapshot (if schema or data corrupted)
php artisan backup:restore-drill --latest
# (Follow docs/security/POSTGRESQL-BACKUP-RESTORE.md to restore target snapshot)
```

### Step 3.4: Purge Stale Caches
```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

### Step 3.5: Restart Daemons & Reset Queues
```bash
supervisorctl restart smart-campus-worker:*
supervisorctl restart smart-campus-reverb:*
systemctl restart php8.2-fpm
```

### Step 3.6: Verify Restored Baseline & Release Traffic
```bash
curl -kfsS https://127.0.0.1/health/full
php artisan up
```

### Step 3.7: Incident Post-Mortem Declaration
Create an incident record in the system:
```bash
# Declare post-mortem via API/console
php artisan tinker --execute="app(App\Services\System\IncidentService::class)->createIncident(['title' => 'Deployment Rollback for Release v1.0.0', 'severity' => 'SEV1', 'description' => 'Automated rollback executed due to post-deployment failure.']);"
```
