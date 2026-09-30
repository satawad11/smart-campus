# V1 Operations Audit (Independent Re-Audit)

Real execution on the production-mode pilot overlay (`docker-compose.yml` + `docker-compose.pilot.yml`,
project `smart-campus-pilot`) on 2026-09-30. Configuration files alone weren't accepted as evidence.
TEST DATA.

## 1. Services actually running
| Service | Evidence |
|---|---|
| Queue worker | stopped → export stays `PENDING`; restarted → `COMPLETED` with file (real worker) |
| Scheduler | a scheduled `backup:run --type=POSTGRESQL --verify` fired on its own (02:20:12Z, VERIFIED + off-host copy); `system:monitor` raised REDIS_HEALTH while Redis was down (after fix A-11) |
| Reverb | WSS private-channel delivery, 190–361 ms after ingestion |
| Health | `/health/*` JSON through the TLS gateway; 503 per failed dependency |
| Alerts | AI_SERVICE_HEALTH, STORAGE_HEALTH, REDIS_HEALTH, BACKUP_FAILED raised automatically; one per source until resolved |
| Incidents | API only (no UI, A-17) |

## 2. Failure injection
| Injected failure | Result | Verdict |
|---|---|---|
| Reverb stopped during ingestion | 201; event and decision persisted (after fix A-08; before, the broadcast ran inside the transaction) | PASS |
| MinIO stopped | `/health/storage` and `/health/full` 503; STORAGE_HEALTH alert; `backup:run --type=STORAGE --verify` exit 1 + BACKUP_FAILED; no partial archive | PASS |
| Redis stopped | `/health/redis` 503 (was 500, A-09); API errors sanitized; REDIS_HEALTH raised by the scheduler itself (was never raised, A-11) | PASS after fixes |
| PostgreSQL stopped | `/health/database` 503 after ≈ 16 s (A-23); `system:monitor` exit 1 (an alert can't be stored in the DB: external probe required); recovery: `/health/full` 200 and ingestion 201 after restart | PASS (slow fail, LOW) |
| AI service stopped | AI_SERVICE_HEALTH CRITICAL, one alert over two runs | PASS |
| AI node stale | AI_NODE_DOWN (tests) | PASS (tests) |
| Camera disconnected | CAMERA_DOWN after 120 s (tests; no real camera) | PASS (tests) |
| Queue stopped | work retained and drained on restart | PASS |
| Full `compose restart` | all healthy; backup files (12) and events persisted | PASS |
| Disk full | not injected (not practical on the workstation); preflight warns below 10 GiB free | NOT TESTED |

## 3. Backup and recovery (real content)
1. Three face samples with real objects in MinIO `face-samples` (TEST DATA).
2. `backup:run --type=ALL --verify` exit 0: pg_dump 240,596 bytes as `campus_backup_user`, storage archive with
   `face_samples: 3`, drills VERIFIED, off-host copies re-hashed.
3. Off-host dump checksum matched its `.sha256` sidecar; restored into an **empty database** (`ON_ERROR_STOP=1`, exit 0).
4. Every `face_samples.image_path` in the restored DB resolved to an archive object with a matching SHA-256 (**3/3**).
5. Earlier Phase 08 restores: 50/50 tables, `vector(512)` + extensions, 49 migrations, app reads the restored DB.
6. Backups survive container recreation (`backend-storage` volume) and `compose restart`.
Open: retention values and object-storage RPO (60 min) await human approval. DR runbook not rehearsed end to end.

## 4. Configuration audit
- `env()` inside `app/`: one unreachable fallback (A-28). Everything else goes through config (safe with `config:cache`).
- `.env.example` / pilot template keys not read by config: `BCRYPT_ROUNDS`, `SESSION_COOKIE` (read by framework defaults; INFO).
- Security-relevant settings not in the pilot template use safe defaults (auth lockout, MFA, session flags).
  Thresholds and PAD are guarded by preflight.
- Dangerous defaults found and handled: PAD bypass/mock (A-12), 30 s DB connect timeout (A-10), Redis-bound
  scheduler locks (A-11), snapshot provider always mock (A-21, open).
- Future feature flags: backend-enforced kill switches verified (security audit §8).

## 5. Seeders
| Seeder | Production behaviour |
|---|---|
| Permission / Role / RolePermission / EventType / AccessPolicy | minimal reference data; RolePermission non-destructive after first run |
| SystemSettingSeeder | settings incl. `feature.*` false |
| DemoDataSeeder | self-skips in production (placeholder, creates nothing) |
| InitialAdminUserSeeder | no-op in production; not called by DatabaseSeeder; shared-password accounts detected by preflight |
No seeder creates students, biometric records, cameras, nodes or API credentials.

## 6. Regression (audited code `d497fb2`)
| Gate | Result |
|---|---|
| Backend SQLite | 1088 passed, 8 incomplete (A-30, justified), 0 failed |
| Backend PostgreSQL 16 | 1095 passed + the corrected A-16 test re-run 41/41 on both databases; 0 failed |
| Pint | PASS (522 files) |
| AI pytest / ruff check / ruff format | 286 passed / clean / clean |
| Frontend vitest / ESLint / production build | 149/149 / clean / OK. Typecheck: N/A (JavaScript project, no TypeScript) |
| Real-path probe | 34/34 |
| Pilot HTTPS smoke | 26/26 (Phase 08 set) |
| `pilot:preflight --require-backup` (rehearsal) | FAIL on exactly the expected items: `AI_MODE=mock` (B5) and local CORS origin; scope WARN from probe test data |
| Host preflight H1–H14 | H3–H14 PASS; H1/H2 FAIL on the workstation (self-signed, no DNS), as expected; H10 external probe and H11 NTP MANUAL |
