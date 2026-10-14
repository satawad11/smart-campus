# Existing System Completion — Progress and Test Evidence

**Branch:** `claude/vibrant-yalow-69fe65` · **Updated:** 2026-10-14
**Status: NOT COMPLETE.** Automated suites are green apart from documented environment-only failures, but
acceptance needs human approvals, a pilot host, real cameras and a production model (B5). See
[WORK_BACKLOG](../audit/WORK_BACKLOG.md) and [KNOWN_RISKS](../audit/KNOWN_RISKS.md).

## 1. Fixed on this branch (existing system)
| ID | Fix | Commit |
|---|---|---|
| C-01 | Malformed `*_uuid` filters → 422 instead of PostgreSQL 500 (all endpoints) | `b93197b`, `57f4f64`, `0f0f78d` |
| C-02 | AI service formatting gate | `b4f9b3a` |
| C-03 | PostgreSQL test gate: `scripts/test-backend-pgsql.sh` + CI job `backend-pgsql` | `0f0f78d` (+ readiness fix) |
| C-04 | Shared-password dev seeder only on `local`/`testing` | `0f0f78d` |
| A-24 / A-28 | ApiException logged at INFO; dead `env()` fallback removed | `0f0f78d` |
| A-15 | MFA enrollment UI | `33eeb92` |
| A-17 | Audit log viewer, incidents page, own session management | `affde83`, `d0c95a4`, `ad0847e` |
| CAM-012 | Offline event buffer race (possible event loss) | `53fe7a2` |
| PDPA G-1 | Audited biometric erasure `face:purge` | `7e27aeb` |
| PDPA G-7 | Node health check fails on plain HTTP to a remote backend | `f6699a6` |
| Tests | Flaky orchestrator test (fixed sleeps), PostgreSQL-rounding flake in missed-event recovery test | `2d4fb91`, `0f0f78d` |

## 2. Test evidence (commands run on this branch, Windows workstation)
| Suite | Command | Result |
|---|---|---|
| Backend, PostgreSQL 16 (full) | `RUNNER=local bash scripts/test-backend-pgsql.sh --no-progress` at `f6699a6`-equivalent backend code (`7e27aeb`) | **1154 tests: 1150 pass, 3 failures, 1 error** — all environmental, see §3 |
| Backend, SQLite (full) | `php artisan test` (before the licence fixes) | 1106 passed, 9 incomplete, 34 skipped (sodium absent locally), 0 failed |
| Licence + purge + changed areas, SQLite & PostgreSQL | targeted `--filter` runs | all pass (LicenseManagement 34, LicenseIssuerTool 3, BiometricPurge 10, face suites 64 on PostgreSQL) |
| AI service | `.venv/Scripts/python -m pytest -q` | 311 passed; `ruff check` / `ruff format --check` clean |
| Frontend | `npm run test` / `npm run lint` / `npm run build` | 154 passed at `33eeb92`; later pages add 23 tests run per file, all passing; full re-run pending |

## 3. Explained failures (not product defects)
| Test | Cause | Evidence |
|---|---|---|
| `BackupAndRetentionTest` ×2, `PilotAlertProducersTest::test_successful_backup_raises_no_alert` | PostgreSQL backups shell out to `pg_dump`, which this Windows host lacks (the backend image has it) | job row `FAILED`; `command -v pg_dump` → none |
| `AcademicMasterApiTest::test_reads_require_academic_view` (error) | `08006` connect timeout to the throwaway DB at the start of the run (image restarts after init) | class re-run 31/31; readiness wait fixed in the script |
| `MillionEventPerformanceBenchmarkTest` (earlier run only) | 317 ms vs 200 ms timing budget on this workstation; passed in the latest run | timing-only |

The Docker runner (`RUNNER=docker`) is **not reliable on Windows**: through the bind mount PHPUnit discovered only
313 of 1154 tests. Use `RUNNER=local` here; on Linux CI the `backend-pgsql` job is authoritative.

## 4. Not verifiable here (BLOCKED)
Pilot host, real cameras/RTSP, production model (B5), production backup/restore, 7/14-day pilot, operator training,
human approvals (PILOT-000B, ACR-COM-01, B-RET, A-14).
