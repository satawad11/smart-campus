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
Install the certificate with `scripts/tls-setup.sh` ([TLS_SETUP](TLS_SETUP.md): import, internal CA or
Let's Encrypt; vendor-hosted instances also open port 80 for renewal).
Run `scripts/pilot-host-preflight.sh` (H1–H14) and fix every FAIL.

## 3. Install

### 3a. Offline install kit (recommended)
The vendor builds `dist/campus-server-kit-<version>-amd64.tar.gz` with `scripts/package-campus-server.sh` from an
approved tag. On the campus server (Linux, Docker, or Ubuntu where the installer offers `apt install docker.io`):
```bash
tar -xzf campus-server-kit-<version>-amd64.tar.gz && cd campus-server-kit-<version>-amd64
sudo ./install.sh          # 4 questions with defaults; --yes for none
```
It automates §3b steps 2–3 and the first verified backup: random secrets in `/opt/smart-campus/campus.env`
(mode 600), images from the kit (no Internet, no source), internal-CA HTTPS, MinIO and least-privilege DB roles,
migrations and approved seed, site `MAIN` with zone `GATE-1`, first SUPER_ADMIN (one-time password shown once),
health check and `pilot:preflight` summary. It refuses to run when a database volume exists without its
`campus.env` (never overwrites data). Running a newer kit's `install.sh` on an installed host is the update path:
verified backup, rollback image tags, maintenance mode, migrations, restart; existing `campus.env` values are kept.
Still manual: license (step 4 below), trusting `infrastructure/pilot/ca/ca.crt` on clients and nodes (or
`smart-campus tls import`), `LICENSE_ENFORCEMENT=enforce`, `AI_MODE` (B5), off-host backup mount, and keeping a copy
of `campus.env` off the host. Day-to-day: `smart-campus status|logs|backup|preflight|artisan`.

### 3b. Manual install (from the repository)
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
