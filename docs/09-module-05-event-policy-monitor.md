# Module 05 — Event Engine + Access Policy + Realtime Monitor

## Goal
Convert recognition results into durable events, policy decisions, realtime operator workflows, and review history.

## Required Separation
Event != Decision.

Example:
- Event: FACE_RECOGNIZED
- Decision: REVIEW
- Reason: STUDENT_SUSPENDED

## Default Policy Concept
- STUDENT + ACTIVE -> ALLOW
- STUDENT + LEAVE -> REVIEW
- STUDENT + SUSPENDED -> REVIEW
- STUDENT + WITHDRAWN -> REVIEW
- STUDENT + GRADUATED -> REVIEW
- STUDENT + EXPELLED -> DENY
- STAFF + ACTIVE -> ALLOW
- Other STAFF statuses -> REVIEW
- Other recognized person types (including parents and visitors) -> REVIEW and notify
- UNKNOWN -> UNKNOWN
- LIVENESS_FAILED -> REVIEW

Policies are configuration-driven.

Owner amendment (2026-11-17): only an ACTIVE student and ACTIVE staff member are routine identities. A visitor's active
visit or a parent's link to an active student does not suppress monitoring alerts; those identities require operator
review. A non-ACTIVE `persons.status` record also overrides an otherwise ACTIVE student/staff profile and requires
review. This amendment is recorded in `docs/decisions/OWNER-DECISIONS-2026-11-16.md`.

## Processing
`recognition -> validate/idempotency -> resolve person -> resolve student -> persist event -> evaluate policy -> persist decision -> commit -> broadcast -> notify`

## Deduplication
- event_uuid unique
- semantic cooldown camera+person
- unknown cooldown camera+track

## Manual Review
Officer review adds a new decision. It never overwrites system decision.

## Realtime
Use Laravel Reverb/private channels.
Channel authorization must enforce scope.

## UI
- Live Monitor
- Event Drawer
- Review Queue
- Unknown Persons
- Event History
- Person Timeline

## Permissions
- monitor.view
- event.view
- event.review
- event.note
- policy.view
- policy.manage

## Acceptance
See Module 05 acceptance criteria.
