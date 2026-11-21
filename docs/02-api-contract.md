# AI Smart Campus Security & Access Platform — API Contract V1

## 1. Prefixes

Public/admin:
`/api/v1`

Internal service:
`/internal/v1`

Future device:
`/device/v1`

## 2. Response Contract

Success:
```json
{
  "success": true,
  "message": "Success",
  "data": {},
  "meta": null
}
```

Error:
```json
{
  "success": false,
  "message": "Validation failed",
  "error": {
    "code": "VALIDATION_ERROR",
    "fields": {}
  }
}
```

## 3. Auth

- POST `/auth/login`
- POST `/auth/logout`
- GET `/auth/me`
- POST `/auth/change-password`
- POST `/auth/mfa/setup`
- POST `/auth/mfa/confirm`
- POST `/auth/mfa/verify`
- POST `/auth/mfa/disable`

## 4. Users/RBAC

Users:
- GET `/users`
- POST `/users`
- GET `/users/{uuid}`
- PUT `/users/{uuid}`
- PATCH `/users/{uuid}/status`
- POST `/users/{uuid}/reset-password`
- GET `/users/{uuid}/sessions`
- DELETE `/users/{uuid}/sessions/{sessionUuid}`
- GET `/users/{uuid}/login-history`

Roles/Permissions:
- GET `/roles`
- POST `/roles`
- GET `/roles/{uuid}`
- PUT `/roles/{uuid}`
- PATCH `/roles/{uuid}/status`
- GET `/permissions`
- PUT `/roles/{uuid}/permissions`

## 5. Persons/Students

Persons:
- GET `/persons` (`face.enroll`): filters `keyword, person_type, page, per_page`; returns a privacy-minimised person summary with person type, nullable `academic_status` / `employment_status`, profile code, and active face-profile flag. Contact data and biometric data are excluded.
- POST `/persons`
- GET `/persons/{uuid}`
- PUT `/persons/{uuid}`

Students:
- GET `/students`
- POST `/students`
- GET `/students/{uuid}`
- PUT `/students/{uuid}`
- PATCH `/students/{uuid}/status`
- PATCH `/students/{uuid}/archive`
- POST `/students/{uuid}/photo`
- POST `/students/import`
- POST `/students/export`

Guardian links (Module 09, PPL-005):
- GET `/guardians` (`guardian.view`): filters `keyword, page, per_page`; searches only PARENT, TEACHER, and STAFF identities and returns safe identity fields.
- POST `/guardians` (`guardian.manage`): create a PARENT identity `{ first_name, last_name, person_code?, display_name?, gender? }`.
- GET `/guardians/{uuid}` (`guardian.view`): read one PARENT identity; other person types return 404.
- PUT `/guardians/{uuid}` (`guardian.manage`): update identity fields only; person type and lifecycle status cannot be changed here.
- GET `/students/{uuid}/guardians` (`guardian.view`): lists linked guardian identities and relation.
- POST `/students/{uuid}/guardians` (`guardian.manage`): `{ guardian_person_uuid, relation: FATHER|MOTHER|GUARDIAN|OTHER }`; accepts PARENT, TEACHER, or STAFF, rejects self-links and duplicate links.
- DELETE `/guardian-links/{uuid}` (`guardian.manage`): removes the relationship and writes an audit record.

Visitors and visits (Module 09, PPL-006; every endpoint is additionally gated by `feature:visitor` and returns `403 FEATURE_DISABLED` while the feature is off):
- GET `/visitors` (`visitor.view`): filters `keyword, person_type (VISITOR|CONTRACTOR), page, per_page`; POST `/visitors` (`visitor.manage`) creates a visitor/contractor identity and profile. `id_document_last4` must be exactly four alphanumeric characters; full document numbers are not accepted or stored.
- GET `/visitors/{uuid}` (`visitor.view`); PUT `/visitors/{uuid}` (`visitor.manage`) updates the person's names and visitor profile fields. No hard-delete endpoint exists.
- GET `/visits` (`visit.view`): optional filters `status, site_uuid, visitor_person_uuid, from, to, page, per_page`, constrained by the caller's site/zone scope.
- POST `/visits` (`visit.manage`): `{ visitor_person_uuid, host_person_uuid?, purpose, site_uuid, zone_uuid?, valid_from, valid_until }`; visitor must have a visitor/contractor profile, host must be staff/teacher, and the time window and site/zone relationship are validated.
- GET `/visits/{uuid}` (`visit.view`); PUT `/visits/{uuid}` (`visit.manage`) edits SCHEDULED visits only.
- POST `/visits/{uuid}/check-in|check-out|cancel` (`visit.manage`): legal transitions are SCHEDULED→CHECKED_IN→CHECKED_OUT or SCHEDULED→CANCELLED. SCHEDULED visits past `valid_until` are automatically marked EXPIRED by `visits:expire` each minute.

Student list filters:
- page
- per_page
- keyword
- department_uuid
- program_uuid
- classroom_uuid
- education_level
- year_level
- academic_status

Event and historical search person summaries include `name`, `person_type`,
`person_code`, `person_status`, and the applicable profile `academic_status`,
`employment_status`, and resolved display `status`. These are additive, read-only
fields; they contain no biometric or contact data.
- face_enrolled
- sort
- order

Teachers and staff (Module 09, PPL-003; additive, docs/12-module-09-people-visitor.md):
- GET `/staff` (`staff.view`): filters `keyword, employment_status, person_type (TEACHER|STAFF), department_uuid, sort, order`
- POST `/staff` (`staff.create`): `{ person: {first_name, last_name, display_name?, gender?, person_code?}, person_type?, staff_code, position?, department_uuid?, phone?, email? }`; status starts ACTIVE
- GET `/staff/{uuid}` (`staff.view`)
- PUT `/staff/{uuid}` (`staff.update`): names, position, department, phone, email; `staff_code`, `person_type` and status are rejected
- PATCH `/staff/{uuid}/status` (`staff.status.change`): `{ status: ACTIVE|LEAVE|SUSPENDED|RESIGNED|RETIRED, reason? }`
- GET `/staff/{uuid}/status-history` (`staff.view`)
- POST `/staff/import` (`staff.import`): CSV `file`, `conflict_mode` CREATE_ONLY|SKIP_EXISTING|UPDATE_EXISTING. Columns: required `staff_code, first_name, last_name`; optional `person_type, display_name, gender, person_code, position, department_code, phone, email, employment_status`
- GET `/staff/imports/{uuid}`, `/staff/imports/{uuid}/errors`, `/staff/imports/{uuid}/preview`; POST `/staff/imports/{uuid}/process` (`staff.import`). Staff import jobs are not reachable through the student import endpoints (404).

Staff error codes: `STAFF_CODE_ALREADY_EXISTS` (409), `STAFF_STATUS_NO_OP` (422), `DEPARTMENT_NOT_FOUND`, `DEPARTMENT_INACTIVE` (422).

## 6. Academic Master Data

- GET/POST `/departments`
- GET/PUT `/departments/{uuid}`
- GET/POST `/programs`
- GET/PUT `/programs/{uuid}`
- GET/POST `/classrooms`
- GET/PUT `/classrooms/{uuid}`

## 7. Imports

- GET `/imports/{uuid}`
- POST `/students/import`
- optional endpoints for mapping/preview according to implementation while preserving this contract concept

## 8. Face

- POST `/faces/enroll`
- POST `/faces/check-quality`
- POST `/faces/recognize`
- GET `/persons/{uuid}/faces`
- POST `/faces/{uuid}/revoke`
- POST `/faces/{uuid}/reenroll`

Internal:
- POST `/internal/v1/face/enroll`
- POST `/internal/v1/face/recognize`
- POST `/internal/v1/face/check-quality`
- POST `/internal/v1/face/liveness`

Recognition classification:
- MATCH
- REVIEW
- UNKNOWN

Raw embeddings must never be returned to frontend.

## 9. Cameras

Sites and zones (UIX-002, additive; previously CLI `locations:provision` only):
- GET `/sites` (`system.view`): sites with their zones and camera counts; filter `status`
- POST `/sites` (`system.manage`): `{ code, name, address?, timezone? (default Asia/Bangkok) }`
- PUT `/sites/{uuid}` (`system.manage`): `name, address, timezone, status`; `code` is rejected
- POST `/sites/{uuid}/zones` (`system.manage`): `{ code (unique per site), name, zone_type? }`
- PUT `/zones/{uuid}` (`system.manage`): `name, zone_type, status`; `code` and site are rejected
- Audit: `location.site_created|site_updated|zone_created|zone_updated`


- GET `/cameras`
- POST `/cameras`
- GET `/cameras/{uuid}`
- PUT `/cameras/{uuid}`
- PATCH `/cameras/{uuid}/status`
- POST `/cameras/{uuid}/test`
- POST `/cameras/{uuid}/snapshot`
- GET `/cameras/form-options` (add-camera form: scoped sites/zones + suggested camera code)
- POST `/cameras/preview` (one live frame from an unsaved stream; nothing persisted)

POST `/cameras`: `site_uuid`, `zone_uuid` and `camera_code` are optional. When omitted the
server uses the first active site/zone in the caller's scope (provisioning a default
`MAIN` site and `GENERAL` zone for a GLOBAL caller when none exist) and the next free
`CAM-####` code.
- POST `/cameras/{uuid}/start`
- POST `/cameras/{uuid}/stop`
- GET `/cameras/{uuid}/health`

## 10. AI Nodes

- GET `/ai-nodes`
- POST `/ai-nodes`
- GET `/ai-nodes/{uuid}`
- PUT `/ai-nodes/{uuid}`
- GET `/ai-nodes/{uuid}/health`
- POST `/ai-nodes/{uuid}/test`

Internal:
- POST `/internal/v1/ai-nodes/register`
- POST `/internal/v1/ai-nodes/heartbeat`
- GET `/internal/v1/ai-nodes/{uuid}/config`

## 11. Streams

Internal AI-node operations:
- GET `/internal/v1/streams`
- POST `/internal/v1/streams/{cameraUuid}/start`
- POST `/internal/v1/streams/{cameraUuid}/stop`
- POST `/internal/v1/streams/{cameraUuid}/restart`
- GET `/internal/v1/streams/{cameraUuid}/health`

## 12. Events

Internal ingestion:
- POST `/internal/v1/events/recognition`
- POST `/internal/v1/events/{eventUuid}/snapshot` (EVT-025)

Public/admin:
- GET `/events`
- GET `/events/{uuid}`
- POST `/events/{uuid}/review`
- POST `/events/{uuid}/notes`
- GET `/events/{uuid}/snapshot` (EVT-025)
- GET `/persons/{uuid}/events`

Event face image (EVT-025, [ACR-EVT-24](decisions/ACR-EVT-24-UNKNOWN-FACE-SNAPSHOT-ENROLLMENT.md), approved 2026-11-20):
- Event responses (`GET /events`, `GET /events/{uuid}`) carry only `snapshot_available` (boolean). They never
  carry the image, its storage key or `snapshot_path`.
- POST `/internal/v1/events/{eventUuid}/snapshot` — `ai_node.token`; `multipart/form-data` with one field
  `image` (JPEG). Only the node that delivered the event, only while the event camera is assigned to it, only
  for an event whose system decision is UNKNOWN, REVIEW or DENY, once per event, within
  `EVENT_SNAPSHOT_UPLOAD_WINDOW_SECONDS` (600) of `received_at`. Limits: `EVENT_SNAPSHOT_MAX_BYTES` (262144),
  each side 32..`EVENT_SNAPSHOT_MAX_DIMENSION` (640) px — a face crop, never a full frame or an embedding.
  Stored on the private `event_snapshots` disk and audited `EVENT_SNAPSHOT_STORED`. `201` returns
  `{event_uuid, snapshot_available: true}`. Errors: `404 EVENT_NOT_FOUND` (missing or another node's event),
  `422 CAMERA_NOT_ASSIGNED_TO_NODE`, `422 EVENT_SNAPSHOT_NOT_ELIGIBLE`, `422 EVENT_SNAPSHOT_WINDOW_EXPIRED`,
  `422 EVENT_SNAPSHOT_TOO_LARGE`, `422 EVENT_SNAPSHOT_INVALID_IMAGE`, `422 VALIDATION_ERROR`,
  `409 EVENT_SNAPSHOT_ALREADY_STORED`, `503 EVENT_SNAPSHOT_STORAGE_UNAVAILABLE`.
- GET `/events/{uuid}/snapshot` — needs `event.view` **and** (`camera.live` or `face.view`); an event outside the
  caller's site/zone scope answers `404`. Returns `{event_uuid, content_type: "image/jpeg", image_base64}` with
  `Cache-Control: no-store, private`. Every successful read is audited `EVENT_SNAPSHOT_VIEWED`. No crop, or the
  object is gone: `404 EVENT_SNAPSHOT_NOT_AVAILABLE`. Temporary viewing is implemented as this authenticated,
  per-request read instead of a pre-signed object-storage URL, so no reusable link exists and the object
  store is never exposed to browsers.
- Retention: `retention.snapshots_days` deletes the object and clears the reference; the event stays until
  `retention.events_days`, which also deletes any remaining object.

Filters:
- from
- to
- person_uuid
- student_code
- event_type
- decision
- site_uuid
- zone_uuid
- camera_uuid
- academic_status

## 13. Policy

- GET `/access-policies`
- POST `/access-policies`
- GET `/access-policies/{uuid}`
- PUT `/access-policies/{uuid}`
- PATCH `/access-policies/{uuid}/status`
- POST `/access-policies/test`

## 14. Dashboard

- GET `/dashboard/summary`
- GET `/dashboard/events-by-hour`
- GET `/dashboard/events-by-decision`
- GET `/dashboard/events-by-camera`
- GET `/dashboard/events-by-department`

## 15. Historical Search

- GET `/history/search`
- GET `/persons/{uuid}/timeline`

The timeline response retains its chronological `data` list. `meta.person` adds
the selected person's UUID, display name, type, code, and current profile status.

## 16. Reports

- GET `/reports/attendance` (`report.view`, EVT-023): daily time in / time out per person from ALLOW recognition events. Params `date` (YYYY-MM-DD, default today), `tz` (default Asia/Bangkok), `person_type`, `keyword`, `site_uuid`. Rows: `person {uuid, name, person_type, code, status, academic_status, employment_status}, time_in, time_in_camera, time_out (null if seen once), time_out_camera, sightings`
- GET `/reports/daily`
- GET `/reports/weekly`
- GET `/reports/monthly`
- GET `/reports/yearly`
- GET `/reports/custom`
- GET `/reports/review`
- GET `/reports/unknown`
- GET `/reports/camera`
- GET `/reports/ai-node`

Exports:
- POST `/reports/exports`
- GET `/reports/exports/{uuid}`

## 17. Notifications

Realtime push (EVT-023): every new notification is also broadcast on the private channel `user.{userUuid}` as `notification.created` (only that user may subscribe). The inbox below stays the source of truth.

- GET `/notifications`
- PATCH `/notifications/{uuid}/read`
- POST `/notifications/read-all`

## 18. System

- GET `/settings`
- GET `/settings/{group}`
- PUT `/settings/{group}`
- GET `/features`
- GET `/system/health`
- GET `/audit-logs`
- GET `/audit-logs/{uuid}`

Visitor biometric retention (Module 09, PPL-009) is configurable as `visitor.face_retention_days` under the `RETENTION`
settings group (positive integer; default 30). The daily `visitors:retain-faces` task revokes and purges only visitors
whose latest visit ended at least that many days ago and who have no scheduled or checked-in visit.

Commercial licensing (COM-02, additive; see `docs/commercial/LICENSE_MODEL.md`):
- GET `/system/license` (`system.view`): installation ID + evaluated license state; never returns the signed document
- POST `/system/license` (`system.manage`): body `{ "license": "<signed envelope>" }`; 201 with the new state
- POST `/system/license/revocations` (`system.manage`): body `{ "revocation_list": "<signed envelope>" }`

License error codes: `LICENSE_MALFORMED`, `LICENSE_UNTRUSTED_KEY`, `LICENSE_SIGNATURE_INVALID`, `LICENSE_WRONG_TYPE`,
`LICENSE_INSTALLATION_MISMATCH`, `LICENSE_REVOKED`, `LICENSE_EXPIRED`, `LICENSE_OLDER_THAN_ACTIVE`,
`LICENSE_CRYPTO_UNAVAILABLE`, `REVOCATION_LIST_STALE` (422); enforcement refusals `LICENSE_REQUIRED`,
`LICENSE_LIMIT_REACHED`, `LICENSE_FEATURE_NOT_ENTITLED` (409, only when `LICENSE_ENFORCEMENT=enforce`).

## 19. Pagination

Use:
- `page`
- `per_page`

Default 20, maximum 100 unless endpoint specifies otherwise.

## 20. Sorting

Use:
- `sort`
- `order=asc|desc`

Backend must whitelist sortable fields.

## 21. Date Format

Use ISO 8601 for API input/output.

Database stores UTC.

Frontend may display Buddhist Era but never sends BE year as the canonical API timestamp.

## 22. Stable Error Codes

Examples:
- AUTH_INVALID_CREDENTIALS
- ACCOUNT_LOCKED
- PERMISSION_DENIED
- PERSON_NOT_FOUND
- STUDENT_NOT_FOUND
- CAMERA_OFFLINE
- AI_NODE_OFFLINE
- FACE_LOW_QUALITY
- FACE_NO_MATCH
- LIVENESS_FAILED
- EVENT_DUPLICATED
- EVENT_SNAPSHOT_NOT_AVAILABLE
- POLICY_NOT_FOUND
- POLICY_EVALUATION_ERROR
- STUDENT_PROFILE_MISSING

## 23. Request ID

Accept or generate `X-Request-ID`.

Propagate it to logs/internal calls/audit where relevant.

## 24. Idempotency

Recognition event ingestion uses unique `event_uuid`.

Retrying the same event must not duplicate Event or Decision records.
