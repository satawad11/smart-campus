# Pilot Backup & Retention Policy

**Status: APPROVED BY OWNER DELEGATION (2026-10-14)** — the project owner instructed the agent to proceed with its recommendations ("ดำเนินการตามที่คุณแนะนำ"); the values below are those recommendations. No separate line-by-line human review was performed; the owner may override any value.
The mechanism (schedule, verification, off-host copy, pruning, alerting) is implemented and was
rehearsed. The exact **frequencies and retention durations below are proposed defaults.** No human
has approved them. They are set in `apps/backend/.env` (template: `infrastructure/pilot/pilot.env.example`)
and can be changed without a code change.

Approved targets this policy must respect (SEC-017): **RPO < 15 minutes, RTO < 60 minutes.**

---

## 1. Policy

| Item | Proposed value | Setting | Mechanism |
|---|---|---|---|
| PostgreSQL backup frequency | every 10 minutes | `BACKUP_DATABASE_CRON="*/10 * * * *"` | scheduler → `backup:run --type=POSTGRESQL --verify` (pg_dump, plain format, as `campus_backup_user`) |
| Object-storage backup frequency | hourly (minute 5) | `BACKUP_STORAGE_CRON="5 * * * *"` | scheduler → `backup:run --type=STORAGE --verify` (full archive of person_photos, face_samples, event_snapshots + public disk) |
| Verification cadence | **every backup** | `--verify` | SHA-256 recorded; drill re-hashes, checks the pg_dump header or zip consistency; exit 1 + alert on failure |
| Restore drill cadence | automated drill per backup; **full restore into an empty non-production database weekly** and at pilot day 7 | manual (PILOT-009 §2.3) | `psql -v ON_ERROR_STOP=1` into a scratch DB, row-count comparison |
| Local retention | keep all backups < 48 h; the newest verified backup per day for 14 days; never fewer than 3 verified per type | `BACKUP_RETENTION_RECENT_HOURS=48`, `BACKUP_RETENTION_DAILY_DAYS=14`, `BACKUP_RETENTION_MIN_VERIFIED=3` | `backup:prune` daily 03:30. Removes the file only; the job row is kept with `metadata.pruned_at` (audit) |
| Off-host copy | **mandatory**: every verified backup | `BACKUP_OFFHOST_PATH=/var/backups/offhost` (mounted from `BACKUP_OFFHOST_HOST_PATH`) | copied right after verification, `.sha256` sidecar written, copy re-hashed; mismatch → failure + alert |
| Off-host retention | managed by the off-host target (e.g. NAS snapshot/lifecycle rule); proposed ≥ 30 days | target-side | not pruned by the application |
| Encryption | **required** at rest and in transit on the off-host target (encrypted volume or NAS share; SMB3 encryption or NFS over a private link). Local backups sit on the host's encrypted disk | infrastructure | the application does not encrypt archives itself (see §3) |
| Access control | backup files 0600; backup directory root-only; off-host share restricted to the backup service account | code + infrastructure | |
| Failure alerting | `BACKUP_FAILED` CRITICAL system alert on any failed backup, drill or off-host copy | `backup:run` | dashboard only; no email/paging in V1 (PILOT-008 §2.3) |
| Go-live gate | `pilot:preflight --require-backup` fails without a verified backup < 24 h whose file and off-host copy still exist with matching checksums, with the schedule disabled, or with no writable off-host target | preflight | |

---

## 2. RPO / RTO Assessment (honest)

| Data | Proposed frequency | Worst-case data loss | RPO < 15 min? |
|---|---|---|---|
| PostgreSQL (identities, embeddings, events, decisions, audit) | 10 min, copied off-host immediately | ≈ 10 min + dump time (seconds at pilot size) | **YES**, provided the off-host copy succeeds (alerted when it doesn't) |
| Object storage (face sample images, person photos, event snapshots) | 60 min | up to ≈ 60 min | **NO**. Flagged |

**Why object storage can't meet RPO < 15 min with the current mechanism:** `backup:run --type=STORAGE`
creates a *full* archive of every object each run. Running it every 10 minutes would copy all face samples
and snapshots six times an hour, and the cost grows with every event snapshot. Meeting 15 minutes needs
**incremental replication**, e.g. MinIO bucket replication or `mc mirror --watch` to the off-host target.
That's an infrastructure addition and needs a human decision. Impact if accepted as-is: recognition keeps
working after a storage loss, because embeddings live in PostgreSQL (10-minute RPO). What could be lost is up
to 60 minutes of event snapshot evidence and newly uploaded enrollment images.

**RTO < 60 min:** a restore of the rehearsal database (≈ 190 KB dump, 50 tables) into an empty database
completed with no errors (PILOT-009). At pilot scale (80 students) the dump stays small. Stack redeploy plus
restore is expected to fit in 60 minutes, but **this hasn't been timed on the pilot host**. It must be
measured during the day-7 full restore test. Status: **PENDING MEASUREMENT**.

## 3. Known Limitations (for the approver)
1. Object-storage RPO is 60 minutes (§2). Decide: accept for the pilot, or add incremental replication.
2. Archives aren't encrypted by the application. Encryption relies on the off-host target and host disk. Decide: accept for the pilot, or add archive encryption.
3. Backup and retention alerts are dashboard-only (no paging).
4. Storage archives are full copies. Size them before go-live:
   `local space ≈ (48 + 14) × storage-archive size + ≈ 300 × DB dump size`.
   Preflight requires ≥ 10 GiB free.

## 4. Approval (human)

| Decision | Value |
|---|---|
| PostgreSQL frequency (proposed 10 min) | ☑ approve (10 min) |
| Object-storage frequency (proposed 60 min) and RPO exception | ☑ approve exception **for the pilot** (embeddings are in PostgreSQL at 10-min RPO; samples can be re-captured). Revisit incremental replication before multi-site commercial use |
| Local retention (48 h all / 14 days daily / min 3) | ☑ approve |
| Off-host retention (proposed ≥ 30 days) | ☑ approve (30 days) |
| Encryption approach | ☑ require archive encryption: set `BACKUP_ENCRYPTION_KEY` on every pilot/customer host (implemented `49d70e0`); key kept in a vault apart from the backups |
| Approver / date | Project owner by delegation to the agent's recommendation, 2026-10-14 (see docs/decisions/OWNER-DECISIONS-2026-10-14.md) |

Recorded 2026-10-14 (owner delegation). History: on 2026-09-30 the decision message left these values as unfilled placeholders.
