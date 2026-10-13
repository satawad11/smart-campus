# Commercial Gap Analysis — V1 Pilot → Commercial V1

**Date:** 2026-10-13 · **Base:** `2e4605a` + this branch · Status legend: see [CURRENT_STATE_AUDIT](CURRENT_STATE_AUDIT.md).

## 1. Starting point

The repository is a **single-institution** platform. `docs/00-system-blueprint.md` §1 scopes it to
"a vocational college"; the only partitioning is the GLOBAL/SITE/ZONE scope model inside one institution.
There is **no** tenant, organization, license, entitlement, release-channel or fleet concept anywhere in the
backend, frontend, AI service or schema (`grep -i "tenant|organization_id|license"` → no code hits) — VERIFIED.

## 2. Deciding constraint: the tenancy model needs human approval

PROJECT_RULES §2/§17 require an `ARCHITECTURE_CHANGE_REQUEST` before architecture-affecting work. Adding
tenancy to the campus platform is architecture-affecting whichever way it is done, so it is raised as
[ACR-COM-01](../commercial/ACR-COM-01-TENANCY-MODEL.md) and **not implemented** until a human decides.

| Option | What it means | Fit |
|---|---|---|
| **A. Shared multi-tenant campus app** | `tenant_id` on ≈45 tables, tenant-aware scopes, storage prefixes, channels, queues, caches, reports; many schools' biometric data in one database | Highest effort and blast radius; concentrates Highly Sensitive biometric data of every customer; contradicts "process at the edge" |
| **B. Isolated instance per institution + multi-tenant Central Management Plane** (recommended) | Today's platform is deployed once per institution (on-premises or a dedicated VM). A separate, small central plane (organizations, licenses, fleet inventory, versions, support) is multi-tenant but holds **no biometric or student data** | Physical isolation for sensitive data; reuses the approved V1 unchanged; matches Hybrid Edge + Central Management |
| C. Database-per-tenant in one app | One Laravel install switching connections per tenant | Medium effort; still central biometric custody; complex migrations/backups |

### Work that is valid under every option (started without waiting)
- **License verification inside the campus instance** (COM-02): any option needs an instance to prove its
  entitlements offline.
- **Node management inside the campus instance** (COM-03): edge nodes talk to the campus backend in A, B and C.
- **Monitoring, alerts, diagnostics, export/offboarding** (COM-05/06 parts that live in the instance).
- Installer/updater hardening (COM-04), security and PDPA documentation (Phase 4), packaging docs (Phase 7).

## 3. Gap matrix

| Req | Exists today | Gap | Status | Blocked by |
|---|---|---|---|---|
| **COM-01** Tenant/organization management | Sites/zones only | Everything | NOT STARTED | ACR-COM-01 |
| COM-01 tenant context from verified token, no body trust | Session/Sanctum identity; node tokens bound to node | Tenant dimension | NOT STARTED | ACR-COM-01 |
| COM-01 cross-tenant tests, super-admin audited access | Cross-**site** negatives exist (`DataScopeEnforcementTest`) | Tenant equivalents | NOT STARTED | ACR-COM-01 |
| **COM-02** Signed license, offline validation | — | Ed25519 signed payload, verify with embedded public key | IN PROGRESS (this branch) | — |
| COM-02 plan/feature entitlements, camera/node limits | Feature flags (V1-locked modules) | Entitlement layer that can only **narrow** what flags allow | IN PROGRESS | — |
| COM-02 expiry/renewal/grace, revocation | — | Grace period, signed revocation list, never deletes or blocks export | IN PROGRESS | — |
| COM-02 issuance with private key kept off-repo | — | Issuer CLI reading the key from outside the repository | IN PROGRESS | Production key custody = human |
| COM-02 audit history | AuditLog infrastructure | License events | IN PROGRESS | — |
| **COM-03** Node registry, unique identity | `ai_nodes` (uuid, node_code unique) | — | VERIFIED | — |
| COM-03 secure enrollment | Admin creates node + one-time credential (`AiNodeCredentialService`), token hashed | One-time enrollment code / bootstrap flow | PARTIAL | — |
| COM-03 heartbeat + health metrics | CPU/RAM/GPU/temp/queue in `ai_node_health_logs` | Disk, FPS, dropped frames, software/model version | PARTIAL | — |
| COM-03 camera-to-node assignment | `cameras.ai_node_id`, config sync | — | VERIFIED | — |
| COM-03 versioned config, validation, rollback | Config derived live (`AiNodeConfigService`), no versions | Revisioned config + rollback | NOT STARTED | — |
| COM-03 authorized remote restart | — | Allowlisted command queue, audit, timeout, result; **no remote shell** | NOT STARTED | — |
| COM-03 model compatibility validation | AI model registry (FACE-001) | Node-reported model vs registry check | NOT STARTED | B5 for real models |
| COM-03 revocation + credential rotation | Credential revoke/issue API | — | VERIFIED (REPORTED tests) | — |
| COM-03 offline queue + idempotent delivery | Durable SQLite buffer (CAM-012), event_uuid idempotency (EVT-004, A-07) | — | VERIFIED | — |
| **COM-04** Repeatable install, preflight | `pilot-host-preflight.sh`, `pilot:preflight`, compose overlay | Generic (non-pilot) installer, env separation, release versioning | PARTIAL | — |
| COM-04 upgrade/rollback, backup before upgrade | Runbooks; image retag rollback | Scripted upgrade with pre-upgrade backup + migration check | PARTIAL | — |
| COM-04 versioned releases + notes | Tags only; runbook points at a stale tag (R-01) | Release process, CHANGELOG | GAP | Human release approval |
| **COM-05** Node offline / camera / backup alerts | AI_NODE_DOWN, CAMERA_DOWN, BACKUP_FAILED, health alerts | Queue backlog, disk threshold, license expiry | PARTIAL | — |
| COM-05 audit log viewer, incidents UI | API only (A-17) | UI | GAP | — |
| COM-05 support diagnostics without biometric data | `/health/*`, preflight | Redacted diagnostics bundle | GAP | — |
| **COM-06** Customer portal | — | Lives in the central plane under option B | NOT STARTED | ACR-COM-01 |
| COM-06 data export / offboarding guidance | Report exports; student import | Full institution export + offboarding runbook | GAP | — |
| **COM-07** Versioned API | `/api/v1`, `/internal/v1` | Third-party integration auth (tokens/scopes), rate limits, OpenAPI | GAP | Integration partner + legal basis for student sync |
| Phase 4 security/PDPA | Strong V1 controls (RBAC, scope, audit, TLS, CSP, lockout, MFA API) | MFA enrollment UI (A-15), data inventory/flow map, DSR workflow, DPA, privacy notice, backup encryption | PARTIAL | Legal review |
| Phase 5 AI quality | Mock pipeline, harness, thresholds approved for pilot | Production model, license, provenance, metrics | BLOCKED | B5 |
| Phase 7 packaging docs | Pilot docs | `docs/commercial/*` set | NOT STARTED → in progress | — |

## 4. Release-gate view (Definition of Done §15)

| Gate | Status |
|---|---|
| Existing core passes acceptance tests | Automated suites green (see EXISTING_SYSTEM_COMPLETION); physical/pilot parts BLOCKED |
| Tenant isolation security tests | BLOCKED — ACR-COM-01 |
| License enforcement backend tests | In progress |
| Node enrollment + config management working | PARTIAL |
| Production install + upgrade/recovery tested | NOT VERIFIED on a real host |
| AI model commercial rights + evaluation | BLOCKED — B5 |
| Backup/restore criteria | REPORTED pass in rehearsal; retention approval BLOCKED |
| PDPA + security review by owners | BLOCKED — human/legal |
| Pilot acceptance | BLOCKED — PILOT-011 |
| Human release approval | BLOCKED |

**Commercial V1 is not ready for sale.** Several mandatory gates are human or physical by nature.
