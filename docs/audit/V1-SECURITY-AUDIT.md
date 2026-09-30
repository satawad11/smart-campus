# V1 Security Audit (Independent Re-Audit)

**Environment for real-path tests:** production-mode pilot overlay on the workstation (`APP_ENV=production`,
TLS gateway with a self-signed certificate, random secrets, least-privilege DB and MinIO users).
Evidence is TEST DATA. Probe script: `scripts/audit/real_path_probe.py`.

## 1. Authentication and session (real browser-style HTTP)
| Check | Result |
|---|---|
| Login without CSRF token | 419 |
| CSRF cookie → login | 200; session id **rotated** on login |
| Forced password change | before fix: privileged API **200** (A-04); after fix: 403 `PASSWORD_CHANGE_REQUIRED`, change-password 200, re-login 200 |
| Logout | 200, then `/auth/me` 401 with the old cookie |
| Session cookie | `Secure`, `HttpOnly`; HSTS on API responses |
| Lockout | account lockout after 5 failures (AUTH-006 tests) |
| Login throttle | missing before (A-03); now 30/min per IP and 10/min per IP+username (test) |
| MFA | TOTP API tested in-process; **no enrollment UI** (A-15) |

## 2. Authorization (RBAC + GLOBAL/SITE/ZONE scope)
- Route inventory: 157 routes. 23 `/api` routes carry no `permission:` middleware. All were reviewed: auth
  self-service, `/features`, `/settings/public`, `broadcasting/auth` (channel callback authorizes),
  notifications and sessions (owner-checked in code), and locked-feature stubs (feature gate, 403). No
  unintended open endpoint was found. `storage/{path}` (signed local-file serving) isn't routed by the gateway.
- Role matrix (seeded): SUPER_ADMIN `*`, SYSTEM_ADMIN `*` (A-27), DISCIPLINE_OFFICER 13 permissions incl.
  `event.review`, SECURITY_OFFICER 6 (view/monitor, **no review**, no `report.view` → A-14),
  EXECUTIVE_VIEWER 3.
- Negative tests over real sessions: site officer → `/users` 403, `POST /ai-nodes` 403.
- **Scope (A-05, BLOCKER, fixed):** before the fix, cross-site list and open succeeded (200). After:
  cross-site list excludes the event, open 404, review 404, camera/AI-node/credential actions 404,
  create in a foreign site 404, reports/dashboard/charts for a foreign site 404, and export filters pinned to scope.
  Zone-scoped users are limited to their zone. GLOBAL roles are unchanged. Reverb channel: cross-site auth 403.
- IDOR: export show/download by another user (A-06) fixed; notifications and sessions already owner-checked.

## 3. Input, output and error handling
- Mass assignment: FormRequests declare `prohibited` for ids, uuids and security fields (users, cameras,
  nodes, ingestion including `access_decision`, `person_id`, `site_id`).
- Error sanitisation under failure (Redis and PostgreSQL down): no stack trace, SQL, host or credential in
  API responses. 404 responses contain no trace.
- Numeric IDs: resources expose UUIDs; export list leak fixed (A-13).
- Raw embeddings: not returned by any resource. The internal recognize endpoint returns profile/person UUIDs
  and scores only. The broadcast payload checked for `"id":` and `embedding`: none.
- Upload spoofing: face/photo uploads are validated as images by FormRequests (FACE-020 and STD tests). The
  AI service decodes with OpenCV/PIL and rejects undecodable bytes outside mock mode. Mock mode accepts
  arbitrary bytes by design.

## 4. Machine API (AI node)
| Check | Result |
|---|---|
| Bad bearer token | 401 |
| Token storage | sha256 hash only; plaintext shown once; list never shows it |
| Node identity binding | `ai_node_uuid` must equal the authenticated node; camera must belong to it; config only for own node |
| Trust boundary | **the node's MATCH claim isn't re-validated against thresholds** (A-18, MEDIUM, open) |

## 5. Biometric safety
- PAD fail-open in non-mock AI modes fixed (808acf9): the service answers 503 `PAD_MODEL_UNAVAILABLE`.
- Backend PAD can still be switched off or mocked by env. `pilot:preflight` now FAILs on
  `FACE_LIVENESS_ENABLED=false`, provider `mock`, recognition disabled, or ai-service `AI_MODE=mock` (A-12).
- Cross-model comparison: vector search is filtered by model code and version (FACE-008 tests).
- No production model (B5).

## 6. Transport, headers, CORS
TLS gateway (TLS 1.2/1.3, HTTP→HTTPS 301). X-Frame-Options DENY, nosniff, CSP on API responses, HSTS.
CORS: explicit origin only; foreign origin not echoed. The static SPA has security headers but no CSP (INFO).

## 7. Secrets and privacy
- Repository scan: no private keys, certificates, datasets, face images or national-ID-like numbers
  tracked. Known items: `InitialAdminUserSeeder` shared dev password (development-only, production no-op,
  preflight detects the password hash on any active account), the RTSP URL in an ai-service test fixture
  (TEST-NET address), and the dev Reverb public key in a load script (P08-25).
- Logs: backend logs record error messages without credentials. ai-service RTSP masking is covered by tests
  (`test_rtsp.py`). Business 409s are logged at ERROR (A-24, noise).
- Backups: contain biometric source images. They're 0600, and the off-host target must be encrypted
  (policy; human approval pending).

## 8. Locked future modules
`FEATURE_DOOR/VISITOR/VEHICLE/ALPR/AI_ASSISTANT_ENABLED=false` kill switches engaged; `/api/v1/features`
all false; stub routes 401 unauthenticated and 403 authenticated. No DOOR/VIS/VEH/AIA implementation
exists beyond the SEC-002 gate stubs. **LOCKED.**
