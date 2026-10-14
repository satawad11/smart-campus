# Known Limitations (release candidate v1.1.0-rc1, updated 2026-10-14)

| Area | Limitation | Reference |
|---|---|---|
| Biometrics | No production face detection/embedding/PAD model; only `AI_MODE=mock`. Live recognition impossible; preflight FAILs on purpose | B5 |
| Biometrics | Biometric erasure is CLI-only (`face:purge`); no retention schedule triggers it automatically; backup copies persist until backups expire | PDPA G-1/G-2 |
| Tenancy | Single institution per instance; central management plane not built | ACR-COM-01 |
| Licensing | Deters casual misuse; not DRM against a customer with root on their own server. Feature entitlements gate only new face enrollments | LICENSE_MODEL §7 |
| Licensing | Two simultaneous additions can overshoot a limit by one (alerted hourly) | LICENSE_MODEL §4 |
| Cameras | "Snapshot" returns a labelled mock placeholder | A-21 |
| Node | Remote commands limited to RESYNC_CONFIG / RESTART_SERVICE; versioned settings cover intervals and target FPS only (target FPS applies to cameras started afterwards); managed in the AI node Operations tab | COM-03b/c |
| Node | Offline buffer unencrypted on node disk (use disk encryption) | PDPA G-3 |
| Node | Edge kit not yet run on real hardware/cameras | R-09 |
| Security | MFA required only for SUPER_ADMIN/SYSTEM_ADMIN; no malware scan of uploads; backup encryption is opt-in (set `BACKUP_ENCRYPTION_KEY`) | SECURITY_BASELINE |
| UI | No UI yet for login history, notifications, or an admin view of other users' sessions (audit log, incidents and own sessions have UIs) | A-17 |
| RBAC | SYSTEM_ADMIN holds `*` like SUPER_ADMIN (separation of duties) | A-27 |
| Reporting | Summary refresh from ~1M raw events untested at scale | A-22 |
| Ops | `/health/database` takes ~16 s to fail when the DB is down | A-23 |
| Integrations | No third-party integration API (COM-07) | — |
