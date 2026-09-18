# PROJECT_RULES.md
# AI Smart Campus Security & Access Platform

## 1. Purpose

This repository is developed using specification-driven, contract-driven, test-gated development.

All human developers and AI coding agents must follow the approved project specifications. No agent may redesign the system simply because it prefers a different implementation.

## 2. Source of Truth Order

Before modifying code, read in this order:

1. `PROJECT_RULES.md`
2. `docs/00-system-blueprint.md`
3. `docs/01-database-blueprint.md`
4. `docs/02-api-contract.md`
5. `docs/03-ui-ux-blueprint.md`
6. `docs/04-development-blueprint.md`
7. The current module specification
8. `ACCEPTANCE_CRITERIA.md`
9. `TASKS.md`
10. Existing source code and tests

If specifications conflict, do not silently choose one. Create an `ARCHITECTURE_CHANGE_REQUEST` and stop the affected work.

## 3. Locked Technology Stack

Frontend:
- Vue 3
- Vite
- Tailwind CSS
- Pinia
- Vue Router
- Axios

Backend:
- Laravel
- REST API
- Laravel Reverb / WebSocket
- Queue
- Scheduler

Data:
- PostgreSQL
- pgvector
- Redis
- S3-compatible object storage

AI:
- Python
- FastAPI
- OpenCV
- ONNX Runtime
- CUDA/TensorRT may be introduced later after benchmark

Infrastructure:
- Docker / Docker Compose
- Nginx

Do not replace a locked technology without explicit project-owner approval.

## 4. Locked Domain Model

- `Person` = master identity.
- `StudentProfile` = academic profile linked to a Person.
- `User` = account that can log in to the platform.
- `FaceProfile` = biometric profile linked to `person_id`.
- `Camera` = sensor.
- `AINode` = perception-processing node.
- `AccessEvent` = something that happened.
- `AccessDecision` = a decision made by policy/system/officer.
- `AccessPolicy` = business rule.
- `AuditLog` = who changed what and when.

A Student is not automatically a User.

Face data must reference `person_id`, not `student_id`.

## 5. Locked AI Boundary

AI MAY:
- detect faces;
- evaluate face quality;
- perform liveness/PAD;
- align faces;
- produce embeddings;
- match embeddings;
- return recognition candidates/scores.

AI MUST NOT:
- change student academic status;
- create or modify users or permissions;
- decide disciplinary outcomes;
- directly open a door or actuate a relay;
- bypass backend policy checks.

Mandatory rule:

`AI identifies -> Backend decides -> Policy controls -> Controller acts -> Human reviews exceptions -> Audit records changes.`

## 6. API Rules

Public/admin API prefix:
`/api/v1`

Internal service API prefix:
`/internal/v1`

Future device API prefix:
`/device/v1`

Public APIs use UUIDs as external identifiers. Internal BIGINT IDs remain implementation details.

Standard successful response:
```json
{
  "success": true,
  "message": "Success",
  "data": {},
  "meta": null
}
```

Standard error response:
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

Do not silently rename contract fields.

## 7. Historical Integrity

The following are historical records and must not be silently overwritten to rewrite history:
- access events;
- access decisions;
- student status histories;
- audit logs;
- login logs where applicable.

Manual review adds a new decision. It does not overwrite the system decision.

## 8. RBAC Rules

Never scatter role checks such as:
```php
if ($user->role === 'admin')
```

Use permission codes and scope enforcement.

Required scope model:
- GLOBAL
- SITE
- ZONE

Frontend menu hiding is not authorization. Backend authorization is mandatory.

## 9. Security Rules

Never commit:
- passwords;
- API keys;
- private keys;
- MFA secrets;
- camera credentials;
- production `.env` files.

Never expose through normal frontend APIs:
- password hashes;
- raw face embeddings;
- MFA secrets;
- camera passwords;
- service credentials.

Sensitive administrative and biometric operations must be audited.

## 10. Biometric Rules

Face embeddings are sensitive biometric data.

- Never expose raw vectors to the browser.
- Face samples must not use a public bucket.
- Use authorization or signed URLs where required.
- Store model name/version with profiles and events.
- Similarity is not automatically a calibrated probability.
- Recognition classifications are `MATCH`, `REVIEW`, or `UNKNOWN`.

## 11. Realtime Rules

PostgreSQL is authoritative.

Persist durable state before broadcasting realtime messages.

WebSocket disconnect/reconnect must support missed-event recovery.

## 12. Reporting Rules

Authoritative totals come from backend report services/APIs.

Do not let an LLM, Vue component, or client-side code independently become the authoritative counter.

Large exports use:
`request -> queue -> generate -> object storage -> notification`.

## 13. Feature Flags

Initial state:

```text
FEATURE_FACE=true
FEATURE_LIVENESS=true
FEATURE_MONITOR=true
FEATURE_REPORT=true

FEATURE_DOOR=false
FEATURE_VISITOR=false
FEATURE_VEHICLE=false
FEATURE_ALPR=false
FEATURE_AI_ASSISTANT=false
```

Do not expose future modules early merely because partial code exists.

## 14. Coding Rules

Prefer:
`Controller -> Service -> Repository/Domain`

Keep controllers thin.

Use Form Requests, DTOs, Resources, middleware/policies, services, and domain events where they make responsibilities clearer.

Do not add unnecessary abstraction.

## 15. Migration Rules

- Never modify a migration already applied to a deployed environment to change production schema.
- Create a new migration.
- Use approved table/field names.
- Avoid destructive cascade deletes for records referenced by history.

## 16. Test Rules

No task is complete without relevant automated tests.

Minimum categories:
- happy path;
- validation;
- authorization;
- permission;
- scope;
- not found;
- conflict;
- failure handling.

Agents must not weaken, delete, or rewrite acceptance/security/architecture tests merely to make a task pass.

If an approved test appears incorrect, report it and wait for approval.

## 17. Architecture Change Procedure

If a task appears to require changing architecture, output:

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
Current specification:
Proposed change:
Reason:
Impact:
Migration impact:
Alternatives:
```

Do not implement the architecture-affecting change until approved.

## 18. No-Guessing Rule

When something is unclear:
1. Search repository specs.
2. Search contracts and tests.
3. Follow established patterns.
4. Choose only a minimum reversible local implementation when safe.
5. Record assumptions in the implementation report.

Never invent major product requirements.

## 19. Required Task Workflow

Before coding:
1. Read required specs.
2. Inspect existing implementation.
3. Inspect relevant tests.
4. Define exact task scope.
5. Identify database/API/security impact.

During coding:
- modify only the bounded task;
- avoid unrelated refactors;
- preserve public contracts.

After coding:
1. format;
2. lint;
3. run migrations when relevant;
4. run unit tests;
5. run feature/API tests;
6. run relevant integration tests;
7. inspect git diff;
8. verify permissions/scopes;
9. verify API contract;
10. produce implementation report.

## 20. Required Implementation Report

Every task ends with:

```text
IMPLEMENTATION REPORT

Task:
Status: COMPLETE / PARTIAL / BLOCKED

Implemented:
- ...

Files changed:
- ...

Database changes:
- ...

API changes:
- ...

Permissions:
- ...

Tests added:
- ...

Tests executed:
- ...

Remaining issues:
- ...

Specification deviations:
NONE
```

Never report COMPLETE while required tests fail.

## 21. Development Sequence

1. Module 01 — Authentication + User + RBAC
2. Module 02 — Person + Student + Academic Structure
3. Module 03 — Face Enrollment + Face AI Foundation
4. Module 04 — Camera + AI Node + RTSP Processing
5. Module 05 — Event Engine + Policy + Realtime Monitor
6. Module 06 — Historical Search + Dashboard + Reporting
7. Module 07 — Security Hardening + Pilot Readiness

Future:
8. Door Access Control
9. Visitor / Parent
10. Vehicle + ALPR
11. AI Assistant + Advanced Analytics

Do not skip into later modules without project-owner approval.
