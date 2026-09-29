# Disaster Recovery Runbook (SEC-017)

## 1. Overview & Objectives
- **Target RTO (Recovery Time Objective)**: < 60 minutes.
- **Target RPO (Recovery Point Objective)**: < 15 minutes (via automated WAL archiving and daily full backups).
- **Scope**: Total datacenter / host failure, catastrophic storage loss, or complete database unrecoverability.

## 2. Disaster Scenarios & Playbooks

### Scenario A: Primary PostgreSQL Cluster Catastrophic Loss
1. **Provision Staging/Recovery Database Instance**:
   ```bash
   # Ensure target postgres has pgvector extension installed
   sudo apt-get install -y postgresql-16-pgvector
   ```
2. **Fetch Latest Verified Backup & Replay WALs**:
   ```bash
   aws s3 sync s3://smart-campus-backup-vault/db/ /var/backups/recovery/
   # Locate latest verified .dump or .sql
   pg_restore -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d smart_campus -v /var/backups/recovery/latest_verified.dump
   ```
3. **Execute Database Health Verification**:
   ```bash
   php artisan backup:restore-drill --latest
   ```

### Scenario B: Object Storage / Snapshot Volume Destruction
1. **Recreate Storage Mount / Target Bucket**:
   ```bash
   mkdir -p /var/www/smart-campus/storage/app/public/events/snapshots
   mkdir -p /var/www/smart-campus/storage/app/private/faces
   chmod -R 775 /var/www/smart-campus/storage
   ```
2. **Restore Object Storage Archives**:
   ```bash
   # Sync from secondary offsite replication vault
   aws s3 sync s3://smart-campus-secondary-vault/storage/public /var/www/smart-campus/storage/app/public
   ```

### Scenario C: Edge AI Node / Camera Hardware Outage
1. Cameras and AI edge nodes operate with store-and-forward local buffering (Module 04).
2. Upon re-establishing communication, nodes automatically flush queued recognitions without duplicate event generation using unique `event_uuid` idempotency.

## 3. Communication & Roles
- **Incident Commander**: Directs recovery, updates executive leadership every 30 minutes.
- **Lead Database Administrator**: Oversees WAL replay and data integrity validation.
- **DevOps / Infrastructure Engineer**: Rebuilds host environments and networking tunnels.
- **Security Officer**: Validates that all restored credentials, certs, and RBAC tables maintain zero regression.
