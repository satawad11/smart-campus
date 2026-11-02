# ARCHITECTURE_CHANGE_REQUEST — ACR-INT-01 RMS student/staff sync

**Status:** PROPOSED (needs project-owner approval and a mapping confirmed on real RMS data)
· **Raised:** 2026-11-02 · **Requested by:** project owner (chat, 2026-11-02)

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  Students and staff are entered by hand or by CSV import. The college's RMS already holds
  the current student list (with class groups) and the active staff list, and offers a
  read-only API (RMS API Center v1).

Current specification:
  Person is the master identity; StudentProfile links a Person to department, program,
  classroom, education level and year (PROJECT_RULES §4). Students arrive through
  StudentImportService (CSV, validated, audited). No external source of truth is defined.

Proposed change:
  1. A scheduled, read-only sync (daily, and on demand by a user with a new permission
     student.sync) that pulls RMS groups, students and staff and upserts them through the
     same validation and audit path as the CSV import.
  2. New table rms_links (source, external_id, person_id or classroom_id, last_seen_at,
     last_payload_hash): RMS ids (student code, s_... staff id, group id) mapped to our
     records. No existing table or API contract changes.
  3. Staff become Person rows of type TEACHER or STAFF (by position); they are not Users.
  4. Records that disappear from RMS are reported for review; never deleted or re-statused
     automatically. Manual edits to synced fields are overwritten only for fields RMS owns.
  5. No national ID, birth date, address, phone or photo is requested or stored.

Reason:
  One source of truth for who is a current student or staff member; fewer manual errors.

Impact:
  Additive: one table, one service, one command, one permission, one schedule entry,
  a sync report in the UI. Outbound HTTPS to RMS from the platform server only.

Migration impact:
  Existing students are matched by student_code; no data is removed.

Alternatives:
  A. Keep CSV import only (export from RMS by hand each term).
  B. One-off import from RMS, no ongoing sync.
```

## Decisions needed

| Question | Proposal |
|---|---|
| Approve the sync (Proposed change 1-5) | Yes, after `rms:preview` on real data |
| RMS owns which fields | name, surname, prefix, classroom, department, program, level, year |
| Staff in scope | All active staff as Person; no login accounts created |
| Departments/programs missing here | Create automatically, flagged in the sync report |
