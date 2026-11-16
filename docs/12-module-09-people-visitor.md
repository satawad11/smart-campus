# Module 09 — People: Staff, Visitors, Parents

Status: **ACCEPTED by the owner 2026-11-16** ("Accept, start building"; OWNER-DECISIONS-2026-11-16 U2/U3). The owner
accepted without answering the open questions, so the defaults below apply until the owner changes them.

## Goal
Manage every non-student identity the campus needs to recognise: teachers and staff, parents/guardians, visitors and
contractors. Each group is managed on its own screen, but all of them are `Person` records, so each can have a face
profile and appear in monitoring, events and reports exactly like a student.

## Core rules
- `Person` stays the master identity (`docs/00` §5). `person_type` already allows
  `TEACHER, STAFF, PARENT, VISITOR, CONTRACTOR, ALUMNI, OTHER`; no new types.
- Group-specific data lives in a profile table linked one-to-one to `persons`, like `student_profiles`.
- Face profiles keep linking to `person_id`. Enrollment, re-enrollment, revoke and duplicate review are unchanged.
- "AI identifies -> Backend decides": the node still only reports a person; the backend resolves the group and status
  and evaluates policy.
- A person has one `person_type`. A teacher who is also a parent is recorded as `TEACHER` with a guardian link.

## Data (new tables)

### staff_profiles (teachers and staff)
| Column | Notes |
|---|---|
| person_id | FK persons, unique |
| staff_code | unique |
| position | free text, e.g. ครู, เจ้าหน้าที่ธุรการ |
| department_id | nullable FK departments (reuses Module 02 academic departments) |
| employment_status | `ACTIVE, LEAVE, SUSPENDED, RESIGNED, RETIRED` (dedicated status-change workflow + history, like students) |
| phone, email | nullable |

### guardian_links (parents)
| Column | Notes |
|---|---|
| guardian_person_id | FK persons (`PARENT`, or any person who is also a guardian) |
| student_person_id | FK persons with a student profile |
| relation | `FATHER, MOTHER, GUARDIAN, OTHER` |
| unique | (guardian_person_id, student_person_id) |

### visitor_profiles (visitors and contractors)
| Column | Notes |
|---|---|
| person_id | FK persons, unique |
| organization | nullable |
| phone | nullable |
| id_document_type | `NATIONAL_ID, PASSPORT, DRIVING_LICENCE, OTHER`, nullable |
| id_document_last4 | **last 4 characters only** — the full ID number is never stored (PDPA minimisation) |

### visits
| Column | Notes |
|---|---|
| uuid | public id |
| visitor_person_id | FK persons |
| host_person_id | nullable FK persons (the staff member visited) |
| purpose | text |
| site_id, zone_id | where the visit is valid |
| valid_from, valid_until | time window |
| status | `SCHEDULED, CHECKED_IN, CHECKED_OUT, EXPIRED, CANCELLED` |
| checked_in_at, checked_out_at, created_by | audit fields |

`visitor_passes` (physical cards/QR) stay reserved for the Door module.

## Decisions and policy
`AccessContextResolver` resolves the subject beyond students:

| Person | subject_type | subject_status |
|---|---|---|
| has student profile | `STUDENT` | academic status (unchanged) |
| has staff profile | `STAFF` | employment status |
| `VISITOR` / `CONTRACTOR` | `VISITOR` | `VISIT_ACTIVE` if a visit is `CHECKED_IN` (or `SCHEDULED` and inside its window) at that site, else `NO_ACTIVE_VISIT` |
| `PARENT` | `PARENT` | `ACTIVE` while linked to at least one active student, else `NO_ACTIVE_STUDENT` |

New seeded policies: `STAFF_ACTIVE` → ALLOW; other staff statuses → REVIEW; `VISIT_ACTIVE` → ALLOW;
`NO_ACTIVE_VISIT` → REVIEW (notifies like UNKNOWN/DENY); `PARENT` `ACTIVE` → ALLOW, otherwise REVIEW.
Policies stay editable like today's student policies.

## Face data of visitors (PDPA)
- Consent is required at enrollment, as for students.
- A visitor's face profile is **revoked automatically N days after their last visit ends** (system setting
  `visitor.face_retention_days`, default 30), with the stored vectors deleted the same way as a normal revoke. Audited.
- Staff and parent faces follow the same rules as student faces.

## Permissions
`staff.view, staff.create, staff.update, staff.status_change` · `guardian.view, guardian.manage` ·
`visitor.view, visitor.manage, visit.view, visit.manage`.
Default roles: SUPER_ADMIN/SYSTEM_ADMIN all; SECURITY_OFFICER `visitor.*`, `visit.*`, `staff.view`, `guardian.view`;
DISCIPLINE_OFFICER `staff.view`, `guardian.view`, `visit.view`.

## API (additive, `/api/v1`)
- `GET/POST /staff`, `GET/PUT /staff/{uuid}`, `POST /staff/{uuid}/status`, `POST /staff/import` (csv/xlsx, same
  wizard as students)
- `GET /students/{uuid}/guardians`, `POST /students/{uuid}/guardians`, `DELETE /guardian-links/{uuid}`,
  `GET/POST /guardians`, `GET/PUT /guardians/{uuid}`
- `GET/POST /visitors`, `GET/PUT /visitors/{uuid}`
- `GET/POST /visits`, `GET/PUT /visits/{uuid}`, `POST /visits/{uuid}/check-in|check-out|cancel`
- `GET /persons?keyword=&person_type=`: one person search for face enrollment and pickers (returns the same safe
  summary as today)

`/visitors` and `/visits` answer `403 FEATURE_DISABLED` while `FEATURE_VISITOR_ENABLED=false`. Staff and guardians are
not behind a future flag.

## UI
- Sidebar group "บุคคลและสิทธิ์": **จัดการบุคลากร** (staff list, filters, detail, status history, create/edit, import),
  **ผู้ปกครอง** (list + link to students, also a tab on the student detail page), **ผู้มาติดต่อ** (visitors +
  today's visits, check-in/out at the gate).
- Face enrollment: the person picker searches every person type, with a type filter.
- Events, history search and reports show the person's group and status, not only students.

## Audit
Staff create/update/status change/import · guardian link add/remove · visitor create/update · visit
create/check-in/check-out/cancel/expire · automatic visitor face revoke.

## Tasks
See `TASKS.md` → Module 09 (PPL-001 … PPL-012).

## Owner answers (defaults, accepted 2026-11-16)
1. Staff data: **manual entry and CSV/XLSX import**. RMS staff sync is not assumed; it becomes a separate task if RMS
   exposes staff.
2. Visitor face retention: **30 days** after the last visit ends (`visitor.face_retention_days`, changeable).
3. A visitor without a face profile **can be checked in manually** at the gate.
