# Backup and Recovery

| Item | Status |
|---|---|
| PostgreSQL dump + object-storage archive, SHA-256, verify drill, off-host copy | Implemented (SEC-006..010, PILOT-PKG); rehearsal restore into an empty DB PASS (REPORTED) |
| Scheduled backups + retention pruning | Implemented, off by default (`BACKUP_SCHEDULE_ENABLED`) |
| Retention values | **Proposed, AWAITING HUMAN APPROVAL** ([PILOT-BACKUP-RETENTION-POLICY](../pilot/PILOT-BACKUP-RETENTION-POLICY.md)) |
| RPO | PostgreSQL ≤ 15 min target; object storage ≈ 60 min (**RPO exception AR4 awaiting approval**) |
| Backup encryption | Implemented, **opt-in**: `BACKUP_ENCRYPTION_KEY` (generate with `php artisan backup:key`); files become `<name>.enc`, checksum covers the ciphertext, the restore drill decrypts to verify, `php artisan backup:decrypt <file.enc> <out>` for manual restores. **Losing the key loses the backups**: store it in a vault, apart from the backups |
| Production restore test | **BLOCKED** (no production host) |
| Full DR rehearsal | Not performed end to end |

Procedures: [POSTGRESQL-BACKUP-RESTORE](../security/POSTGRESQL-BACKUP-RESTORE.md),
[STORAGE-BACKUP-RESTORE](../security/STORAGE-BACKUP-RESTORE.md), [DISASTER-RECOVERY](../runbooks/DISASTER-RECOVERY.md).

Rules: restore into a new, empty database and verify before switching; never restore over live data; a backup
contains biometric data, so it inherits Highly Sensitive handling and its retention bounds how long erased biometric
data can persist (PDPA G-1). License state survives restore (installation identity and licences are in the DB).
