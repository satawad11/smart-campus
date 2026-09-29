# PostgreSQL Backup & Restore Procedure (SEC-007, SEC-009)

## 1. Overview
This procedure governs automated and manual backup routines for the Smart Campus PostgreSQL database (`smart_campus`), containing face profiles, events, decisions, cameras, users, and audit records.

## 2. Backup Strategy
- **Frequency**: Daily full backup at 01:00 AM; continuous WAL archiving for point-in-time recovery (PITR).
- **Tooling**: `pg_dump` with custom/plain directory format, automated via `php artisan backup:run --type=POSTGRESQL`.
- **Integrity**: Every backup archive calculates a SHA-256 checksum stored in the `backup_jobs` table.
- **Storage Location**: Encrypted local path `/var/backups/smart-campus/db/` replicated to offsite S3-compatible encrypted bucket.

## 3. Database Backup Execution
```bash
# Automated execution
php artisan backup:run --type=POSTGRESQL --verify

# Manual execution with pg_dump directly
PGPASSWORD="$DB_PASSWORD" pg_dump -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USERNAME" -F c -b -v -f "/var/backups/smart-campus/db/db_$(date +%Y%m%d_%H%M%S).dump" smart_campus
```

## 4. Restore Drill Procedure
A backup file is untrusted until tested in an isolated staging environment.

```bash
# Automated verification drill (validates presence, size, and SHA256 checksum)
php artisan backup:restore-drill --latest

# Manual database restore to staging database
createdb -h "$DB_HOST" -U "$DB_USERNAME" smart_campus_restore_test
pg_restore -h "$DB_HOST" -U "$DB_USERNAME" -d smart_campus_restore_test -v "/var/backups/smart-campus/db/db_target.dump"

# Run sanity checks
psql -h "$DB_HOST" -U "$DB_USERNAME" -d smart_campus_restore_test -c "SELECT count(*) FROM users;"
psql -h "$DB_HOST" -U "$DB_USERNAME" -d smart_campus_restore_test -c "SELECT count(*) FROM face_profiles;"
psql -h "$DB_HOST" -U "$DB_USERNAME" -d smart_campus_restore_test -c "SELECT count(*) FROM access_events;"

# Clean up restore drill database
dropdb -h "$DB_HOST" -U "$DB_USERNAME" smart_campus_restore_test
```

## 5. Frequency of Restore Drills
- Automated drill: Daily via scheduled artisan job.
- Manual full restore drill: Monthly by Lead Systems/DevOps Engineer.
