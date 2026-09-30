# V1 Requirement Traceability (Independent Re-Audit)

**Scope:** 159 tasks marked `[x]` in TASKS.md (FND, AUTH, STD, FACE, CAM, EVT, RPT, SEC, PILOT-000/PKG).
**Method:** each task group traced to specification → implementation → tests → runtime entry point →
evidence, then **verified on a real path** where possible (production-mode pilot overlay: TLS gateway,
PostgreSQL 16, Redis, MinIO, Reverb, queue, scheduler). TASKS.md checkmarks weren't taken as evidence.

Verdicts: **VERIFIED (real path)** · **VERIFIED (tests)** · **PARTIAL** · **FALSE COMPLETE** · **MISSING EVIDENCE**.

Mechanical ID scan: 27 completed tasks have no task-ID reference in implementation files and 47 none in
tests. Those tasks were traced by behaviour instead; an unreferenced ID isn't a defect by itself.

## Foundation (FND-001..012)
| Area | Implementation | Runtime path | Evidence | Verdict |
|---|---|---|---|---|
| Compose topology, images | `docker-compose.yml`, app Dockerfiles | pilot overlay stack up, all services healthy | rehearsal 2026-09-30 | VERIFIED (real path) |
| PostgreSQL + pgvector + pg_trgm | `infrastructure/postgres/init`, migrations | `vector(512)` column + `hnsw (vector_cosine_ops)` index; restore keeps both | restore checks | VERIFIED (real path) |
| Redis, MinIO | compose, `minio-init` | failure injection on both | operations audit | VERIFIED (real path) |
| Nginx routing | `infrastructure/nginx`, `infrastructure/pilot/nginx` | `/api`, `/internal`, `/reverb`, `/health` via gateway | smoke 26/26, probe 34/34 | VERIFIED (real path) (health routing fixed P08-12) |
| Health checks | `HealthController`, `HealthChecks` | 503 on each dependency outage | failure injection | VERIFIED (real path) (A-09 fixed) |
| CI | `.github/workflows/ci.yml` | not executed (no remote run in this audit) | local equivalents all green | MISSING EVIDENCE (CI run) |
| Dev seed/demo | `DemoDataSeeder` (placeholder, skips in production) | production seed run | PILOT-002 | VERIFIED (real path) |

## Module 01 — Auth/RBAC (AUTH-001..018)
| Area | Implementation | Runtime path | Verdict |
|---|---|---|---|
| Login / CSRF / session | `AuthController`, `AuthenticationService`, session middleware | CSRF cookie → login → session id rotated → `/me` → logout → 401 (real stack) | VERIFIED (real path) |
| Lockout / password policy / change | `AccountSecurityService`, `PasswordService`, `PasswordPolicyRule` | forced change then re-login (real stack) | VERIFIED (real path); server-side forced change **was missing** (A-04, fixed) |
| MFA setup/verify/disable | `MfaController`, `MfaService` | API only; **no enrollment UI** | PARTIAL (A-15) |
| Permission middleware | `RequirePermission` | officer 403 on users/nodes (real stack) | VERIFIED (real path) |
| GLOBAL/SITE/ZONE scope (AUTH-009) | `PermissionService::applyScope`, `EffectiveScope` | **was applied only to the Reverb channel** | FALSE COMPLETE → fixed (A-05); now VERIFIED (real path + tests) |
| User mgmt APIs/UI (AUTH-010/016) | `UserController`, `UserManagementService`, views | create/list via API | PARTIAL: **no role assignment path** (A-01, CLI added); no sessions/login-history UI (A-17) |
| Role/permission APIs/UI (AUTH-011/017) | `RoleController` | tests | VERIFIED (tests) |
| Sessions / force logout (AUTH-012) | `SessionController`, `UserSessionController` | API only | VERIFIED (tests); no UI (A-17) |
| Login history (AUTH-013), audit (AUTH-014) | `LoginHistoryController`, `AuditService` | audit rows observed for bootstrap, role, backup actions | VERIFIED (real path); no UI (A-17) |

## Module 02 — Person/Student/Academic (STD-001..018)
| Area | Implementation | Verdict |
|---|---|---|
| Person root, StudentProfile, status history | models + migrations (FKs, unique, enum CHECK on PostgreSQL) | VERIFIED (tests, PostgreSQL gate) |
| CRUD/search/status workflow | `StudentService`, `StudentController` | VERIFIED (tests) |
| Archive (STD-009), photo storage (STD-010) | API endpoints | VERIFIED (tests); **no UI entry points** (A-17) |
| Import (STD-011..014, 017) | `StudentImportController`, queued job, wizard UI | VERIFIED (tests); queue worker execution proven on real stack with exports |
| Invariant: academic status doesn't alter biometric/login identity | no Face/User/Decision writes in `app/Services/Student`; status read only at decision time | VERIFIED (code) |

## Module 03 — Face biometrics (FACE-001..021)
| Area | Implementation | Verdict |
|---|---|---|
| Model registry, profiles, samples, pgvector search | models, `FaceEmbeddingRepository` (model-scoped search) | VERIFIED (tests) |
| Detection/alignment/quality/embedding/PAD providers | `apps/ai-service` factories | **mock only** in any usable mode (B5). ONNX classes exist without runtime dependency or models | FALSE COMPLETE for production use (B5) |
| Enrollment / verification / duplicate review / revoke / re-enroll | backend services + UI | VERIFIED (tests, mock AI) |
| MATCH/REVIEW/UNKNOWN classification (FACE-013) | `FaceRecognitionClassificationService` | VERIFIED (tests); thresholds FACE-021 enforced by preflight |
| PAD interface (FACE-015) | backend `FaceLivenessService`; ai-service liveness | PAD fail-open fixed (808acf9); disable/mock now caught by preflight (A-12) |
| Benchmark harness (FACE-019) | `app/evaluation/harness.py` | VERIFIED (tests, mock) |
| Raw embeddings never exposed | resources/controllers scan; internal recognize returns UUIDs + scores | VERIFIED (code + real stack broadcast check) |

## Module 04 — Camera / AI node / RTSP (CAM-001..021)
| Area | Verdict |
|---|---|
| Camera CRUD, RTSP credential encryption and masking | VERIFIED (real path): RTSP secret never returned by list/show |
| Node registration / per-node token / revoke | VERIFIED (real path): token shown once, bad token 401, heartbeat 200 via gateway |
| Snapshot (CAM-002) | PARTIAL: always a labelled mock placeholder (A-21) |
| RTSP source / sampler / tracking / worker / cache / offline buffer (CAM-005..012) | VERIFIED (tests with test doubles); **no real camera** in this audit |
| Health collection + stale detection | VERIFIED (real path): `AI_NODE_DOWN` / dependency alerts; CAMERA_DOWN by tests |
| Load benchmark (CAM-020) | SYNTHETIC evidence only |

## Module 05 — Event / policy / realtime (EVT-001..021)
| Area | Verdict |
|---|---|
| Ingestion → idempotency → cooldown → context → policy → decision | VERIFIED (real path): 201, retry 200, tampered 409 |
| Concurrent duplicate delivery | was 500 (A-07), fixed; VERIFIED (real path) `[200, 201]` |
| Decision persistence vs broadcast ordering | was inside transaction (A-08), fixed; VERIFIED (Reverb-down injection) |
| Recognition REVIEW vs policy REVIEW | kept separate: classification in `access_events.metadata`, decision in `access_decisions` | VERIFIED (code) |
| Review queue / append-only review / concurrency | VERIFIED (tests); review now scope-checked (A-05) |
| Reverb channel scope | VERIFIED (real path): cross-site `broadcasting/auth` 403; authorised subscriber received event in 190–361 ms |
| Missed-event recovery | VERIFIED (tests) |
| Load (EVT-021) | SYNTHETIC; capacity claim still PENDING PILOT |

## Module 06 — History / reporting (RPT-001..023)
| Area | Verdict |
|---|---|
| Search, timeline, summaries, daily/weekly/monthly/yearly (Thai academic year May–April)/custom | VERIFIED (tests); **scope was missing** (A-05, fixed) |
| Exports CSV/XLSX/PDF, expiry, notification | CSV via real queue worker VERIFIED (real path); XLSX/PDF VERIFIED (tests) |
| Export ownership | was missing (A-06, fixed) |
| Scheduled reports | VERIFIED (tests) |
| Million-event performance (RPT-023) | MISSING EVIDENCE for raw-event refresh (A-22) |

## Module 07 — Security / pilot readiness (SEC-001..024)
| Task | Verdict |
|---|---|
| SEC-001/002 settings, feature flags | VERIFIED (real path): kill switches, locked features false, 401/403 |
| SEC-003 headers/CORS | VERIFIED (real path) |
| SEC-004 secrets | VERIFIED (scan: no secrets; pilot secrets random, never committed) |
| SEC-005 least-privilege roles | VERIFIED (real path) after d9af363 |
| SEC-006..010 backup, drill, retention | VERIFIED (real path): real pg_dump as backup role, MinIO objects, off-host copy, restore into empty DB, biometric object references resolved 3/3 by SHA-256 |
| SEC-011..014 health, alerts, monitoring, incidents | VERIFIED (real path) for health and alerts; incidents API only (A-17) |
| SEC-015..017 runbooks | DEPLOYMENT/ROLLBACK rewritten; DISASTER-RECOVERY not rehearsed end to end |
| SEC-018 dataset process | documentation only (no pilot data yet) |
| SEC-019 calibration "executed" | **FALSE COMPLETE / MISSING EVIDENCE** (A-19) |
| SEC-020 camera pilot tests | **MISSING EVIDENCE** (A-20 / P08-07) |
| SEC-021..023 UAT, backup UAT, security suite | VERIFIED (tests); did **not** detect A-04/A-05/A-06 (actingAs-based, no cross-scope negatives) |

## Phase 08 (PILOT-000..011, PKG)
Rehearsal evidence only; pilot host, operators and elapsed time PENDING (see PILOT docs).
