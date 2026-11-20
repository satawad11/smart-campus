# Module 07 — Security Hardening + Pilot Readiness

## Goal
Move from development-ready to pilot-ready Production V1.

## System Settings
Groups:
- GENERAL
- SECURITY
- FACE
- EVENT
- CAMERA
- STORAGE
- REPORT
- NOTIFICATION
- RETENTION
- FEATURE

## Feature Flags
Backend and frontend must enforce flags.

Production V1 keeps:
- DOOR false
- VISITOR false
- VEHICLE false by default — optional since ACR-VEH-01 (approved 2026-11-12)
- ALPR false by default — optional since ACR-VEH-01 (approved 2026-11-12)
- AI_ASSISTANT false

## Security Hardening
- HTTPS/TLS
- security headers
- CORS allowlist
- rate limits
- input validation
- object/function authorization tests
- admin MFA
- secure sessions
- secret management
- encrypted camera credentials
- least-privilege DB/service accounts
- segmented network

## Backup
Back up:
- PostgreSQL
- object storage as required
- configuration
- critical audit/biometric data according to policy

Track backup jobs and verify checksums.

A backup is not trusted until restore is tested.

## Retention
Separate retention for:
- events
- snapshots
- camera health
- AI health
- reports
- login logs
- face/audit according to policy

## Monitoring
Health:
- backend
- database
- Redis
- storage
- websocket
- AI service
- AI nodes
- cameras
- queues
- scheduler

Alerts:
- backend/database/Redis down
- camera down
- AI node down
- high GPU/disk/queue
- failed backup
- repeated login failures

## Data Governance
Classify data and define:
- owner
- purpose
- access role
- retention
- sharing
- backup

Biometric data is highly sensitive.

## Pilot
Recommended:
- 1 site
- 1 gate
- 1-2 cameras
- 1 AI node
- 50-100 students
- 2-5 operators

Test:
- day/night/backlight
- glasses
- masks
- different distances
- crowd
- network failure
- camera failure

Measure:
- match accuracy
- false match
- false reject
- unknown rate
- review rate
- latency
- uptime
- event loss
- review time

## UAT Roles
- System Admin
- Discipline
- Security
- Registrar
- Executive

## Runbooks
Required:
- deployment
- rollback
- restore
- disaster recovery
- incident handling
- Smart Gate pilot (optional module, ACR-VEH-01): `docs/pilot/SMART-GATE-PILOT-RUNBOOK.md`

## Production V1 Gate
Production V1 requires human go/hold review after pilot and UAT.
