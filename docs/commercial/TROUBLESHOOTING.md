# Troubleshooting

| Symptom | Likely cause | Check / fix |
|---|---|---|
| `pilot:preflight` FAIL `AI_MODE=mock` | No production biometric model (B5) | Expected until a model is approved; non-biometric operation only |
| Adding a camera/AI node returns 409 `LICENSE_LIMIT_REACHED` | Active count at the licensed limit | Deactivate an unused one or install a larger licence |
| 409 `LICENSE_REQUIRED` | Enforce mode with no usable licence (none, expired past grace, revoked, invalid) | `php artisan license:status`; install a valid licence |
| Licence install 422 `LICENSE_INSTALLATION_MISMATCH` | Licence issued for another installation ID | Send `license:status` installation ID to the vendor |
| Licence install 422 `LICENSE_CRYPTO_UNAVAILABLE` | PHP without sodium | Use the project backend image |
| Alert `LICENSE_CLOCK_ROLLBACK` | Host clock went backwards | Fix NTP; expiry already uses the later time |
| `/health/redis` 503, REDIS_HEALTH alert | Redis down | `docker compose ps redis`; scheduler keeps running (A-11) |
| `/health/database` slow then 503 | PostgreSQL down (≈16 s to fail, A-23) | `docker compose ps postgres`; disk space |
| User gets 403 `PASSWORD_CHANGE_REQUIRED` | Forced password change pending | User changes password at `/change-password` |
| SITE/ZONE officer can't see an event (404) | Event outside the officer's scope | Correct the role scope with `access:assign-role` |
| Security officer dashboard 403 | Open RBAC decision A-14 | Pending owner decision |
| AI node OFFLINE | No heartbeat within the stale window | Node: `deploy/ai-node/healthcheck.sh`; token valid?; `BACKEND_BASE_URL` reachable? |
| Node CPU/RAM blank in health | Node build before telemetry fix | Upgrade node (stdlib telemetry, `2bafdbf`) |
| Events arrive late after an outage | Node delivering from its offline buffer | Normal; delivery is idempotent on `event_uuid` |
| Export stays PENDING | Queue worker stopped | `docker compose ps queue`; work drains on restart |

Support diagnostics must not include face images, embeddings or student data. Share `pilot:preflight --json`,
`/health/full` output and container logs filtered for errors.
