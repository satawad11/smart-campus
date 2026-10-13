# Upgrade and Rollback

**Status:** procedure defined; scripted upgrade with automatic pre-upgrade backup is backlog COM-04a. Rehearsed
only as image retag rollback in the pilot rehearsal (REPORTED).

## Upgrade
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
Migrations are additive (PROJECT_RULES §15). A release containing a destructive or data-rewriting migration must say
so in its notes and requires an explicit human approval plus a verified backup.

## Rollback
[runbooks/ROLLBACK.md](../runbooks/ROLLBACK.md): revert images; for schema/data problems restore the verified
pre-upgrade backup into a **new** database and switch to it (never restore over live data). Events delivered by
nodes after the backup are re-sent from node buffers only if still buffered; otherwise they are lost between backup
and rollback (RPO note in [BACKUP_AND_RECOVERY](BACKUP_AND_RECOVERY.md)).
