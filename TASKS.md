# TASKS.md

Legend:
- `[ ]` not started
- `[~]` in progress
- `[x]` complete and tests passing
- `[!]` blocked
- `[R]` requires human review/approval

Only mark `[x]` when the relevant tests pass.

# FOUNDATION

[x] FND-001 Create monorepo directory structure
[x] FND-002 Create Docker Compose base services
[x] FND-003 Bootstrap Laravel backend
[x] FND-004 Bootstrap Vue 3 frontend
[x] FND-005 Bootstrap FastAPI AI service
[x] FND-006 Configure PostgreSQL + pgvector + pg_trgm
[x] FND-007 Configure Redis
[x] FND-008 Configure S3-compatible object storage
[x] FND-009 Configure Nginx development routing
[x] FND-010 Create health checks
[x] FND-011 Create base CI pipeline
[x] FND-012 Create development seed/demo environment

# MODULE 01 — AUTHENTICATION + USER + RBAC

[x] AUTH-001 Create user/RBAC/session/login-log migrations
[x] AUTH-002 Create User/Role/Permission models and relationships
[x] AUTH-003 Create idempotent RBAC seeders
[x] AUTH-004 Implement AuthenticationService
[x] AUTH-005 Implement login/logout/me APIs
[x] AUTH-006 Implement account lock and password policy
[x] AUTH-007 Implement MFA setup/verify/disable
[x] AUTH-008 Implement permission middleware/service
[x] AUTH-009 Implement GLOBAL/SITE/ZONE scope authorization
[x] AUTH-010 Implement user management APIs
[x] AUTH-011 Implement role/permission APIs
[x] AUTH-012 Implement session management and force logout
[x] AUTH-013 Implement login history
[x] AUTH-014 Implement auth/RBAC audit actions
[x] AUTH-015 Build Vue login/MFA/change-password
[x] AUTH-016 Build user management UI
[x] AUTH-017 Build role/permission matrix UI
[x] AUTH-018 Add auth/RBAC acceptance/security tests

# MODULE 02 — PERSON + STUDENT + ACADEMIC

[x] STD-001 Create academic master-data migrations
[x] STD-002 Create Person migration/model
[x] STD-003 Create StudentProfile migration/model
[x] STD-004 Create student status history migration/model
[x] STD-005 Implement academic master-data APIs
[x] STD-006 Implement StudentService
[x] STD-007 Implement student CRUD/search/filter/pagination
[x] STD-008 Implement student status-change workflow/history
[x] STD-009 Implement student archive flow
[x] STD-010 Implement student photo storage
[x] STD-011 Implement import job/error schema
[x] STD-012 Implement import upload/mapping/validation/preview
[x] STD-013 Implement queued student import
[x] STD-014 Implement import identity-conflict handling
[x] STD-015 Build student list/detail/form UI
[x] STD-016 Build status-change/history UI
[x] STD-017 Build student import wizard UI
[x] STD-018 Add student/academic acceptance tests

# MODULE 03 — FACE ENROLLMENT + AI FOUNDATION

[x] FACE-001 Create AI model registry
[x] FACE-002 Create FaceProfile/FaceSample migrations
[x] FACE-003 Implement AI service health/readiness
[x] FACE-004 Implement face detection abstraction
[x] FACE-005 Implement face alignment
[x] FACE-006 Implement face quality evaluation
[x] FACE-007 Implement embedding generation
[x] FACE-008 Implement pgvector search
[x] FACE-009 Implement enrollment API/service
[x] FACE-010 Implement enrollment verification
[x] FACE-011 Implement duplicate-face review
[x] FACE-012 Implement recognition test API
[x] FACE-013 Implement MATCH/REVIEW/UNKNOWN classification
[x] FACE-014 Implement revoke/re-enroll
[x] FACE-015 Implement liveness/PAD interface
[x] FACE-016 Build face enrollment UI
[x] FACE-017 Build face profile management UI
[x] FACE-018 Build restricted recognition-test UI
[x] FACE-019 Add face AI benchmark/evaluation harness
[x] FACE-020 Add face security/acceptance tests
[x] FACE-021 [R] Human review of pilot threshold settings — HUMAN APPROVED (see docs/FACE-021-PILOT-THRESHOLDS-REVIEW.md)
[x] FACE-022 Webcam scanning: contract §8 POST /faces/check-quality (face boxes + quality, no embedding) and
    POST /faces/recognize (MATCH/REVIEW/UNKNOWN + person summary, audited, no access decision); webcam
    enrollment wizard with live capture indicators (docs/03 §10) and "สแกนใบหน้า" page; live check with
    real YuNet/SFace models and a physical webcam still PENDING HOST

# MODULE 04 — CAMERA + AI NODE + RTSP

[x] CAM-001 Create Camera/AINode/health schemas
[x] CAM-002 Implement camera CRUD/test/snapshot
[x] CAM-003 Implement AI node registration/heartbeat
[x] CAM-004 Implement per-node authentication
[x] CAM-005 Implement VideoSource abstraction
[x] CAM-006 Implement RTSP source and reconnect
[x] CAM-007 Implement frame sampler
[x] CAM-008 Implement face tracking
[x] CAM-009 Implement recognition worker queue
[x] CAM-010 Implement track/local recognition cache
[x] CAM-011 Implement recognition-event delivery
[x] CAM-012 Implement durable offline event buffer/retry
[x] CAM-013 Implement camera health collection
[x] CAM-014 Implement AI node health collection
[x] CAM-015 Implement camera configuration sync
[x] CAM-016 Implement ROI support
[x] CAM-017 Build camera management UI
[x] CAM-018 Build AI-node management UI
[x] CAM-019 Build camera/node health UI
[x] CAM-020 Benchmark 1/2/4/8-camera load
[x] CAM-021 Add camera/AI-node acceptance tests

# MODULE 05 — EVENT + POLICY + REALTIME

[x] EVT-001 Create event type/access event/access decision schemas
[x] EVT-002 Create access policy schema/seed defaults
[x] EVT-003 Implement recognition-event ingestion
[x] EVT-004 Implement event UUID idempotency
[x] EVT-005 Implement semantic cooldown/deduplication
[x] EVT-006 Implement AccessContext and policy matcher
[x] EVT-007 Implement decision persistence
[x] EVT-008 Implement event APIs/history filters
[x] EVT-009 Implement review queue
[x] EVT-010 Implement manual review append-only decisions
[x] EVT-011 Implement review concurrency protection
[x] EVT-012 Implement notifications
[x] EVT-013 Implement Reverb/WebSocket events
[x] EVT-014 Implement channel authorization/scope
[x] EVT-015 Implement missed-event recovery
[x] EVT-016 Build Live Monitor UI
[x] EVT-017 Build event detail/review UI
[x] EVT-018 Build unknown-person page
[x] EVT-019 Build person event timeline integration
[x] EVT-020 Add event/policy/realtime acceptance tests
[x] EVT-021 Load test event ingestion/realtime
[x] EVT-022 Unknown-person / denied alerts in the console: header bell (unread count, inbox, read/read-all),
    on-screen alert + optional sound for new UNKNOWN/DENY notifications; notification data gains camera and time

# MODULE 06 — HISTORY + REPORTING

[x] RPT-001 Implement historical search API
[x] RPT-002 Implement person timeline API
[x] RPT-003 Create hourly/daily summary structures
[x] RPT-004 Implement summary refresh/backfill jobs
[x] RPT-005 Implement dashboard summary
[x] RPT-006 Implement dashboard charts
[x] RPT-007 Implement daily report
[x] RPT-008 Implement weekly report
[x] RPT-009 Implement monthly report
[x] RPT-010 Implement yearly/academic-year report
[x] RPT-011 Implement custom report
[x] RPT-012 Implement review/unknown/camera/AI-node reports
[x] RPT-013 Create report export job schema
[x] RPT-014 Implement queued XLSX export
[x] RPT-015 Implement queued CSV export
[x] RPT-016 Implement queued PDF export
[x] RPT-017 Implement export notification/expiration
[x] RPT-018 Implement scheduled reports
[x] RPT-019 Build dashboard UI
[x] RPT-020 Build historical search UI
[x] RPT-021 Build report UI/export history
[x] RPT-022 Add reporting correctness/timezone/scope tests
[x] RPT-023 Performance test million-event dataset

# MODULE 07 — SECURITY + PILOT READINESS

[x] SEC-001 Implement system settings service/UI
[x] SEC-002 Implement backend-enforced feature flags
[x] SEC-003 Configure production security headers/CORS
[x] SEC-004 Configure secrets strategy
[x] SEC-005 Define least-privilege DB/service users
[x] SEC-006 Implement backup job tracking
[x] SEC-007 Implement PostgreSQL backup procedure
[x] SEC-008 Implement object-storage backup procedure
[x] SEC-009 Document and execute restore drill
[x] SEC-010 Implement retention cleanup jobs
[x] SEC-011 Implement system health dashboard
[x] SEC-012 Implement system alert lifecycle
[x] SEC-013 Configure metrics/logging/monitoring
[x] SEC-014 Create incident schema/workflow
[x] SEC-015 Create deployment runbook
[x] SEC-016 Create rollback runbook
[x] SEC-017 Create disaster-recovery runbook
[x] SEC-018 Create pilot test dataset/process
[x] SEC-019 Execute AI calibration workflow
[x] SEC-020 Execute camera/environment pilot tests
[x] SEC-021 Execute role-based UAT
[x] SEC-022 Execute backup/restore UAT
[x] SEC-023 Execute security test suite
[x] SEC-024 [R] Human Production V1 go/hold review

# PHASE 08 — PILOT DEPLOYMENT & STABILIZATION

Pilot scope: 1 Site, 1 Gate, 2 Cameras, 1 AI Node, 80 Students, 5 Operators.
Evidence: `docs/pilot/PILOT-0xx-*.md`. `[~]` = software/rehearsal part done; the
human, physical or elapsed-time part is still open (see each document's status).

Production candidate: code at `72ee47b`, tag `v1.0.0-pilot` (PILOT-000 delta review: APPROVED FOR
PILOT DEPLOYMENT; live biometric operation HOLD, blocker B5: no production face/PAD model).

[x] PILOT-000 Production candidate delta review (APPROVED FOR PILOT DEPLOYMENT; B4 human-APPROVED 2026-09-30)
[x] PILOT-PKG Pilot host deployment package + backup/retention policy (retention AWAITING HUMAN APPROVAL)
[~] PILOT-001 Production environment validation (overlay rehearsal H3–H14 PASS; PENDING HOST)
[~] PILOT-002 Deploy approved V1 (Compose overlay rehearsal PASS; PENDING HOST)
[~] PILOT-003 Initial live backup (verified + off-host rehearsal backup; PENDING LIVE BACKUP)
[~] PILOT-004 Operator onboarding (package ready; AWAITING HUMAN OPERATOR COMPLETION)
[~] PILOT-005 Production smoke test (HTTPS rehearsal smoke 26/26; PENDING PHYSICAL WALKTHROUGH)
[~] PILOT-006 Camera/AI-node stability (procedure ready; AWAITING PILOT ELAPSED TIME / REAL DATA)
[~] PILOT-007 Recognition quality review (procedure ready; AWAITING PILOT ELAPSED TIME / REAL DATA; blocked by B5)
[~] PILOT-008 Incident/alert report (pre-pilot incidents fixed, alert producers added; live log AWAITING REAL DATA)
[~] PILOT-009 Backup verification (scheduled + off-host rehearsal backups and restores PASS; PENDING PILOT CONTINUITY)
[~] PILOT-010 7/14-day review (template ready; AWAITING PILOT ELAPSED TIME / REAL DATA)
[ ] PILOT-011 [R] Human pilot acceptance — AWAITING HUMAN PILOT ACCEPTANCE

# V1 INDEPENDENT RE-AUDIT (2026-09-30) — docs/audit/

Gate: READY FOR PILOT WITH CONDITIONS (live biometrics HOLD on B5). Original checkmarks above are
kept as recorded history; the audit annotates them instead of rewriting them:

[x] AUDIT-A05 AUTH-009 scope was enforced only on Reverb, not REST (BLOCKER) — fixed 5b04fac
[x] AUDIT-A01 No first-admin / role-assignment path (BLOCKER) — CLI added 87604a6; UI/API still a human decision
[x] AUDIT-A02..A13 Confirmed defects fixed (forced password change, export IDOR, ingestion race,
    broadcast-in-transaction, Redis-bound scheduler, health/session coupling, DB timeout, PAD preflight,
    login throttle, site/zone provisioning, export serialization)
[!] AUDIT-B5 No production face/PAD model — live biometric operation HOLD (human decision)
[ ] [R] AUDIT-A15 MFA enrollment UI missing (AUTH-015 partial) — human decision
[ ] [R] AUDIT-A14 SECURITY_OFFICER dashboard permission mismatch — human RBAC decision
[ ] AUDIT-A19 SEC-019 calibration has no execution evidence (requires B5)
[ ] AUDIT-A20 SEC-020 physical camera results have no raw evidence
Open register: docs/audit/V1-OPEN-FINDINGS.md

# OPERATIONS

[x] OPS-001 Web "System update" page (อัปเดตระบบ): versions, Thai release notes, history, one-click update
    via the host update agent — ACR-OPS-01 APPROVED 2026-11-10 (docs/decisions/ACR-OPS-01-WEB-SYSTEM-UPDATE.md);
    automated tests pass; end-to-end run on a real campus server with systemd still PENDING HOST
[x] OPS-002 Read host update configuration as validated data; never source update.env as shell
[x] OPS-003 Block dependency scans on high/critical advisories; keep lower severities advisory-only
[x] INT-002 External API connection settings page (การเชื่อมต่อระบบภายนอก): RMS address, encrypted write-only key,
    timeout, daily sync time, connection test — ACR-INT-02 APPROVED 2026-11-12
    (docs/decisions/ACR-INT-02-INTEGRATION-SETTINGS-UI.md); live test against RMS still PENDING HOST

# FUTURE PHASES — APPROVED 2026-11-16, BUILT ONE AT A TIME

Approved by the owner (docs/decisions/OWNER-DECISIONS-2026-11-16.md). Each module needs an accepted spec before code;
feature flags stay false until the module is complete.

[ ] [R] DOOR-* Door Access Control (module 08) — after Module 09; needs controller hardware/protocol from the owner
[~] [R] VIS-* / PPL-* People: staff, visitors, parents (module 09) — spec ACCEPTED: docs/12-module-09-people-visitor.md
[ ] [R] VEH-* Vehicle + ALPR (module 10) — needs plate model chosen and licence-checked
[ ] [R] AIA-* AI Assistant + Advanced Analytics (module 11) — needs provider chosen

## Module 09 — People: staff, visitors, parents

[x] PPL-001 Owner accepts docs/12-module-09-people-visitor.md — ACCEPTED 2026-11-16 with default answers
[x] PPL-002 Migrations + models: staff_profiles (+ status histories), guardian_links, visitor_profiles, visits;
    11 permissions + role matrix (fresh seed and migration for existing installs)
[x] PPL-003 Staff API: CRUD, status change workflow + history, audit (/staff, docs/02 §5)
[ ] PPL-004 Staff import (csv/xlsx) reusing the student import wizard
[ ] PPL-005 Guardian links API + student detail tab
[ ] PPL-006 Visitor + visit API (check-in/out/cancel/expire), FEATURE_VISITOR_ENABLED gate
[ ] PPL-007 Person search API (all person types) for face enrollment and pickers
[ ] PPL-008 AccessContextResolver: STAFF / VISITOR / PARENT subjects + seeded policies
[ ] PPL-009 Visitor face retention: automatic revoke N days after the last visit (scheduled, audited)
[ ] PPL-010 UI: จัดการบุคลากร (list, detail, status, create/edit, import)
[ ] PPL-011 UI: ผู้ปกครอง + ผู้มาติดต่อ (visits today, check-in/out); face enrollment person picker by type
[ ] PPL-012 Events/history/reports show person group and status; acceptance tests
