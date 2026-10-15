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
| Administrator forgot the password and no other admin can log in | No UI path left | On the backend host: `docker compose exec backend php artisan access:reset-password <username>` (add `--reset-mfa` if the authenticator device is lost); log in with the printed one-time password and change it |
| SITE/ZONE officer can't see an event (404) | Event outside the officer's scope | Correct the role scope with `access:assign-role` |
| Admin gets 403 `MFA_ENROLLMENT_REQUIRED` | `security.admin_mfa_enforced` is on and the SUPER_ADMIN/SYSTEM_ADMIN has no MFA | Enrol on Account security (the app redirects there); another admin with MFA can switch the setting off |
| 429 Too Many Requests | `/api/v1` rate limit (`API_RATE_LIMIT_PER_MINUTE`, default 300/min per user) | Wait for `Retry-After`; raise the limit only with a reason |
| AI node OFFLINE | No heartbeat within the stale window | Node: `deploy/ai-node/healthcheck.sh`; token valid?; `BACKEND_BASE_URL` reachable? |
| Node CPU/RAM blank in health | Node build before telemetry fix | Upgrade node (stdlib telemetry, `2bafdbf`) |
| Events arrive late after an outage | Node delivering from its offline buffer | Normal; delivery is idempotent on `event_uuid` |
| Export stays PENDING | Queue worker stopped | `docker compose ps queue`; work drains on restart |

Support diagnostics: `php artisan support:diagnostics` writes a redacted JSON bundle (config checks, health, licence
state, counts, node versions, alerts, backup status, redacted error lines) to `storage/app/diagnostics/`. It contains
no names, e-mails, student codes, IPs, credentials, face images or embeddings by design; still review it before sending.
