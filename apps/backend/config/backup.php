<?php

/*
|--------------------------------------------------------------------------
| Backup Configuration (SEC-008 / PILOT-003 / PILOT-BACKUP-RETENTION-POLICY)
|--------------------------------------------------------------------------
|
| object_storage_disks: object-storage disks (config/filesystems.php) whose
| contents are archived by `backup:run --type=STORAGE` in addition to the
| local public disk, under "<disk>/". A disk that cannot be listed fails the
| job. report_exports is excluded: exports are temporary (RPT-017).
|
| The schedule, retention and off-host values below are PROPOSED pilot
| defaults (docs/pilot/PILOT-BACKUP-RETENTION-POLICY.md), pending human
| retention approval. Scheduling is off unless BACKUP_SCHEDULE_ENABLED=true
| so development stacks do not accumulate backups.
|
*/

$disks = env('BACKUP_OBJECT_STORAGE_DISKS', 'person_photos,face_samples,event_snapshots');

return [

    'object_storage_disks' => array_values(array_filter(array_map('trim', explode(',', (string) $disks)))),

    'schedule' => [
        'enabled' => (bool) env('BACKUP_SCHEDULE_ENABLED', false),
        'database_cron' => env('BACKUP_DATABASE_CRON', '*/10 * * * *'),
        'storage_cron' => env('BACKUP_STORAGE_CRON', '5 * * * *'),
        'prune_cron' => env('BACKUP_PRUNE_CRON', '30 3 * * *'),
    ],

    // backup:prune keeps every backup from the last `recent_hours`, the newest
    // verified backup of each day for `daily_days`, and never fewer than
    // `min_verified` verified backups per type. Job rows are never deleted.
    'retention' => [
        'recent_hours' => (int) env('BACKUP_RETENTION_RECENT_HOURS', 48),
        'daily_days' => (int) env('BACKUP_RETENTION_DAILY_DAYS', 14),
        'min_verified' => (int) env('BACKUP_RETENTION_MIN_VERIFIED', 3),
    ],

    // Directory on a separate (off-host) mount. When set, every verified backup
    // is copied there with a .sha256 sidecar and the copy's checksum re-verified.
    'offhost_path' => env('BACKUP_OFFHOST_PATH'),

    /*
    | PDPA G-4: optional backup encryption (libsodium secretstream). 32 random
    | bytes, base64 (php artisan backup:key). Keep the key in a password vault,
    | NOT next to the backups: without it encrypted backups can't be restored.
    | Empty = backups are written in plaintext (rely on volume encryption).
    */
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // COM-05: system:monitor alerts when the volume holding storage/ (local
    // backups, exports, logs) has less free space than this percentage.
    'disk_min_free_percent' => (float) env('SERVER_DISK_MIN_FREE_PERCENT', 10),

    // Optional read-only role for pg_dump (SEC-005 campus_backup_user). Falls
    // back to the application connection credentials when unset.
    'database' => [
        'username' => env('BACKUP_DB_USERNAME'),
        'password' => env('BACKUP_DB_PASSWORD'),
    ],

];
