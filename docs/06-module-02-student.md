# Module 02 — Person + Student + Academic Structure

## Goal
Create trusted identity/academic data for later biometric recognition.

## Core Rules
- Person is the master identity.
- StudentProfile is an academic profile.
- Student status changes use dedicated workflow/history.
- student_code is unique.
- face profiles later reference person_id.

## Academic Entities
- Department
- Program
- Classroom
- Education level
- Academic status

Education level:
- VOC_CERT
- HIGH_VOC_CERT
- SHORT_COURSE
- OTHER

Academic status:
- ACTIVE
- LEAVE
- SUSPENDED
- WITHDRAWN
- GRADUATED
- EXPELLED
- UNKNOWN

## Student Import
Must support:
- xlsx/csv
- upload
- column mapping
- validation
- preview
- queued import
- row-level errors
- duplicate/update policy
- identity mismatch review

## UI
- student list
- filters
- detail
- status history
- create/edit
- import wizard

## Audit
- student create/update
- status change
- archive
- photo update
- import
- academic master changes

## Person Record Status (STD-002)
persons.status is the identity RECORD lifecycle, distinct from student academic status:
- ACTIVE — normal operational identity record
- INACTIVE — retained but no longer operationally active
- ARCHIVED — retained for historical/reference purposes

Default: ACTIVE. Enforced by a database CHECK constraint. Student academic
statuses (ACTIVE/LEAVE/SUSPENDED/WITHDRAWN/GRADUATED/EXPELLED/UNKNOWN) are NOT
valid person statuses.

## User–Person Cardinality (STD-002)
Optional one-to-one. users.person_id is nullable, UNIQUE when non-null, and FK
ON DELETE SET NULL. A Person has zero or one User; a User belongs to zero or one
Person. Relaxing this requires an explicit architecture change.

## Acceptance
See Module 02 acceptance criteria.
