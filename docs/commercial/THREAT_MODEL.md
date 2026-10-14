# Threat Model — Campus Instance (STRIDE, 2026-10-14)

**Scope:** one campus instance (ACR-COM-01 option B): browser ↔ nginx TLS ↔ Laravel API/Reverb ↔ PostgreSQL / Redis /
MinIO; AI node ↔ `/internal/v1`; cameras (RTSP) ↔ AI node; backups → off-host target; vendor licence files.
**Assets:** biometric data (embeddings, face images — Highly Sensitive, minors), student records, access events,
admin accounts, node credentials, licence private key (vendor side), backups.

| # | Threat (STRIDE) | Entry point | Existing controls | Residual risk / next step |
|---|---|---|---|---|
| T1 | **S**poofing a staff user (password guessing, stolen session) | `/api/v1/auth/*` | Lockout, login throttle, `/api/v1` rate limit, admin MFA required, session revocation, secure cookies | Non-admin roles without MFA: consider MFA for DISCIPLINE/SECURITY officers |
| T2 | **S**poofing an AI node (stolen bearer token) to forge recognition events | `/internal/v1/*` | Per-node hashed credentials, revocation/rotation, node may only use its own cameras/config, **server re-checks MATCH threshold (A-18)**, HTTPS check on node | A stolen token can still submit UNKNOWN/REVIEW noise: alert on anomalous event rates (future) |
| T3 | **S**poofing a face (photo/screen) | camera | PAD interface; preflight FAILs while PAD is mock | B5: no production PAD model yet; pilot stays non-biometric |
| T4 | **T**ampering with licence state | DB, config | Signed envelope re-verified, clock high-water mark, revocation lists | Root on own server can patch code (accepted; contractual) |
| T5 | **T**ampering with events/audit history | DB | Append-only decisions, audit log, DB least-privilege roles | DB superuser can alter rows: restrict DB superuser access on hosts |
| T6 | **R**epudiation of admin actions | API/CLI | Audit log of admin, biometric, licence, node-command actions; audit viewer | CLI actions attribute to "system": record operator name in tickets |
| T7 | **I**nformation disclosure: biometric data exfiltration | API, DB, backups | Embeddings never returned by API, private buckets, scope enforcement, encrypted backups (opt-in), purge after revocation | Enable backup encryption + disk encryption on every host (install guide) |
| T8 | **I**nformation disclosure across sites | REST/realtime | GLOBAL/SITE/ZONE scope on REST and channels (A-05) | Cross-tenant N/A under option B |
| T9 | **I**nformation disclosure via support bundles/logs | support | Redacted diagnostics bundle; sensitive keys stripped from audit | Log lines from third-party libraries: redaction is pattern-based |
| T10 | **D**enial of service: API flooding | nginx/API | Rate limits, health probes, queue backlog alert | No WAF; host firewall allows campus network only |
| T11 | **D**enial of service: disk exhaustion (buffer, backups, logs) | node/server | Node + server disk alerts, retention jobs, backup pruning | — |
| T12 | **E**levation of privilege: role misassignment | CLI/UI | Role assignment CLI audited; permission codes; forced password change | Periodic access review (organisational) |
| T13 | **E**levation via remote node commands | admin API | Allowlisted enum only, no shell, `ai_node.manage` + scope, audit, timeout | — |
| T14 | Licence private key theft (vendor) | vendor workstation | Key outside repo, user-only ACL | **Move offline** (owner task, D8) |
| T15 | Supply chain (images, dependencies) | build | Lockfiles, `composer/npm audit` advisory in CI | Pin `minio:latest`; add image scanning; licence review (DEPENDENCY_LICENSES) |

Review this model at every release and after any architecture change.
