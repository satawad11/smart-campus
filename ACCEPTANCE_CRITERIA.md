# ACCEPTANCE_CRITERIA.md

# Global Acceptance Criteria

Every module must satisfy:

- migrations run successfully from a clean database when relevant;
- seeders are idempotent where specified;
- API contracts are respected;
- UUIDs are used for public resource identifiers;
- authorization and scope checks are enforced server-side;
- audit entries exist for sensitive administrative changes;
- errors use stable error codes;
- frontend supports loading, empty, validation, permission, and failure states;
- automated tests pass;
- no secrets are committed;
- no raw face embeddings are exposed to the frontend.

# Module 01 — Authentication + User + RBAC

- Login/logout/me work.
- Invalid credentials do not reveal whether username exists.
- Disabled/locked/expired users cannot log in.
- Admin MFA is supported.
- Roles and permissions are configurable.
- GLOBAL/SITE/ZONE scopes are enforced server-side.
- Security officer cannot create users.
- Disabling a user invalidates active sessions as designed.
- Last SUPER_ADMIN protection exists.
- Sensitive RBAC changes are audited.

# Module 02 — Person + Student + Academic Structure

- Person and StudentProfile remain separate.
- Student code uniqueness is enforced.
- Student status changes use a dedicated operation.
- Status history is append-only.
- Department/program/classroom relationships are validated.
- Search/filter/pagination work server-side.
- Import supports validation, preview, result, and row errors.
- Identity mismatch on import is surfaced for review.
- Student archive does not destroy historical records.

# Module 03 — Face Enrollment + AI Foundation

- Face profiles reference person_id.
- Enrollment validates detection/quality/alignment.
- Model/version is stored.
- Embeddings are stored in pgvector and never exposed to browser.
- Recognition returns MATCH/REVIEW/UNKNOWN.
- Thresholds are configuration/model-driven.
- Duplicate-face warning exists.
- Revoke and re-enroll preserve history.
- Liveness/PAD pipeline exists.
- AI failure does not create partial active profiles.
- Face-sensitive operations are permission-protected and audited.

# Module 04 — Camera + AI Node + RTSP

- Camera CRUD and test work.
- Camera credentials are encrypted and never returned in clear text.
- AI node heartbeat/health work.
- RTSP reconnect works.
- Frame sampling and track-based recognition avoid per-frame duplicate recognition.
- Multi-camera processing is benchmarked.
- Recognition result delivery is idempotent.
- Backend outage supports durable local buffering/retry.
- Time synchronization/clock drift is observable.
- Camera/node health is visible.

# Module 05 — Event + Policy + Realtime Monitor

- Event and Decision are separate records.
- System decision is not overwritten by manual decision.
- ACTIVE student maps to configured default policy behavior.
- SUSPENDED/other exceptional statuses follow configured policy.
- UNKNOWN is handled without creating fake Person records.
- Duplicate UUID/event cooldown is enforced.
- WebSocket broadcast occurs after durable persistence.
- Review queue works.
- Review scope permissions are enforced.
- Concurrent review conflicts are handled.
- Reconnect can recover missed events.

# Module 06 — Historical Search + Reporting

- Historical search filters by date/person/site/zone/camera/event/decision/status.
- Person timeline is correct.
- Dashboard metrics distinguish total events from unique persons.
- Daily/weekly/monthly/yearly/custom reports work.
- Scope applies to reports.
- Large exports are queued.
- XLSX/CSV/PDF exports work.
- Export actions are audited.
- Timezone boundaries are tested.
- Summary/backfill strategy works.

# Module 07 — Security Hardening + Pilot Readiness

- HTTPS/TLS deployment path exists.
- Admin MFA enforced according to configuration.
- Secrets are excluded from repository and frontend.
- Backup jobs exist and restore test procedure is documented/tested.
- Retention policies are configurable and auditable.
- System health/alerts exist.
- Queue/storage/database/AI/camera health are observable.
- Pilot test plan exists.
- AI threshold calibration process exists.
- Incident management/runbook exists.
- Deployment/rollback runbook exists.
- UAT scenarios pass before Production V1.

# Golden Architecture Assertions

These must remain true:

1. AI service cannot directly actuate doors.
2. FaceProfile references Person, not StudentProfile.
3. Frontend permission hiding is not treated as backend authorization.
4. Historical decisions/events are not rewritten.
5. Production V1 keeps Door/Visitor/Vehicle/ALPR/AI Assistant disabled.
