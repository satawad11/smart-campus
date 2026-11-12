# ARCHITECTURE_CHANGE_REQUEST — ACR-INT-02 External API connection settings from the web app

**Status:** APPROVED by the project owner (chat, 2026-11-12) · implemented, see the notes below
· **Raised:** 2026-11-12 · **Requested by:** project owner (chat, 2026-11-12: "งานต่อไปคือกำหนดการตั้งค่า
การเชื่อมต่อ API ภายนอกจากหน้าแดชบอร์ด"; chose: key encrypted in the database, an
"Integrations" page with RMS first, a new permission `integration.manage`)

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  The RMS API Center connection (ACR-INT-01) is set only in apps/backend/.env
  (RMS_API_URL, RMS_API_KEY, RMS_SYNC_ENABLED, RMS_SYNC_CRON). Issuing, rotating or
  revoking a key in RMS, or turning on the daily sync, needs a shell on the server and a
  container restart.

Current specification:
  docs/integrations/RMS_API_CENTER.md: "The key lives in apps/backend/.env (RMS_API_KEY),
  never in git, logs or the frontend." PROJECT_RULES §9: service credentials are never
  exposed through normal frontend APIs; sensitive administrative operations are audited.

Proposed change:
  1. New table integration_connections (code unique, base_url, api_key_encrypted,
     api_key_hint, settings jsonb, last test result, updated_by_user_id). One row per
     connector; RMS only in V1. A null column falls back to .env/config.
  2. The API key is write-only: encrypted with Crypt (as camera credentials and MFA
     secrets), hidden from serialization, never returned by any API, never written to
     audit logs. The UI sees only api_key_set, the source (database | env) and a
     non-secret hint (the RMS key id, e.g. rmsk_9bcdb909_...).
  3. Changing the API address requires entering the key again in the same request, so the
     stored key cannot be redirected to another host.
  4. API: GET /api/v1/integrations (system.view), PUT /api/v1/integrations/rms and
     POST /api/v1/integrations/rms/test (integration.manage; the test is throttled 6/min
     because RMS blocks a caller after 10 wrong keys in 15 minutes).
  5. New permission integration.manage, SUPER_ADMIN only (like system.update).
  6. RmsClient, the sync page status and the scheduler read the effective settings
     (database over .env) on every use; no restart needed.

Reason:
  Key rotation and the sync schedule are routine administrator tasks; they should not need
  shell access, and they should be audited.

Impact:
  Additive: one table, one permission, three endpoints, one page. .env keeps working as
  the fallback (existing installations behave the same until something is saved).
  The key now also exists in the database (encrypted with APP_KEY) and therefore in
  database backups (encrypted). Losing APP_KEY makes the stored key unreadable; the page
  reports this and asks for the key again.

Migration impact:
  New table and permission only; no existing data changes.

Alternatives:
  A. Keep the key in .env; the page shows status and tests the connection only.
  B. RMS tab inside System Settings (system_settings is not built for secrets: values are
     returned to the browser and copied into setting histories in clear text).
```

## Implementation notes (2026-11-12)

- Table `integration_connections`, model `IntegrationConnection` (`setApiKey()`, `apiKey()`,
  `hasUnreadableKey()`, `hintFor()`).
- `RmsConnectionSettings::current()` resolves the effective connection (database over
  `config/rms.php`/.env). Used by `RmsClient::fromConfig()`, `RmsSyncController` meta and
  `routes/console.php` (read only when a `schedule:*` command runs). Before the migration it
  falls back to the config.
- `IntegrationConnectionService`: present / update / test. The test calls `res=school` with
  the **saved** settings (the page asks to save first) and stores the result on the row;
  a new or cleared key resets the last result. Audit actions `INTEGRATION_UPDATED`
  (old/new address, hint, source, timeout, schedule; metadata `api_key_changed`,
  `api_key_cleared`) and `INTEGRATION_TESTED` (ok, error code, duration, key source).
- Schedule: the page sets a daily time (`HH:MM`); the cron becomes `M H * * *`. A cron
  from .env that is not a simple daily time is shown as cron text.
- Turning on the daily sync with no key (database or .env) is refused (422).
- UI: menu ผู้ดูแลระบบ → การเชื่อมต่อระบบภายนอก (`/integrations`, `views/system/IntegrationsView.vue`);
  read-only without `integration.manage`. The RMS sync page points there.
- Existing installations: the migration grants `integration.manage` to SUPER_ADMIN when the
  role matrix is already seeded; fresh installs get it from the seeders
  (`RolePermissionSeeder::SUPER_ADMIN_ONLY`).
