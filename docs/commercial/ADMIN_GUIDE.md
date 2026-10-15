# Administrator Guide (Campus Instance)

For day-to-day operator tasks see [PILOT-004 operator onboarding](../pilot/PILOT-004-OPERATOR-ONBOARDING.md).

## Accounts and access
| Task | How |
|---|---|
| First administrator | `php artisan access:bootstrap-admin <username>` (once; one-time password, forced change) |
| Create users | UI → Users → New (forced password change at first login) |
| Forgotten password / locked-out admin | Another admin: UI → Users → Reset password. No admin can log in: on the server `php artisan access:reset-password <username>` (one-time password printed once, forced change, all sessions revoked, lockout cleared, audited). Lost authenticator: add `--reset-mfa` |
| Assign a role with scope | `php artisan access:assign-role <username> <ROLE>` (GLOBAL), add `--site=<code>` (SITE) or `--site=<code> --zone=<code>` (ZONE); `--until=`, `--revoke` (CLI only, A-01) |
| Enrol MFA (own account) | header link **ตั้งค่า MFA** → Account security; strongly recommended. To make it **required** for SUPER_ADMIN/SYSTEM_ADMIN: System Settings → Security → `security.admin_mfa_enforced` = on (off by default). Enrol your own MFA first |
| Sites and gates | `php artisan locations:provision <SITE_CODE> "<Site name>" --zone="GATE-1:Main Gate:GATE"` (idempotent) |
| Disable a user | UI → Users → user → status |
| Revoke a user's sessions | API `POST /api/v1/users/{uuid}/sessions/revoke-all` (`user.update`; no UI yet, A-17) |

Data scope: SITE/ZONE-scoped users only see records in their scope; out-of-scope records answer 404.

## Biometric erasure (data subject requests)
1. Revoke the person's face profile (UI or API; audited).
2. `php artisan face:purge <person_uuid> --dry-run` to review, then `php artisan face:purge <person_uuid> --reason=<DSR ticket>`.
3. Record the DSR ticket. Copies inside existing backups disappear when those backups expire.

## Licence
`php artisan license:status` · `license:install <file>` · `license:import-revocations <file>`; API
`/api/v1/system/license`. Alerts appear in System → Alerts. Details: [LICENSE_MODEL](LICENSE_MODEL.md).

## Monitoring
System health page; `/health/full` for external probes (timeout ≥ 20 s, A-23); alerts raised automatically by
`system:monitor` (every minute) and `license:check` (hourly). Acknowledge and resolve alerts in the UI.

## Backups
`php artisan backup:run --type=ALL --verify`; scheduled when `BACKUP_SCHEDULE_ENABLED=true`. See
[BACKUP_AND_RECOVERY](BACKUP_AND_RECOVERY.md).

## Go-live / change checks
`php artisan pilot:preflight --require-backup` before go-live and after every upgrade.

## Things administrators must not do
Enable door/visitor/vehicle/ALPR/AI-assistant flags; change FACE thresholds without human approval; restore a backup
over a live database; put real student data on a non-production host without the controller's approval.
