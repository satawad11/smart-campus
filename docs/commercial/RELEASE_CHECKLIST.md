# Release Checklist

A release is a **human-approved** annotated tag on a reviewed commit. Agents prepare evidence; they don't tag,
push or deploy (PROJECT_RULES; master plan §14).

## 1. Engineering gates (each with command output attached to the release record)
- [ ] Backend tests, SQLite: `cd apps/backend && php artisan test` → 0 failed
- [ ] Backend tests, PostgreSQL 16: `RUNNER=local bash scripts/test-backend-pgsql.sh` (or CI job `backend-pgsql`) → 0 failed
- [ ] Pint: `vendor/bin/pint --test`
- [ ] AI: `pytest -q`, `ruff check .`, `ruff format --check .`
- [ ] Frontend: `npm run lint`, `npm run test`, `npm run build`
- [ ] `docker compose build backend frontend ai-service`
- [ ] `composer audit`, `npm audit --audit-level=high` reviewed (advisories documented)
- [ ] Migrations reviewed: additive only, or destructive ones called out with a backup step
- [ ] No secrets in the diff (`git diff <prev-tag>..HEAD` scanned; `.env`, `*.secret`, `*.lic`, keys absent)
- [ ] V1-locked feature flags still locked (`pilot:preflight` locked-features group PASS)
- [ ] FACE-021 thresholds unchanged, or the change carries its own human approval

## 2. Change review
- [ ] Change review pack listing behaviour changes since the last approved tag (template: PILOT-000B)
- [ ] Human approval recorded (name, role, date, approved commit)

## 3. Release artefacts
- [ ] Annotated tag `vX.Y.Z` on the approved commit (by the release owner):
      `git tag -a vX.Y.Z -m "..." <commit> && git push origin vX.Y.Z`
- [ ] GitHub Actions **Release** workflow green (CI → kits → release); the release lists
      `campus-server-kit-vX.Y.Z-amd64.tar.gz`, `ai-node-kit-vX.Y.Z-{amd64,arm64}.tar.gz` and their `.sha256` files
- [ ] Release notes edited on GitHub (they are shown to the administrator by `smart-campus update`)
- [ ] Release notes: features, fixes, migrations, config changes, known limitations, upgrade notes
- [ ] Runbook references updated to the new tag (DEPLOYMENT.md, ROLLBACK.md)
- [ ] Licence public keys in `config/license.php` are the intended production keys

## 4. Current state (2026-10-13)
Development branch only. Open: PILOT-000B approval, B5, ACR-COM-01, PDPA/legal items. **No release candidate tagged.**
