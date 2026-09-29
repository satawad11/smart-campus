# Production Deployment Runbook (SEC-015)

## 1. Scope & Objective
This runbook governs the deployment of **Production V1** of the AI Smart Campus Security & Access Platform. Production V1 enables core identity, student administration, biometric face recognition access control, historical search, and audit reporting, while keeping experimental and hardware-coupled actuators (Door, Visitor, Vehicle, ALPR, AI Assistant) fail-closed and disabled.

## 2. Pre-Deployment Checklist
- [ ] Working tree clean and committed on target release tag/commit.
- [ ] Database backup executed (`php artisan backup:run --type=POSTGRESQL --verify`).
- [ ] Environment secrets populated via secret manager (AWS Secrets Manager / Vault / secure `.env`).
- [ ] Least-privilege database roles configured (`campus_app_user`, `campus_migration_user`).
- [ ] SSL/TLS certificates active and valid.
- [ ] Security headers and CORS origins verified (`CORS_ALLOWED_ORIGINS` explicitly defined, no `*` with credentials).
- [ ] Future feature flags confirmed disabled in production configuration:
  - `FEATURE_DOOR_ENABLED=false`
  - `FEATURE_VISITOR_ENABLED=false`
  - `FEATURE_VEHICLE_ENABLED=false`
  - `FEATURE_ALPR_ENABLED=false`
  - `FEATURE_AI_ASSISTANT_ENABLED=false`

## 3. Step-by-Step Deployment Procedure

### Step 3.1: Maintenance Mode Engagement
```bash
cd /var/www/smart-campus/apps/backend
php artisan down --retry=60 --secret="$MAINTENANCE_BYPASS_TOKEN"
```

### Step 3.2: Code Pull & Dependency Installation
```bash
git fetch origin --tags
git checkout tags/v1.0.0-pilot

# Backend dependencies
composer install --no-dev --optimize-autoloader --no-interaction

# Frontend build & distribution
cd ../frontend
npm ci
npm run build
cp -r dist/* /var/www/smart-campus/public_html/
```

### Step 3.3: Database Migration Execution
```bash
cd /var/www/smart-campus/apps/backend
# Executed using campus_migration_user
php artisan migrate --force
php artisan db:seed --class=SystemSettingSeeder --force
```

### Step 3.4: Cache Optimization & Service Priming
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### Step 3.5: Daemon / Queue Restart
```bash
systemctl restart php8.2-fpm
systemctl restart nginx
supervisorctl restart smart-campus-worker:*
supervisorctl restart smart-campus-reverb:*
```

### Step 3.6: Post-Deployment Smoke Verification
```bash
# Verify health probes
curl -kfsS https://127.0.0.1/health
curl -kfsS https://127.0.0.1/health/database
curl -kfsS https://127.0.0.1/health/redis
curl -kfsS https://127.0.0.1/health/storage
curl -kfsS https://127.0.0.1/health/ai

# Verify locked feature gates return 403 FEATURE_DISABLED
curl -kfsS -o /dev/null -w "%{http_code}\n" -H "Accept: application/json" https://127.0.0.1/api/v1/doors # Expected 401 or 403
```

### Step 3.7: Deactivate Maintenance Mode
```bash
php artisan up
```
