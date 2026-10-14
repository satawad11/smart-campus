# Known Limitations (Commercial V1 candidate, 2026-10-13)

| Area | Limitation | Reference |
|---|---|---|
| Biometrics | No production face detection/embedding/PAD model; only `AI_MODE=mock`. Live recognition impossible; preflight FAILs on purpose | B5 |
| Biometrics | Biometric erasure is CLI-only (`face:purge`); no retention schedule triggers it automatically; backup copies persist until backups expire | PDPA G-1/G-2 |
| Tenancy | Single institution per instance; central management plane not built | ACR-COM-01 |
| Licensing | Deters casual misuse; not DRM against a customer with root on their own server. Feature entitlements gate only new face enrollments. No licence UI (API/CLI only) | LICENSE_MODEL §7 |
| Licensing | Two simultaneous additions can overshoot a limit by one (alerted hourly) | LICENSE_MODEL §4 |
| Cameras | "Snapshot" returns a labelled mock placeholder | A-21 |
| Node | No remote restart / versioned config / rollback yet | COM-03b/c |
| Node | Offline buffer unencrypted on node disk (use disk encryption) | PDPA G-3 |
| Node | Edge kit not yet run on real hardware/cameras | R-09 |
| Security | MFA available to all users but not enforced for admins; no general API rate limit; no malware scan of uploads; backups not encrypted by the app | SECURITY_BASELINE |
| Security | A stolen node token could submit MATCH events without a server-side threshold re-check | A-18 |
| UI | No UI for audit logs, incidents, sessions, login history, notifications | A-17 |
| RBAC | SECURITY_OFFICER dashboard 403; SYSTEM_ADMIN = `*` | A-14, A-27 |
| Reporting | Summary refresh from ~1M raw events untested at scale | A-22 |
| Ops | `/health/database` takes ~16 s to fail when the DB is down | A-23 |
| Integrations | No third-party integration API (COM-07) | — |
