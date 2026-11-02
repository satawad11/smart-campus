# RMS API Center v1 integration (student and staff source)

Source: the college's RMS (`https://rms.dmtc.ac.th/api_center.php`), vendor
document "RMS API Center v1" (2026). Read-only JSON over HTTPS.

## Status

| Stage | What | State |
|---|---|---|
| 1 | Read-only client + `php artisan rms:preview` (counts and field values to map; writes nothing, prints no personal data) | DONE, tested with fixtures |
| — | Live call from the platform server | BLOCKED: RMS answers `403 ip` until the RMS administrator allows the server's public IP |
| 2 | Sync into Person / StudentProfile (+ staff as Person TEACHER/STAFF) | NOT STARTED: needs ACR-INT-01 approval and a mapping confirmed against real data |

## Access rules (from the vendor document)

- GET only, `v=1`, key `rmsk_...` in `Authorization: Bearer` only (never in the URL), HTTPS always.
- Calls only from the allowed server IP(s). 60 calls/min per key; 429 carries `Retry-After`.
  10 wrong keys in 15 minutes block the caller for 15 minutes.
- Cache and refresh at most hourly or daily; never call RMS per page view or from the browser.
- Student data are personal data of mostly minors: use only what the data-sharing agreement allows.
- The key lives in `apps/backend/.env` (`RMS_API_KEY`), never in git, logs or the frontend.

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

## Run the preview

```bash
docker compose exec backend php artisan rms:preview
docker compose exec backend php artisan rms:preview --json
```
