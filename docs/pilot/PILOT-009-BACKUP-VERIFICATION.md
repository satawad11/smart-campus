# PILOT-009 — Backup Verification

**Status: PASS (non-production full restore test) / PENDING (pilot continuity: daily backups over the pilot period)**
**Restore tests executed:** 2026-09-29 ~19:17Z and 19:44Z (rehearsal)

| Required check | Result |
|---|---|
| Scheduled database backup | **GAP**: not scheduled in code (P08-18); daily manual or host timer during the pilot |
| Object-storage backup | PASS in rehearsal (MinIO buckets included since P08-05) |
| Backup verification | PASS (drill VERIFIED; full restore below) |
| Retention | **GAP**: no retention for backup files (P08-18) |
| Backup alerts | PASS: `BACKUP_FAILED` CRITICAL alert on any failed backup or drill (P08-06) |
| Restore metadata | PASS: `RESTORE_DRILL` job rows reference `source_job_id`, checksum and size |
| Backups survive a redeploy | PASS after fix (P08-16) |

---

## 1. Full Restore Test (TEST DATA, rehearsal, non-production)

The automated restore drill (`backup:restore-drill`) checks checksum, header and archive
integrity. It **doesn't** restore data. This task also performed a real restore:

Performed twice, once per PILOT-003 backup run. Both passed.

| Step | Restore 1 (2026-09-29 ~19:17Z) | Restore 2, final (2026-09-29T19:44:03Z) |
|---|---|---|
| Source job | `01a0ee99-37b7-73de-bda5-7c23cfbf7ee7`, 186,990 bytes | `01a0eeb1-5f6a-718d-b9c2-255cc48c9a8b`, 189,490 bytes |
| Copy off the container, SHA-256 | `d4256989…a30ec`, **matches** the job record | `fd929a65…ce906`, **matches** the job record |
| Restore target | new, empty database `pilot_restore` (same PostgreSQL 16.15 server) | recreated empty `pilot_restore` |
| `psql -v ON_ERROR_STOP=1 -d pilot_restore < dump.sql` | **exit 0** | **exit 0** |
| Tables restored | 50 of 50 | 50 of 50 |
| Row counts vs source | 48 identical; `backup_jobs` 1 vs 4, `audit_logs` 0 vs 4 | 48 identical; `backup_jobs` 5 vs 8, `audit_logs` 4 vs 8 |

Only rows written **after** the dump snapshot differ: the backup run's own completion,
restore-drill and audit records. That is expected point-in-time behaviour, not data loss.

Additional checks on restore 1:
`face_profiles.embedding` restored as `vector(512)`; extensions vector 0.8.6 and pg_trgm 1.6;
`migrate:status` 49 Ran, 0 Pending; application connected to the restored DB (5 roles, 38 settings).

Storage archives (`01a0ee99-39ea-…`, `01a0eeb1-6116-…`): passed the zip consistency check.
The buckets were empty in rehearsal, so object content restore wasn't exercised against real
files. `BackupIntegrityTest::test_storage_backup_includes_object_storage_buckets` checks that
bucket objects are archived and readable byte-for-byte.

**Conclusion (rehearsal):** a V1 backup produced by `backup:run --type=ALL --verify` restores into an
empty database and the application can run on it.

---

## 2. Pilot Continuity Verification (PENDING, REAL PILOT DATA)

### 2.1 Required schedule (proposal for human confirmation)
`routes/console.php` doesn't schedule `backup:run`. SEC-007 says "daily", but nothing
automates it. For the pilot, the SYSTEM_ADMIN either runs it daily by hand or adds a host
cron or systemd timer:

```bash
docker compose exec -T backend php artisan backup:run --type=ALL --verify
```

A failure raises a `BACKUP_FAILED` CRITICAL alert (PILOT-008). Adding a scheduler entry is
a candidate post-pilot change. It wasn't added here because frequency and destination are
operator decisions.

### 2.2 Daily backup log (fill in from `backup_jobs`)
| Pilot day | PostgreSQL job / status / size | Storage job / status / size | Off-host copy verified | Notes |
|---|---|---|---|---|
| 1 | | | | |

### 2.3 Mid-pilot full restore test (at least once, around day 7)
Repeat §1 against a copy of a **real** pilot backup in a non-production database. Record the
row-count comparison and the restored face-sample object count. Don't restore into the
live pilot database.

| Field | Value |
|---|---|
| Date | |
| Backup job IDs | |
| Tables identical / differing (with reason) | |
| Object count archived vs restored per bucket | |
| Result | |
| Executed by | |

## Pre-Deployment Update (2026-09-30)

Production candidate: code at `72ee47b`, release tag `v1.0.0-pilot`. Full package and rehearsal: `PILOT-HOST-DEPLOYMENT-CHECKLIST.md`; delta review: `PILOT-000`.

**Status: PENDING PILOT CONTINUITY.** The gaps in the table above are closed in software: scheduled backups
(DB every 10 min, storage hourly), retention (`backup:prune`), verified off-host copies. On the overlay rehearsal a
**scheduled** PostgreSQL backup fired by itself at 2026-09-30T02:20:12Z (VERIFIED, off-host copy verified), and a restore
from the off-host copy succeeded. RPO: PostgreSQL meets < 15 min; object storage (hourly) does **not**. Flagged in
`PILOT-BACKUP-RETENTION-POLICY.md` §2.
