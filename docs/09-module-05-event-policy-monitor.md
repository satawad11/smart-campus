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

## Smart Gate Policies (GATE-006, ACR-VEH-01 decision D2)
Seeded for gate mode `FACE_PRIMARY`; they apply to gate passes only, never to an ordinary face event.
- Vehicle BLOCKED -> DENY (`GATE_VEHICLE_BLOCKED`, priority 1), whoever drives it
- Plate only, no face -> REVIEW (`GATE_PLATE_ONLY`)
- Face unknown -> REVIEW (`GATE_FACE_UNKNOWN`); liveness failed -> REVIEW (`GATE_LIVENESS_FAILED`)
- Face recognised -> the person's own policies above decide, whatever the plate says. An entitled person is
  allowed; the pass's match_result (MATCHED / MISMATCH / UNKNOWN / FACE_ONLY) is the note. No gate policy allows.
- Disabled templates for "configurable to REVIEW": `Gate Vehicle Mismatch -> Review` and
  `Gate Plate Unknown -> Review` (priority 150, between the personal REVIEW/DENY rules at 100 and ALLOW at 200, so
  enabling one only makes decisions stricter). Enable as is for every FACE_PRIMARY gate, or copy with `gate_id` set
  for one gate. There is no policy editing screen yet, so this is a database change by an administrator.

Existing installs receive these rows from migration `2026_11_19_900005_seed_face_primary_gate_policies` (inserted
only when missing); fresh installs from `AccessPolicySeeder`. Writing gate decisions is GATE-007.

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
