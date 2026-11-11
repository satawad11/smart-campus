# Security and Privacy Baseline (Phase 4 checklist)

**Date:** 2026-10-13. Status per control: **TECH** = technical control implemented and tested · **PARTIAL** ·
**GAP** · **ORG** = organisational control (process, people) · **LEGAL** = needs legal review.
"Implemented" never means "compliant": see §4.

## 1. Technical controls

| # | Control | Status | Evidence / gap |
|---|---|---|---|
| 1 | Least privilege / RBAC with permission codes | TECH | `RequirePermission`, `contracts/permissions.yaml`; A-14 resolved (`144164d`); open: A-27 (SYSTEM_ADMIN = `*`) |
| 2 | Data scope GLOBAL / SITE / ZONE on REST and realtime | TECH | `DataScopeEnforcementTest`, `BroadcastChannelScopeTest` (A-05 fixed) |
| 3 | MFA for administrators | TECH (switchable) | Enrollment UI; enforcement for SUPER_ADMIN/SYSTEM_ADMIN is **off by default** (owner, 2026-10-14) and switched on in System Settings → Security → `security.admin_mfa_enforced`; when on, APIs and realtime are refused until enrolled (`MfaEnforcementTest`). Recommended: switch on before real data |
| 4 | Password policy, lockout, login throttle | TECH | AUTH-006, `throttle:login` (A-03) |
| 5 | Session management, forced logout, forced password change | TECH | AUTH-012; server-side forced change (A-04) |
| 6 | Credential rotation | PARTIAL | AI-node credentials: issue/revoke API. App secrets: manual per `docs/security/SECRETS-STRATEGY.md` |
| 7 | TLS in transit | TECH | TLS gateway; node `health_check` FAILs on plain HTTP to a remote backend (G-7) |
| 8 | Encryption at rest | PARTIAL | Camera credentials encrypted (Laravel `Crypt`); MFA secrets encrypted. DB/object-storage/backup volumes rely on host disk encryption (GAP: not enforced by preflight) |
| 9 | Secrets management | PARTIAL | `.env` outside repo, mandatory pilot secrets; no vault. License private key never in product (COM-02) |
| 10 | Audit logging | TECH | `AuditService` with sensitive-key scrubbing; biometric, admin, licence actions audited |
| 11 | Rate limiting | TECH | login, MFA throttled; `/api/v1` limited per user/IP (`API_RATE_LIMIT_PER_MINUTE`, default 300; `ApiRateLimitTest`); `/internal/v1` excluded |
| 12 | Secure file upload | DONE (needs clamav running) | type/size validation plus ClamAV malware scan (`MalwareFreeUpload`) on student import, student photos, face enrollment/re-enrollment and recognition-test images; infected files are rejected, audited (`UPLOAD_MALWARE_DETECTED`) and alerted; fail-closed (503) when the scanner is down. `UPLOAD_SCAN_DRIVER=clamav` in the pilot env; off in development. Signatures update over outbound HTTPS (stale on fully offline sites) |
| 13 | Database least privilege | TECH | `docs/security/LEAST-PRIVILEGE-ROLES.sql`, preflight warns on owner user |
| 14 | Backup encryption | TECH (opt-in) | `BACKUP_ENCRYPTION_KEY` → libsodium secretstream, drill verifies by decrypting, `backup:decrypt`; key custody = ops (vault, apart from backups) |
| 15 | Restore testing | PARTIAL | rehearsal restores into an empty DB pass (REPORTED); none on a production host (BLOCKED) |
| 16 | Vulnerability / dependency scanning | PARTIAL | `composer audit` + `npm audit` in CI (advisory only); no container image scan |
| 17 | Dependency licence audit | PARTIAL | Done for libraries ([DEPENDENCY_LICENSES](DEPENDENCY_LICENSES.md)): all permissive; Redis ≥7.4 image needs counsel review (MinIO/AGPL replaced by SeaweedFS/Apache-2.0) |
| 18 | Threat model | TECH | [THREAT_MODEL](THREAT_MODEL.md) (STRIDE, 15 threats); review each release |
| 19 | Cross-tenant security tests | BLOCKED | depends on ACR-COM-01 |
| 20 | Biometric data minimisation and erasure | PARTIAL | `face:purge` erasure (G-1 done); retention schedule for revoked profiles is a controller decision (G-2) |
| 21 | Security headers, CSP, HSTS, CORS | TECH | SEC-003 |
| 22 | Locked future modules can't be enabled | TECH | kill switches + preflight |
| 23 | Health endpoints don't leak internals | TECH | A-09; sanitized errors |

## 2. Organisational controls (ORG — none can be completed by engineering)
Incident response roles and on-call; breach notification to PDPC within 72 h; access reviews (quarterly);
staff training on biometric data handling; records of processing activities; DPO appointment where required;
vendor/sub-processor management; change-approval board (see PILOT-000B).

## 3. Legal (LEGAL)
Lawful basis for biometric processing of students (incl. minors; guardian consent), privacy notices (Thai),
Data Processing Agreement between institution (controller) and vendor (processor), DPIA, retention schedule,
DSR procedure and timelines, cross-border transfer (none under ACR option B).

## 4. Release position
Commercial sale requires every row in §1 at TECH or an accepted, documented risk, plus §2 and §3 signed off by the
responsible owners. **Current position: not ready** (gaps 14, 17, 18, 20; MFA enforcement decision; legal items).
