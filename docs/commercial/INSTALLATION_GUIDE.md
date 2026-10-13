# Installation Guide — Campus Instance (Commercial)

**Status:** procedure assembled from the pilot deployment package, which was rehearsed on a workstation
(PILOT-001/002, REPORTED). **Not yet executed on a customer host.** Assumes tenancy option B (one isolated instance
per institution, [ACR-COM-01](ACR-COM-01-TENANCY-MODEL.md)), pending decision.

## 1. Environments
| Environment | Compose files | `APP_ENV` | Data |
|---|---|---|---|
| Development | `docker-compose.yml` | `local` | synthetic; dev seeders allowed (`local`/`testing` only) |
| Staging / rehearsal | `docker-compose.yml` + `docker-compose.pilot.yml` | `staging` | synthetic or approved test data |
| Production (customer) | `docker-compose.yml` + `docker-compose.pilot.yml` | `production` | real data only with the controller's approval |

Dev seeders never create shared-password accounts outside `local`/`testing` (C-04).

## 2. Host prerequisites
Follow [PILOT-HOST-DEPLOYMENT-CHECKLIST](../pilot/PILOT-HOST-DEPLOYMENT-CHECKLIST.md) §2–§3: Linux host, Docker
Engine + Compose v2, DNS name and trusted TLS certificate, NTP, **full-disk encryption** (PDPA G-3/G-4), off-host
backup target, firewall allowing only 443 from the campus network, no inbound SSH from the Internet.
Run `scripts/pilot-host-preflight.sh` (H1–H14) and fix every FAIL.

## 3. Install
1. Check out the **approved release tag** (never a branch). See [RELEASE_CHECKLIST](RELEASE_CHECKLIST.md).
2. Create the env file from `infrastructure/pilot/pilot.env.example` outside the repository; fill every secret.
   Set `LICENSE_ENFORCEMENT=enforce` for commercial installations.
3. Deploy: [runbooks/DEPLOYMENT.md](../runbooks/DEPLOYMENT.md) §3.2–§3.5 (images, datastores, DB roles,
   migrations, approved seed, first admin via `access:bootstrap-admin`, `locations:provision`).
4. License:
   ```bash
   docker compose exec backend php artisan license:status        # send the Installation ID to the vendor
   docker compose exec backend php artisan license:install /path/customer.lic
   ```
   (or System → License API). See [LICENSE_MODEL](LICENSE_MODEL.md).
5. Go-live gate: `php artisan pilot:preflight --require-backup` must PASS. While no production biometric model is
   approved (B5), the preflight FAILs on `AI_MODE=mock` by design: biometric go-live is impossible until then.
6. AI node(s): [AI-NODE-INSTALLATION-GUIDE](../pilot/AI-NODE-INSTALLATION-GUIDE.md) (`deploy/ai-node/`).
7. Smoke test: PILOT-HOST-DEPLOYMENT-CHECKLIST §10.

## 4. Repeatability
Every step above is idempotent (seeders, `locations:provision`, `minio-init`, `db-roles`); `access:bootstrap-admin`
refuses to run twice. Re-running the procedure on a configured host doesn't duplicate data.
