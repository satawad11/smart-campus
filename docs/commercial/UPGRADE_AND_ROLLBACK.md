# Upgrade and Rollback

**Status:** scripted (`scripts/upgrade-campus.sh`, 2026-10-14): verified with `bash -n` and `--dry-run`;
**not yet run on a real host**. Image-retag rollback rehearsed in the pilot rehearsal (REPORTED).

## Upgrade from GitHub Releases (campus server installed from the kit)
Releases are produced by `.github/workflows/release.yml` when a `v*` tag is pushed: the full CI pipeline runs first,
then the campus-server kit (amd64) and AI-node kits (amd64, arm64) are built and attached to the GitHub Release with
`.sha256` files. Tags containing `-` (e.g. `v1.2.0-rc1`) are published as pre-releases.

On the server, an administrator runs the update by hand (nothing updates automatically):
```bash
sudo smart-campus update-setup     # once: repository + read-only fine-grained token (stored 0600 in update.env)
sudo smart-campus update-check     # releases on GitHub vs installed version
sudo smart-campus update vX.Y.Z    # notes + confirmation, download, SHA-256 check, then the kit's install.sh
sudo smart-campus rollback         # back to the images tagged before the last update (database not reverted)
```
`install.sh` in update mode: verified backup, `rollback-<timestamp>` image tags + `.last-rollback`, maintenance
mode, migrations as `campus_migration_user`, restart, preflight; history in `update-history.log`. If a step fails the
installer stops, leaves maintenance mode on and prints the rollback command.
Tested 2026-11-08 against a mock GitHub API (25 checks: list, auth, cancel, download, checksum, tamper rejection,
missing assets, no-op, rollback). **Not yet run against a real GitHub Release or a real campus server.**

## Upgrade from the web app (ACR-OPS-01)
Admin page **อัปเดตระบบ** (`/system/update`, permission `system.update`, SUPER_ADMIN only) shows the installed
version, update history, newer releases with their notes, and the last job. "Update" needs the admin's password
again and is audited (`SYSTEM_UPDATE_REQUESTED` / `_REJECTED` / `_CHECK_REQUESTED`).

How it works (design and threat model: `docs/decisions/ACR-OPS-01-WEB-SYSTEM-UPDATE.md`):
- `install.sh` runs `update-agent-setup.sh`: `update-state/` (root, token-free JSON, mounted read-only into the
  backend), `update-requests/inbox` (www-data, mounted read-write into the backend only), `UPDATE_AGENT_KEY` in
  `campus.env`, systemd `smart-campus-update-agent.path`/`.service` and a daily `smart-campus-update-check.timer`.
- The backend writes an HMAC-signed request; the root agent (`update-agent.py run`) accepts only `check` and
  `update`, re-checks signature, 10-minute expiry, replay, tag format and that the tag is newer than the installed
  one with a kit, then runs the unchanged `smart-campus update <tag> --yes`. Status goes to `update-state/status.json`,
  full logs to `update-logs/` (root only).
- No downgrade from the web; `sudo smart-campus rollback` stays CLI-only. `sudo smart-campus web-update off` disables
  the button (the page still shows versions and the CLI command).
- The first update to a release containing this feature must be done from the CLI.

Release notes: write `docs/releases/<tag>.md` in Thai before tagging (template: `docs/releases/TEMPLATE.md`);
`release.yml` uses it as the release body, otherwise GitHub's generated notes.

Verification: agent unit tests (`deploy/campus-server/tests`, Python 3.10/3.12), backend `SystemUpdateTest`, frontend
`system-update.test.js`. **Not yet run end-to-end on a real campus server with systemd.**

## Scripted upgrade (source checkout)
```bash
MIGRATION_DB_PASSWORD=... scripts/upgrade-campus.sh <approved-tag> --dry-run   # review the plan
MIGRATION_DB_PASSWORD=... scripts/upgrade-campus.sh <approved-tag>             # asks for confirmation
```
The script performs the manual steps below in order, starting with a **verified backup**, tags the running images
`rollback-<timestamp>`, writes `.upgrade-state`, and on any failure stops and prints the exact rollback commands.

## Manual upgrade (same steps)
1. Read the release notes; check the release is human-approved ([RELEASE_CHECKLIST](RELEASE_CHECKLIST.md)).
2. Take and verify a backup **before** anything changes:
   `docker compose exec backend php artisan backup:run --type=ALL --verify` → must exit 0; confirm the off-host copy.
3. Tag the running images for rollback and enable maintenance mode: [runbooks/DEPLOYMENT.md](../runbooks/DEPLOYMENT.md) §3.1.
4. Check out the new tag, build, migrate (`php artisan migrate --force`), re-run the approved seed, cache
   (DEPLOYMENT §3.2–§3.5).
5. `php artisan pilot:preflight` and the smoke test; leave maintenance mode.
6. Upgrade AI nodes last (`deploy/ai-node/update.sh`): nodes buffer events offline while the backend is down and
   deliver them idempotently afterwards (CAM-012, A-07).

## Migration rules
### Object storage: MinIO → SeaweedFS (releases after 2026-11)
The first update to a release with SeaweedFS copies every object from the old MinIO store (`storage-migrate`,
rclone), verifies it byte for byte, and only then points the app at the new store. The `smart-campus_minio-data`
volume is kept; delete it by hand once the new release is accepted. If the copy fails the update stops with the
app still on MinIO, so `smart-campus rollback` finds every file. Allow time and disk space for one more copy of
the object data.

Migrations are additive (PROJECT_RULES §15). A release containing a destructive or data-rewriting migration must say
so in its notes and requires an explicit human approval plus a verified backup.

## Rollback
[runbooks/ROLLBACK.md](../runbooks/ROLLBACK.md): revert images; for schema/data problems restore the verified
pre-upgrade backup into a **new** database and switch to it (never restore over live data). Events delivered by
nodes after the backup are re-sent from node buffers only if still buffered; otherwise they are lost between backup
and rollback (RPO note in [BACKUP_AND_RECOVERY](BACKUP_AND_RECOVERY.md)).
