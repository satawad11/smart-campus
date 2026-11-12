# RMS API Center v1 integration (student and staff source)

Source: the college's RMS (`https://rms.dmtc.ac.th/api_center.php`), vendor
document "RMS API Center v1" (2026). Read-only JSON over HTTPS.

## Status

| Stage | What | State |
|---|---|---|
| 1 | Read-only client + `php artisan rms:preview` (counts and field values to map; writes nothing, prints no personal data) | DONE, tested with fixtures |
| — | Live call from the platform server | WORKS (2026-11-03, dev server IP allowed); findings below |
| 2 | Sync into Person / StudentProfile (+ staff as Person TEACHER/STAFF): `rms:sync`, UI page, daily schedule | IMPLEMENTED (ACR-INT-01 approved 2026-11-03); daily schedule off until a dry run is reviewed |

## Access rules (from the vendor document)

- GET only, `v=1`, key `rmsk_...` in `Authorization: Bearer` only (never in the URL), HTTPS always.
- Calls only from the allowed server IP(s). 60 calls/min per key; 429 carries `Retry-After`.
  10 wrong keys in 15 minutes block the caller for 15 minutes.
- Cache and refresh at most hourly or daily; never call RMS per page view or from the browser.
- Student data are personal data of mostly minors: use only what the data-sharing agreement allows.
- The key is set from the web app (ผู้ดูแลระบบ → การเชื่อมต่อระบบภายนอก, ACR-INT-02): stored
  encrypted, write-only, never returned to the browser or written to logs. `RMS_API_KEY` in
  `apps/backend/.env` remains the fallback when nothing is saved there. Never in git.

## Resources and the fields we would use

| res | scope | Fields | Platform target |
|---|---|---|---|
| `school` | school.read | school {id, name}, term {id, start, end} | display / sanity check |
| `staff` | staff.read | id (`s_...`, stable per app), name (with title), surname, position, departments | Person (TEACHER or STAFF) |
| `groups` | classes.read | id, short_name, name, level, grade, major, department, year (B.E.), advisors | Department, Program, Classroom |
| `students` | students.read | id (= student code), prefix, name, surname, group_id, number | Person (STUDENT) + StudentProfile |
| `timetable` | timetable.read | term, group, subject, teachers, day, periods, room | not used (out of scope) |

RMS does **not** send birth dates, addresses, phone numbers, photos or guardian data.
The national ID is available only with an extra scope; this platform does **not** need it
and must not request it (`person_code` stays empty or keeps its current value).

## Open mapping questions (answer with `rms:preview` on real data)

1. `level` values → `education_level` (expected ปวช. → VOC_CERT, ปวส. → HIGH_VOC_CERT).
2. `grade` → `year_level`.
3. `department` names → existing Department rows (create missing ones, or map by hand).
4. `major` → Program (by name within the department).
5. `short_name` → Classroom code for `year` (academic year).
6. Staff `position` → TEACHER vs STAFF.
7. Students present here but no longer returned by RMS (graduated, withdrawn): flag for
   review; never change `academic_status` or delete automatically.

## First live preview (2026-11-03, counts only)

- Term 2/2569. Staff 184, groups 149, students 1300 (3 pages, no duplicates, all in a known group).
  1300 looks low for the whole college: confirm with the RMS administrator that the key sees every student.
- `groups.department` is **empty for all 149 groups**: departments must come from `major`
  (or a hand-made major → department table), not from RMS directly.
- `grade` is text such as `ปวช.1`, `ปวส.2` (level + year), not a number: parse the trailing digit.
- `level`: only `ปวช.` (72 groups) and `ปวส.` (77).
- Group `year` spans 2563-2569: older groups still hold enrolled (over-term) students.
- 31 majors with naming that differs by level (e.g. `ไฟฟ้า`, `ช่างไฟฟ้า`, `ช่างไฟฟ้ากำลัง`):
  needs a hand-confirmed major → Program/Department table.
- Staff `position`: ครู 62, ครูผู้ช่วย 22, ผู้บริหาร 3, **ไม่มี 97**. TEACHER vs STAFF cannot rely on
  position alone; staff `departments` mix teaching departments (`แผนกวิชา...`) and work units (`งาน...`).
- Nothing matches this dev database yet (0 student codes, 0 classrooms, 0 programs), as expected.

## Run the sync

```bash
docker compose exec backend php artisan rms:sync --dry-run   # report only, writes nothing
docker compose exec backend php artisan rms:sync             # real run
```

Or Students -> "ซิงก์จาก RMS" in the web UI (permission `student.sync`). Review the dry-run
report (unmapped majors, new departments, students missing from RMS) before the first real run,
then turn on the daily run on the การเชื่อมต่อระบบภายนอก page (or `RMS_SYNC_ENABLED=true`).

## Run the preview

```bash
docker compose exec backend php artisan rms:preview
docker compose exec backend php artisan rms:preview --json
```
