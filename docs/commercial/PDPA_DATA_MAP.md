# PDPA Data Inventory and Data-Flow Map

**Status:** TECHNICAL INVENTORY from code (2026-10-13). **Not a legal assessment.** Lawful basis, notices, consent
wording, DPA terms and retention periods need review by the data controller (the institution) and qualified Thai
PDPA counsel. Statuses: VERIFIED (read in code) · DESIGN GAP · DECISION NEEDED.

Biometric data (face images, face embeddings) is **sensitive personal data** under PDPA s.26 and is treated as
*Highly Sensitive* in this platform (`docs/00-system-blueprint.md` §13). Many data subjects are **minors**.

## 1. Data-flow map

```text
 Camera (RTSP, on campus LAN)
   │ video frames — never stored, never sent off-site
   ▼
 AI Node (edge, on campus)
   │ detect → quality → align → embed (probe embedding, in memory only)
   │ POST /internal/v1/face/recognize  {embedding, model}      ──┐ node bearer token
   │ POST /internal/v1/events/recognition {event, candidate, score} │ (per-node credential)
   │ offline buffer: undelivered event payloads (SQLite on node disk)
   ▼                                                             │
 Campus backend (on campus server or dedicated VM)  ◄────────────┘
   │ PostgreSQL: persons, students, users, face embeddings (pgvector), events, decisions, audit
   │ MinIO: face-samples, person-photos, event-snapshots, report-exports (private buckets)
   │ Redis: sessions, cache, queue payloads (transient)
   ▼
 Browser (staff)  — sees names, photos, events; never raw embeddings (PROJECT_RULES §9/§10)
   ▼
 Backups (pg_dump + object archive) → off-host copy
```

Under the recommended tenancy model ([ACR-COM-01](ACR-COM-01-TENANCY-MODEL.md), option B) **nothing in this map
leaves the institution**; the vendor's central plane would hold only licence/fleet metadata.

## 2. Inventory

| Data | Category | Where | Retention today | Status |
|---|---|---|---|---|
| Camera video frames | biometric source | camera → node memory | not stored | VERIFIED (no frame persistence in ai-service) |
| Probe embeddings (live) | biometric | node memory → backend request | not stored by the node; backend matches and discards | VERIFIED |
| Enrolled face embeddings (512-d) | **sensitive** | PostgreSQL `face_profiles.embedding` | until revoked **and** purged (`face:purge`) | G-1 closed technically; when to purge is G-2 |
| Face sample images | **sensitive** | MinIO `face-samples` + `face_samples` rows (SHA-256, path) | until revoked **and** purged (`face:purge`) | G-1 closed technically; G-2 |
| Person photos | personal | MinIO `person-photos` | until replaced/deleted (STD-010) | VERIFIED |
| Person / student profile (name, codes, class, status history) | personal (minors) | PostgreSQL `persons`, `student_profiles`, `student_status_histories` | indefinite (archive, no delete) | DECISION NEEDED |
| Access events + decisions (who, where, when, score) | personal (location) | PostgreSQL `access_events`, `access_decisions` | `retention.events_days` = 90 | VERIFIED (setting) |
| Event snapshots | personal | MinIO `event-snapshots` | `retention.snapshots_days` = 30 | VERIFIED (setting; snapshots not produced yet, A-21) |
| Offline event buffer on node | personal (person UUID + score) | node disk, SQLite, **unencrypted** | until delivered | VERIFIED; G-3 |
| Staff accounts, login logs, sessions | personal | PostgreSQL `users`, `login_logs`, `user_sessions` | login logs 365 d | VERIFIED (setting) |
| Audit logs | personal (staff actions) | PostgreSQL `audit_logs` | `retention.audit_logs_days` | VERIFIED (setting) |
| Report exports | personal | MinIO `report-exports` | 7 days | VERIFIED (setting) |
| Health logs (camera/node) | operational | PostgreSQL | 14 days | VERIFIED |
| Backups | all of the above | backup volume + off-host copy | proposed 48 h recent / 14 d daily | DECISION NEEDED (B-RET) |
| Application logs | may contain usernames, IPs | container logs | host log rotation | DECISION NEEDED |

## 3. Gaps

| ID | Gap | Proposed remedy | Owner |
|---|---|---|---|
| **G-1** | ~~No erasure path for biometric data~~ **Implemented:** `php artisan face:purge <person_uuid> --reason=<DSR ref>` (CLI only; requires all profiles REVOKED; nulls embeddings, deletes sample objects, keeps rows/checksums, audits counts + IDs only; `--dry-run`; idempotent). Copies in backups persist until backup expiry | Controller decides who may run it and when (G-2) |
| G-2 | No retention period for revoked biometric profiles or archived students | Controller decision; then a retention job like `retention.*` | Controller + legal |
| G-3 | Node offline buffer is unencrypted on disk | Full-disk encryption on the node (install guide requirement) or encrypt the buffer file | Engineering + site |
| G-4 | Backups are not encrypted at rest by the application | Encrypted backup volume / off-host target, or `age`/GPG encryption in `backup:run` | Engineering + ops |
| G-5 | No Data Subject Request (DSR) workflow (access/export, correction, erasure, objection) | Runbook using existing student export + G-1 purge; ticket template; SLA set by controller | Controller + engineering |
| G-6 | No privacy notice, consent/legal-basis record, DPIA, or DPA template | Legal documents; the platform can store a consent/legal-basis reference per person later | Legal |
| G-7 | Node → backend traffic may be plain HTTP on the LAN | Require HTTPS for `BACKEND_BASE_URL` in production; preflight check | Engineering |

## 4. Technical controls already in place (VERIFIED in code or earlier audits)
RBAC with GLOBAL/SITE/ZONE scope on REST and realtime; raw embeddings never returned to the browser; private
buckets; per-node hashed credentials; audit log of biometric and admin actions; TLS gateway; session/MFA controls;
retention jobs for events, snapshots, health, exports and login logs.

## 5. What this document does not establish
Compliance. Organisational controls (training, access reviews, breach-notification procedure within 72 h,
records of processing) and legal review are outside the code and are **not done**.
