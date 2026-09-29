# PILOT-003 — Initial Live Backup

**Status: PENDING (live pilot backup) / PASS (rehearsal backup, verified)**
**Rehearsal executed:** 2026-09-29T19:16:52Z (run 1) and 2026-09-29T19:43:15Z (run 2, final)

SEC-024 condition 2 requires `php artisan backup:run --type=ALL --verify` right after
the live pilot migration and seeding. There is no live pilot host yet, so **the live backup
hasn't been taken. PILOT-003 can't PASS until it has been, with the evidence recorded in §4.**
The rehearsal below shows that the command now produces a real, verifiable backup, which
it didn't before this task.

---

## 1. Defects Found Before Any Backup Could Be Trusted

| ID | Defect | Consequence if not fixed | Fix |
|---|---|---|---|
| P08-04 | `pg_dump` missing from the backend image; a failed dump wrote an empty file (or a one-line "simulated export") and recorded it **COMPLETED** | The SEC-024 backup would have been an empty file marked COMPLETED | `postgresql16-client` in the image; failed or empty dump → job FAILED, file removed |
| P08-04 | `backup:run --verify` exited 0 when the restore drill failed | The operator would have seen success for an unverified backup | Exit 1 unless every drill is VERIFIED; a `BACKUP_FAILED` CRITICAL alert is raised |
| P08-04 | Drill accepted any non-empty file as a PostgreSQL backup | A simulated export would pass the drill | Drill requires the `PostgreSQL database dump` header for pgsql backups |
| P08-05 | Storage backup archived only the local `public` disk | Face samples, person photos and event snapshots (MinIO) not backed up | `config/backup.php` object-storage disks added to the archive; unreadable disk → FAILED |
| —      | DB password passed on the `pg_dump` command line | Visible in the process list | Passed via the `PGPASSWORD` process environment |
| P08-16 | Backup files stored in the container's writable layer | Every deploy or recreate silently deletes all backups while their job rows still say VERIFIED | Compose `backend-storage` volume; preflight verifies the file and checksum |

Tests: `BackupIntegrityTest` (7 tests), `PilotAlertProducersTest` (backup cases),
the existing `BackupAndRetentionTest` (unchanged, passes).

## 2. Rehearsal Backup Evidence (TEST DATA, rehearsal DB `pilot_rehearsal`)

Command, run as the least-privilege app user `campus_app_user`:

```
php artisan backup:run --type=ALL --verify      → exit 0 (both runs)
```

### Run 1: 2026-09-29T19:16:52Z. Files later **lost** (P08-16)
| Job | Type | Size | SHA-256 | Status |
|---|---|---|---|---|
| `01a0ee99-37b7-73de-bda5-7c23cfbf7ee7` | POSTGRESQL | 186,990 bytes | `d425698933a744c1fbc61335ce7cee255dbe81f2540e688b27024940a7ea30ec` | VERIFIED |
| `01a0ee99-39ea-7074-a8d6-0baca46bb0c6` | STORAGE | 235 bytes | `6214965f9e84410cd42ca2dc33a36997ccdca8a5576a8d16d96dd043677d7df9` | VERIFIED |

These files lived in the container's writable layer. When the container was recreated,
the files disappeared, but both rows still said VERIFIED. `pilot:preflight --require-backup`
now checks the file and its checksum and reported **FAIL, "backup file missing or checksum mismatch"**.
The rows are kept as evidence. They weren't deleted.

### Run 2 (final): 2026-09-29T19:43:15Z, with a persistent storage volume
| Job | Type | Size | SHA-256 | Status |
|---|---|---|---|---|
| `01a0eeb1-5f6a-718d-b9c2-255cc48c9a8b` | POSTGRESQL (pg_dump 16.15, plain format) | 189,490 bytes | `fd929a65b873475b56bba8f5608d35c8d32e44bbaa814ea43450adb5b48ce906` | VERIFIED |
| `01a0eeb1-6116-704a-9755-eec2f361743a` | STORAGE (public disk + person_photos, face_samples, event_snapshots; all empty in rehearsal) | 235 bytes | `793f5053c0f2b078cc9d371310da4f257c5d43c14b97b46d0251504abab19457` | VERIFIED |

The container was then recreated with the volume kept. `pilot:preflight --require-backup` → **PASS,
0 failures, 1 warning** (Redis has no password; see PILOT-001). Both backup files were present,
with checksums matching.

### Checks required by the task
| Check | Result |
|---|---|
| PostgreSQL backup created | PASS (real pg_dump, plain format, header verified) |
| Object-storage backup created | PASS (archive includes the 3 MinIO buckets; per-bucket object counts recorded in job metadata) |
| Checksum and integrity validation | PASS (SHA-256 recorded; drill VERIFIED; the copy taken off the container matched) |
| Backup job record | PASS (`backup_jobs`: type, target, size, checksum, algorithm, timestamps, metadata) |
| No credential leakage | PASS: the rotated DB password and MinIO secret appear in neither `backup_jobs`, the backup `audit_logs` rows nor the dump file; pg_dump gets its password via the environment |
| Retention metadata | **GAP**: backup files have no retention policy (P08-18); the file mode is 0644 (P08-17) |
| Restore compatibility | PASS: full restore into an empty database (PILOT-009) |

## 3. Live Backup Procedure (pilot host, human-executed)

Run immediately after PILOT-002 step 4 (migrate and seed) and before any enrollment:

```bash
docker compose exec backend php artisan backup:run --type=ALL --verify   # must exit 0
docker compose exec backend php artisan pilot:preflight --require-backup  # must PASS
```

Then copy both backup files off the host to the backup destination (PILOT-001 H9) and
record the checksums below. After enrollment starts, the storage archive contains
biometric source images: store it encrypted and with restricted access.

## 4. Live Backup Record (REAL PILOT DATA; to be filled in on the pilot host)

| Field | Value |
|---|---|
| Date/time (production TZ) | |
| Deployed commit / tag | |
| PostgreSQL job ID / size / SHA-256 / status | |
| Storage job ID / size / SHA-256 / status | |
| `backup:run` exit code | |
| `pilot:preflight --require-backup` result | |
| Off-host copy location and checksum verified | |
| Executed by / witnessed by | |

**PILOT-003 = PASS only when every field above is filled in and both jobs are VERIFIED.**
