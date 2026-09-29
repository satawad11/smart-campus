# Object & File Storage Backup Procedure (SEC-008)

## 1. Overview
This procedure specifies backup mechanisms for unstructured application data:
- Event snapshots (`storage/app/public/events/snapshots/`)
- Face registration reference images (`storage/app/private/faces/`)
- Exported reports and temporary artifacts (`storage/app/exports/`)

## 2. Backup Strategy
- **Frequency**: Daily archive creation or real-time S3 bucket replication (versioning + cross-region replication).
- **Tooling**: Built-in `php artisan backup:run --type=STORAGE`, or AWS CLI / MinIO Client `mc mirror`.
- **Integrity**: Standard SHA-256 manifest verification and zip archive structure checks.

## 3. Storage Backup Execution
```bash
# Automated archive execution
php artisan backup:run --type=STORAGE --verify

# S3 / MinIO direct bucket synchronization
aws s3 sync /var/www/smart-campus/storage/app/public s3://smart-campus-backup-vault/storage/public --delete
```

## 4. Restore Drill Procedure
```bash
# Automated verification drill
php artisan backup:restore-drill --latest

# Manual restoration drill
unzip -t /var/backups/smart-campus/storage/storage_target.zip
unzip -o /var/backups/smart-campus/storage/storage_target.zip -d /tmp/storage_restore_test
```
