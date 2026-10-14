# Data Export and Offboarding Guide (COM-06)

Under the deployment model (ACR-COM-01 option B) the institution's data lives on the institution's own server, so
offboarding never depends on the vendor holding the data. A licence expiring never deletes data or blocks exports
([LICENSE_MODEL](LICENSE_MODEL.md)).

## 1. Full export (authoritative)
```bash
DC="docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml"
$DC exec backend php artisan backup:run --type=ALL --verify     # must exit 0
# If BACKUP_ENCRYPTION_KEY is set, decrypt copies for hand-over:
$DC exec backend php artisan backup:decrypt <file>.sql.enc /tmp/export.sql
$DC exec backend php artisan backup:decrypt <file>.zip.enc /tmp/objects.zip
```
- `*.sql`: PostgreSQL plain-format dump of every table (people, students, academic structure, events, decisions,
  audit logs, settings). Open format: restorable into any PostgreSQL 16 with pgvector.
- `*.zip`: object-storage archive (person photos, face samples, event snapshots) + `manifest.json`.

## 2. Partial exports for day-to-day needs
Reports UI → export (XLSX/CSV/PDF) for event history and summaries; person timeline API for a single person.

## 3. Biometric data at offboarding
Recommended: before hand-over or decommissioning, revoke and purge biometric data that is no longer needed
(`face:purge`, see [DSR_PROCEDURE](../legal/DSR_PROCEDURE.md)); biometric data should not move to a new system without
a lawful basis decided by the controller.

## 4. Decommissioning checklist
- [ ] Verified final export handed to the institution (checksum recorded).
- [ ] AI node credentials revoked (AI node → credentials → revoke) and nodes wiped.
- [ ] Vendor support access removed; vendor-held copies deleted and certified (DPA §11).
- [ ] Server disks and backup media securely wiped or retained per the institution's retention schedule.
- [ ] Licence revocation list updated by the vendor if the licence was terminated early.

## 5. Known gap
There is no dedicated CSV export of the student register (the `student.export` permission exists but no endpoint
uses it). The SQL dump is the complete export; a CSV export can be added if customers require it.
