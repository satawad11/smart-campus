# Data Subject Request (DSR) Procedure — Campus Instance

**Status: DRAFT operational procedure.** Timelines and approvals must be confirmed by the institution (controller)
and its PDPA counsel. Statutory deadlines quoted here must be verified by counsel before use.

## 1. Intake
1. Request arrives through the channel in the privacy notice. Log it with a ticket ID (e.g. `DSR-2026-001`).
2. Verify the requester's identity (and guardian authority for a minor) before acting. Never disclose data to an
   unverified requester.
3. Record: request type, person UUID, received date, due date (proposed: 30 days), handler.

## 2. Handling by request type
| Request | How (technical) | Who |
|---|---|---|
| Access / copy | Student record and status history (UI/API), person timeline and events (`/api/v1/persons/{uuid}/timeline`), report export filtered to the person. **Never export face embeddings**; face images only if the controller decides they are in scope | Data officer with `student.view`/`report.export` |
| Correction | Student edit in the UI (audited) | Data officer |
| Access / correction / erasure of vehicle data (ACR-VEH-01) | จัดการยานพาหนะ: vehicle detail shows the person's current and past links; edit the vehicle (audited); end a link instead of deleting it; plate reads lose their plate text after `retention.plate_reads_days`. Search the events by plate to answer "where was my car seen" (each search is audited) | Data officer with `vehicle.manage` / `event.view` |
| Withdraw consent for biometrics / erasure of biometrics | 1. Revoke the face profile (UI/API, audited). 2. `php artisan face:purge <person_uuid> --dry-run`, then `--reason=<DSR ID>` (irreversible). Backup copies expire with the backup retention (max ~30 days) | System administrator |
| Erasure of other data | Assess legal retention duties first; access events expire after the events retention period; archive the student record | Controller decision |
| Objection / restriction | Disable the person's face profile (revoke) and record the restriction in the ticket | Data officer |

## 3. Closing
Reply to the requester, attach evidence (audit log entries: `FACE_REVOKED`, `FACE_BIOMETRICS_PURGED` with the DSR
ID as reason), close the ticket. Keep the ticket (without the exported data) as accountability evidence.

## 4. Breach
A suspected personal-data breach follows the incident procedure (System → Incidents) and the controller's breach
notification process (notification to the regulator within the statutory deadline — commonly cited as 72 hours;
counsel to confirm).
