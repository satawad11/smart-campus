# Production Secrets Management & Rotation Strategy (SEC-004)

## 1. Overview & Classification

The AI Smart Campus Security & Access Platform separates secrets into distinct classification tiers. In accordance with `PROJECT_RULES.md` §1 and §6, no hardcoded secrets, test credentials, or fallback defaults are permitted in production code or configuration.

| Secret Tier | Description | Storage / Injection Method | Rotation Cadence |
|---|---|---|---|
| **Tier 1: Master Keys** | `APP_KEY`, database encryption keys | Encrypted environment variables / AWS Secrets Manager / Vault | 12 Months (with dual-key decryption window) |
| **Tier 2: Infrastructure Credentials** | PostgreSQL application, migration, backup, and monitoring passwords; Redis password | Environment variables injected at container runtime | 90 Days |
| **Tier 3: External Service Credentials** | S3 access/secret keys, SMTP credentials | Injected via environment variables | 90 Days |
| **Tier 4: Node Credentials** | AI Edge Node bearer tokens (`ai_node_credentials`) | Bcrypt-hashed in database, plain token returned once to operator at creation | Revocable on-demand (CAM-004), 180 Days |
| **Tier 5: RTSP & Camera Passwords** | Camera connection passwords | Encrypted at rest via Laravel `Crypt` (AES-256-CBC) using `APP_KEY` | Per device provisioning |

---

## 2. Fail-Closed Production Secrets Policy

In production (`APP_ENV=production`):
1. **Application Key (`APP_KEY`)**: Must be 32 bytes base64-encoded (`base64:...`). Application terminates immediately on boot if missing or default.
2. **Database Credentials**: Plaintext default passwords (such as `postgres` or `secret`) cause immediate failure.
3. **Session Cookies**: `SESSION_SECURE_COOKIE=true` is strictly required. Non-HTTPS session cookies are rejected.
4. **CORS Allowlist**: Wildcard (`*`) is prohibited when credentials are enabled. `CORS_ALLOWED_ORIGINS` must be explicitly defined.

---

## 3. Secret Rotation Procedures

### 3.1 `APP_KEY` Rotation
When rotating `APP_KEY`:
1. Generate new 32-byte key: `php artisan key:generate --show`.
2. Configure previous key under `APP_PREVIOUS_KEYS` comma-separated to allow decrypting existing encrypted fields (e.g. RTSP passwords, MFA secrets).
3. Execute re-encryption job for encrypted attributes (`cameras.rtsp_password`, `users.two_factor_secret`).
4. Remove previous key after verification.

### 3.2 Database Password Rotation
1. Update password in PostgreSQL for service user:
   ```sql
   ALTER USER campus_app_user WITH PASSWORD 'new-secure-random-password';
   ```
2. Update deployment secret in orchestrator (Docker Compose / Kubernetes Secret).
3. Perform rolling restart of backend worker and HTTP containers.
4. Verify connection via `/up` health check.

### 3.3 AI Edge Node Token Revocation
1. Identify compromised node UUID or credential ID.
2. Operator calls `POST /api/v1/ai-nodes/{aiNode}/credentials/{credential}/revoke`.
3. Node token is immediately marked revoked; subsequent `/internal/v1/*` requests with that token receive HTTP 401.

---

## 4. Leakage Prevention & Audit Logging

1. All audit logging (`AuditService`) strips sensitive keys (`password`, `secret`, `token`, `key`, `rtsp_password`, `embedding`, `vector`) before persistence.
2. Exception handlers in `bootstrap/app.php` sanitize stack traces and database error details in JSON responses.
3. Git commit hooks and CI scanners enforce `.gitignore` rules forbidding `.env`, `.pem`, `.key`, and database dump files.
