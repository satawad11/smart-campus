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

Public/admin:
- GET `/events`
- GET `/events/{uuid}`
- POST `/events/{uuid}/review`
- POST `/events/{uuid}/notes`
- GET `/persons/{uuid}/events`

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
- POLICY_NOT_FOUND
- POLICY_EVALUATION_ERROR
- STUDENT_PROFILE_MISSING

## 23. Request ID

Accept or generate `X-Request-ID`.

Propagate it to logs/internal calls/audit where relevant.

## 24. Idempotency

Recognition event ingestion uses unique `event_uuid`.

Retrying the same event must not duplicate Event or Decision records.

## 25. Vehicles (ACR-VEH-01, VEH-004)

Behind `feature:vehicle` (off by default; `FEATURE_VEHICLE_ENABLED=false` forces it off). The feature
gate is checked before permissions, so a disabled module answers `403 FEATURE_DISABLED`.

```
GET  /api/v1/vehicles                               vehicle.view
POST /api/v1/vehicles                               vehicle.manage
GET  /api/v1/vehicles/{vehicle_uuid}                vehicle.view   (includes links, newest first)
PUT  /api/v1/vehicles/{vehicle_uuid}                vehicle.manage
POST /api/v1/vehicles/{vehicle_uuid}/links          vehicle.manage (link a person)
POST /api/v1/vehicles/{vehicle_uuid}/links/{link_uuid}/end   vehicle.manage (end a link)
```

List filters: `plate` (exact, normalised), `province` (code or Thai name), `keyword` (plate, brand,
model, colour), `status`, `vehicle_type`, `person_uuid` (current links only), `sort`, `order`, paging.

Create body: `plate_number`, `plate_province` (ISO 3166-2:TH code or Thai name), optional
`vehicle_type`, `brand`, `model`, `color`, `status`, `notes`. Plates are normalised with the rules in
`contracts/thai-plates.json`. The plate and province cannot change after registration.

Update body: `vehicle_type`, `brand`, `model`, `color`, `status` (`ACTIVE|INACTIVE|BLOCKED`), `notes`.

Link body: `person_uuid`, `relation` (`OWNER|DRIVER|AUTHORIZED`), optional `effective_from`, `reason`.
End body: optional `effective_to`, `reason`. Links are never deleted.

No DELETE routes. Every create, update, link and end is written to the audit log (module `vehicle`).

Error codes: `VEHICLE_PLATE_EXISTS` (409), `VEHICLE_PROVINCE_INVALID` (422), `VEHICLE_LINK_EXISTS` (409),
`VEHICLE_LINK_ENDED` (409), `VEHICLE_LINK_END_BEFORE_START` (422), `PERSON_NOT_FOUND` (422).

### 25.1 Per-camera plate reading (VEH-005)

`plate_recognition_enabled` (boolean, default `false`) is accepted by `POST /api/v1/cameras` and
`PUT /api/v1/cameras/{camera_uuid}` and returned by the camera resource. Turning it **on** while the
ALPR module is off (`feature.alpr` false or `FEATURE_ALPR_ENABLED=false`) is rejected with
`422 ALPR_DISABLED`; turning it off is always allowed. Changes are audited with the camera update.

`GET /internal/v1/ai-nodes/{node_uuid}/config` sends each camera's **effective** value: the camera
switch AND `feature.alpr`. Nodes treat anything other than a literal `true` as off.

### 25.2 Plate event types (VEH-006)

Seeded by `EventTypeSeeder` (category `PLATE`), also on upgrades:

| Code | Meaning | Severity |
|---|---|---|
| `PLATE_RECOGNIZED` | Plate read clearly and it matches a registered vehicle | INFO |
| `PLATE_UNKNOWN` | Plate read clearly but no registered vehicle has it | WARNING |
| `PLATE_LOW_CONFIDENCE` | Plate read, but OCR confidence is below the threshold chosen in VEH-007 | INFO |

The backend picks the type when it ingests a plate result (VEH-010); nodes never set it. Plate events
are records only: no default access policy uses them until the Smart Gate tasks (GATE-006).

### 25.3 Plate-read ingestion (VEH-010)

`POST /internal/v1/events/plate` (node bearer token). Body: `event_uuid`, `camera_uuid`,
`ai_node_uuid`, `track_id`, `occurred_at`, `plate_text` (raw OCR), optional `province_text` (raw OCR),
`ocr_score` (0–1), optional `detection_score`, `frames_voted`, `model_code`, `model_version`, optional
`reasons[]`. The node may not send `event_type`, `vehicle_*`, `person_*`, `decision` or internal ids.

The backend normalises the text (`contracts/thai-plates.json`), resolves the province, and picks the
type: `PLATE_LOW_CONFIDENCE` when the text is not a valid Thai plate or `ocr_score` is below
`ALPR_OCR_MIN_CONFIDENCE` (provisional 0.90 until VEH-007 is approved); otherwise `PLATE_RECOGNIZED`
when a registered vehicle has the plate (province must match; with no readable province only a
plate held by exactly one vehicle matches), else `PLATE_UNKNOWN`.

Plate events have no person and **never get an access decision**. They are broadcast on the site
channel as `plate.read`. Rejections: `422 ALPR_DISABLED`, `422 PLATE_RECOGNITION_DISABLED` (camera
switch off), `AI_NODE_IDENTITY_MISMATCH`, `CAMERA_NOT_FOUND`, `CAMERA_NOT_ASSIGNED_TO_NODE`,
`409 EVENT_ALREADY_EXISTS`. A retried `event_uuid` answers 200; the same readable plate at the same
camera within `ALPR_PLATE_COOLDOWN_SECONDS` (30) is one pass and answers 200 with the first event.

### 25.4 Plate events in events, history and reports (VEH-012)

- `GET /api/v1/events` and `GET /api/v1/history/search` accept `plate` (normalised like stored plates;
  matches plate events only; unreadable input returns nothing).
- Event resources carry `plate` on plate events: `plate_number`, `plate_display`, `plate_province`,
  `plate_province_name`, `raw_text`, `ocr_score`, `vehicle_uuid`, `vehicle_status`; `null` otherwise.
- Range reports (`/reports/daily|weekly|monthly|yearly|custom`) add `plates`: `recognized`,
  `unknown`, `low_confidence`, `blocked_vehicle_reads`. These are counted from events and are not part
  of `totals` (plate events have no decision).
- `POST /api/v1/events/{uuid}/review` on a plate event answers `422 PLATE_EVENT_NOT_REVIEWABLE`.
- Realtime: plate reads arrive on the site channel as `plate.read` (§25.3), separate from
  `event.decided`.

## 26. Smart Gates (ACR-VEH-01 Phase 5, GATE-002)

Behind `feature:vehicle` (owner decision 2026-11-17), checked before permissions, so a disabled module
answers `403 FEATURE_DISABLED`. Permissions `gate.view` / `gate.manage` (SUPER_ADMIN and SYSTEM_ADMIN
manage, SECURITY_OFFICER views). Configuration only: no endpoint here opens a barrier (decision D5).

```
GET  /api/v1/gates                         gate.view   (filters: keyword, site_uuid, zone_uuid, status, paging)
POST /api/v1/gates                         gate.manage
GET  /api/v1/gates/form-options            gate.manage (sites -> zones and cameras; each camera has gate_uuid/gate_name if already used)
GET  /api/v1/gates/{gate_uuid}             gate.view   (includes cameras)
PUT  /api/v1/gates/{gate_uuid}             gate.manage
PUT  /api/v1/gates/{gate_uuid}/cameras     gate.manage (replaces the whole camera set)
```

Create body: `site_uuid`, `zone_uuid`, `code` (`[A-Za-z0-9_-]`, max 64, unique per site), `name`,
optional `direction` (`IN|OUT|BOTH`, default `BOTH`), `correlation_window_seconds` (1-60, default 10),
`status` (`ACTIVE|INACTIVE`, default `INACTIVE`), `notes`, `cameras[]` (`camera_uuid`, `role`
`FACE|PLATE|BOTH`). `mode` is not accepted: every gate is `FACE_PRIMARY` (decision D2).

Update body: `name`, `zone_uuid` (same site), `direction`, `correlation_window_seconds`, `status`,
`notes`. Site, code and mode cannot change; cameras change only through `PUT .../cameras`
(body `cameras: [{camera_uuid, role}]`, may be empty).

Rules: an `ACTIVE` gate needs at least one camera whose role reads faces (`FACE` or `BOTH`); a camera
serves at most one gate and must be in the gate's site. The gate resource lists each camera's `role`,
`status`, `face_recognition_enabled` and `plate_recognition_enabled` so the UI can warn when a role does
not match what the camera is set to read. No DELETE: a gate is deactivated.

Audit (module `gate`): `GATE_CREATED`, `GATE_UPDATED`, `GATE_CAMERAS_CHANGED` (only when the set changes).

Error codes: `GATE_CODE_EXISTS` (409), `GATE_CAMERA_IN_USE` (409), `GATE_NEEDS_FACE_CAMERA` (422),
`GATE_ZONE_SITE_MISMATCH` (422), `GATE_CAMERA_SITE_MISMATCH` (422), `GATE_CAMERA_DUPLICATE` (422),
`SITE_NOT_FOUND` (422), `ZONE_NOT_FOUND` (422), `CAMERA_NOT_FOUND` (422).
