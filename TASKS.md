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
[ ] AUTH-010 Implement user management APIs
[ ] AUTH-011 Implement role/permission APIs
[ ] AUTH-012 Implement session management and force logout
[ ] AUTH-013 Implement login history
[ ] AUTH-014 Implement auth/RBAC audit actions
[ ] AUTH-015 Build Vue login/MFA/change-password
[ ] AUTH-016 Build user management UI
[ ] AUTH-017 Build role/permission matrix UI
[ ] AUTH-018 Add auth/RBAC acceptance/security tests

# MODULE 02 — PERSON + STUDENT + ACADEMIC

[ ] STD-001 Create academic master-data migrations
[ ] STD-002 Create Person migration/model
[ ] STD-003 Create StudentProfile migration/model
[ ] STD-004 Create student status history migration/model
[ ] STD-005 Implement academic master-data APIs
[ ] STD-006 Implement StudentService
[ ] STD-007 Implement student CRUD/search/filter/pagination
[ ] STD-008 Implement student status-change workflow/history
[ ] STD-009 Implement student archive flow
[ ] STD-010 Implement student photo storage
[ ] STD-011 Implement import job/error schema
[ ] STD-012 Implement import upload/mapping/validation/preview
[ ] STD-013 Implement queued student import
[ ] STD-014 Implement import identity-conflict handling
[ ] STD-015 Build student list/detail/form UI
[ ] STD-016 Build status-change/history UI
[ ] STD-017 Build student import wizard UI
[ ] STD-018 Add student/academic acceptance tests

# MODULE 03 — FACE ENROLLMENT + AI FOUNDATION

[ ] FACE-001 Create AI model registry
[ ] FACE-002 Create FaceProfile/FaceSample migrations
[ ] FACE-003 Implement AI service health/readiness
[ ] FACE-004 Implement face detection abstraction
[ ] FACE-005 Implement face alignment
[ ] FACE-006 Implement face quality evaluation
[ ] FACE-007 Implement embedding generation
[ ] FACE-008 Implement pgvector search
[ ] FACE-009 Implement enrollment API/service
[ ] FACE-010 Implement enrollment verification
[ ] FACE-011 Implement duplicate-face review
[ ] FACE-012 Implement recognition test API
[ ] FACE-013 Implement MATCH/REVIEW/UNKNOWN classification
[ ] FACE-014 Implement revoke/re-enroll
[ ] FACE-015 Implement liveness/PAD interface
[ ] FACE-016 Build face enrollment UI
[ ] FACE-017 Build face profile management UI
[ ] FACE-018 Build restricted recognition-test UI
[ ] FACE-019 Add face AI benchmark/evaluation harness
[ ] FACE-020 Add face security/acceptance tests
[ ] FACE-021 [R] Human review of pilot threshold settings

# MODULE 04 — CAMERA + AI NODE + RTSP

[ ] CAM-001 Create Camera/AINode/health schemas
[ ] CAM-002 Implement camera CRUD/test/snapshot
[ ] CAM-003 Implement AI node registration/heartbeat
[ ] CAM-004 Implement per-node authentication
[ ] CAM-005 Implement VideoSource abstraction
[ ] CAM-006 Implement RTSP source and reconnect
[ ] CAM-007 Implement frame sampler
[ ] CAM-008 Implement face tracking
[ ] CAM-009 Implement recognition worker queue
[ ] CAM-010 Implement track/local recognition cache
[ ] CAM-011 Implement recognition-event delivery
[ ] CAM-012 Implement durable offline event buffer/retry
[ ] CAM-013 Implement camera health collection
[ ] CAM-014 Implement AI node health collection
[ ] CAM-015 Implement camera configuration sync
[ ] CAM-016 Implement ROI support
[ ] CAM-017 Build camera management UI
[ ] CAM-018 Build AI-node management UI
[ ] CAM-019 Build camera/node health UI
[ ] CAM-020 Benchmark 1/2/4/8-camera load
[ ] CAM-021 Add camera/AI-node acceptance tests

# MODULE 05 — EVENT + POLICY + REALTIME

[ ] EVT-001 Create event type/access event/access decision schemas
[ ] EVT-002 Create access policy schema/seed defaults
[ ] EVT-003 Implement recognition-event ingestion
[ ] EVT-004 Implement event UUID idempotency
[ ] EVT-005 Implement semantic cooldown/deduplication
[ ] EVT-006 Implement AccessContext and policy matcher
[ ] EVT-007 Implement decision persistence
[ ] EVT-008 Implement event APIs/history filters
[ ] EVT-009 Implement review queue
[ ] EVT-010 Implement manual review append-only decisions
[ ] EVT-011 Implement review concurrency protection
[ ] EVT-012 Implement notifications
[ ] EVT-013 Implement Reverb/WebSocket events
[ ] EVT-014 Implement channel authorization/scope
[ ] EVT-015 Implement missed-event recovery
[ ] EVT-016 Build Live Monitor UI
[ ] EVT-017 Build event detail/review UI
[ ] EVT-018 Build unknown-person page
[ ] EVT-019 Build person event timeline integration
[ ] EVT-020 Add event/policy/realtime acceptance tests
[ ] EVT-021 Load test event ingestion/realtime

# MODULE 06 — HISTORY + REPORTING

[ ] RPT-001 Implement historical search API
[ ] RPT-002 Implement person timeline API
[ ] RPT-003 Create hourly/daily summary structures
[ ] RPT-004 Implement summary refresh/backfill jobs
[ ] RPT-005 Implement dashboard summary
[ ] RPT-006 Implement dashboard charts
[ ] RPT-007 Implement daily report
[ ] RPT-008 Implement weekly report
[ ] RPT-009 Implement monthly report
[ ] RPT-010 Implement yearly/academic-year report
[ ] RPT-011 Implement custom report
[ ] RPT-012 Implement review/unknown/camera/AI-node reports
[ ] RPT-013 Create report export job schema
[ ] RPT-014 Implement queued XLSX export
[ ] RPT-015 Implement queued CSV export
[ ] RPT-016 Implement queued PDF export
[ ] RPT-017 Implement export notification/expiration
[ ] RPT-018 Implement scheduled reports
[ ] RPT-019 Build dashboard UI
[ ] RPT-020 Build historical search UI
[ ] RPT-021 Build report UI/export history
[ ] RPT-022 Add reporting correctness/timezone/scope tests
[ ] RPT-023 Performance test million-event dataset

# MODULE 07 — SECURITY + PILOT READINESS

[ ] SEC-001 Implement system settings service/UI
[ ] SEC-002 Implement backend-enforced feature flags
[ ] SEC-003 Configure production security headers/CORS
[ ] SEC-004 Configure secrets strategy
[ ] SEC-005 Define least-privilege DB/service users
[ ] SEC-006 Implement backup job tracking
[ ] SEC-007 Implement PostgreSQL backup procedure
[ ] SEC-008 Implement object-storage backup procedure
[ ] SEC-009 Document and execute restore drill
[ ] SEC-010 Implement retention cleanup jobs
[ ] SEC-011 Implement system health dashboard
[ ] SEC-012 Implement system alert lifecycle
[ ] SEC-013 Configure metrics/logging/monitoring
[ ] SEC-014 Create incident schema/workflow
[ ] SEC-015 Create deployment runbook
[ ] SEC-016 Create rollback runbook
[ ] SEC-017 Create disaster-recovery runbook
[ ] SEC-018 Create pilot test dataset/process
[ ] SEC-019 Execute AI calibration workflow
[ ] SEC-020 Execute camera/environment pilot tests
[ ] SEC-021 Execute role-based UAT
[ ] SEC-022 Execute backup/restore UAT
[ ] SEC-023 Execute security test suite
[ ] SEC-024 [R] Human Production V1 go/hold review

# FUTURE — LOCKED UNTIL APPROVAL

[ ] [R] DOOR-* Door Access Control
[ ] [R] VIS-* Visitor/Parent
[ ] [R] VEH-* Vehicle + ALPR
[ ] [R] AIA-* AI Assistant + Advanced Analytics
