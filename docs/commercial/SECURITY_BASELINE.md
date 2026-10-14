# Security and Privacy Baseline (Phase 4 checklist)

**Date:** 2026-10-13. Status per control: **TECH** = technical control implemented and tested · **PARTIAL** ·
**GAP** · **ORG** = organisational control (process, people) · **LEGAL** = needs legal review.
"Implemented" never means "compliant": see §4.

## 1. Technical controls

| # | Control | Status | Evidence / gap |
|---|---|---|---|
| 1 | Least privilege / RBAC with permission codes | TECH | `RequirePermission`, `contracts/permissions.yaml`; open decisions A-14 (dashboard), A-27 (SYSTEM_ADMIN = `*`) |
| 2 | Data scope GLOBAL / SITE / ZONE on REST and realtime | TECH | `DataScopeEnforcementTest`, `BroadcastChannelScopeTest` (A-05 fixed) |
| 3 | MFA for administrators | TECH | Enrollment UI (`33eeb92`); SUPER_ADMIN and SYSTEM_ADMIN must enrol before any permission-protected API or realtime channel (`AUTH_MFA_REQUIRED_ROLES`; `MfaEnforcementTest`) |
| 4 | Password policy, lockout, login throttle | TECH | AUTH-006, `throttle:login` (A-03) |
| 5 | Session management, forced logout, forced password change | TECH | AUTH-012; server-side forced change (A-04) |
| 6 | Credential rotation | PARTIAL | AI-node credentials: issue/revoke API. App secrets: manual per `docs/security/SECRETS-STRATEGY.md` |
| 7 | TLS in transit | PARTIAL | TLS gateway in the pilot overlay; node→backend may be HTTP on LAN (PDPA G-7) |
| 8 | Encryption at rest | PARTIAL | Camera credentials encrypted (Laravel `Crypt`); MFA secrets encrypted. DB/MinIO/backup volumes rely on host disk encryption (GAP: not enforced by preflight) |
| 9 | Secrets management | PARTIAL | `.env` outside repo, mandatory pilot secrets; no vault. License private key never in product (COM-02) |
| 10 | Audit logging | TECH | `AuditService` with sensitive-key scrubbing; biometric, admin, licence actions audited |
| 11 | Rate limiting | TECH | login, MFA throttled; `/api/v1` limited per user/IP (`API_RATE_LIMIT_PER_MINUTE`, default 300; `ApiRateLimitTest`); `/internal/v1` excluded |
| 12 | Secure file upload | PARTIAL | type/size validation on student import, photos, face images; no malware scanning |
| 13 | Database least privilege | TECH | `docs/security/LEAST-PRIVILEGE-ROLES.sql`, preflight warns on owner user |
| 14 | Backup encryption | TECH (opt-in) | `BACKUP_ENCRYPTION_KEY` → libsodium secretstream, drill verifies by decrypting, `backup:decrypt`; key custody = ops (vault, apart from backups) |
| 15 | Restore testing | PARTIAL | rehearsal restores into an empty DB pass (REPORTED); none on a production host (BLOCKED) |
| 16 | Vulnerability / dependency scanning | PARTIAL | `composer audit` + `npm audit` in CI (advisory only); no container image scan |
| 17 | Dependency licence audit | GAP | not performed; required before sale (and for AI models: B5) |
| 18 | Threat model | GAP | not written; key threats known: stolen node token forging MATCH (A-18), insider misuse of biometric search, licence tampering (COM-02 mitigations) |
| 19 | Cross-tenant security tests | BLOCKED | depends on ACR-COM-01 |
| 20 | Biometric data minimisation and erasure | GAP | PDPA G-1 / G-2 |
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
