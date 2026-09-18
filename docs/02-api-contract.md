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
- GET `/persons`
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
- face_enrolled
- sort
- order

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

- GET `/cameras`
- POST `/cameras`
- GET `/cameras/{uuid}`
- PUT `/cameras/{uuid}`
- PATCH `/cameras/{uuid}/status`
- POST `/cameras/{uuid}/test`
- POST `/cameras/{uuid}/snapshot`
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

## 16. Reports

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
