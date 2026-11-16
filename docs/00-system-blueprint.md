# AI Smart Campus Security & Access Platform — System Blueprint V1

## 1. Purpose

Build a practical Smart Campus security and student-monitoring platform for a vocational college. Production V1 focuses on student face recognition, academic-status lookup, realtime monitoring, historical search, reports, audit, and operational readiness.

The platform is designed to expand later to door access control, visitors/parents, vehicles, ALPR, Smart Gate, AI Assistant, anomaly detection, and advanced analytics without redesigning the core identity/event model.

## 2. Production V1 Scope

Enabled:
- Person/Student master data
- Academic structure
- User/RBAC
- Face enrollment
- Face recognition
- Liveness/PAD foundation
- Camera management
- AI node management
- RTSP processing
- Event engine
- Policy engine
- Live monitor
- Manual review
- Historical search
- Reports
- Audit
- Backup/restore
- Monitoring/security hardening

Disabled by default:
- Physical door actuation
- Visitor/parent pass management
- Vehicle management
- ALPR
- AI Assistant

## 3. Core Principle

`AI identifies -> Backend decides -> Policy controls -> Human reviews exceptions -> Audit records`

For future physical access:
`AI identifies -> Backend decides -> Policy controls -> Controller acts -> Sensor confirms -> Audit records`

AI must never directly open a door or impose a disciplinary decision.

## 4. Core Domains

- Person
- StudentProfile
- User
- Role/Permission
- FaceProfile
- Camera
- AINode
- AccessEvent
- AccessDecision
- AccessPolicy
- Report
- AuditLog

Future:
- Door
- Controller
- Visitor
- VisitorPass
- Vehicle
- ALPR
- AI Assistant

## 5. Identity Model

`Person` is the master identity. Person types:
- STUDENT
- TEACHER
- STAFF
- PARENT
- VISITOR
- CONTRACTOR
- ALUMNI
- OTHER

`StudentProfile` is one academic profile linked to a Person.

`User` is an optional login account. Students are not automatically Users.

Face profiles always link to `person_id`, not student profile IDs.

## 6. Student Academic Status

Required values:
- ACTIVE
- LEAVE
- SUSPENDED
- WITHDRAWN
- GRADUATED
- EXPELLED
- UNKNOWN

Academic status must come from trusted registry data. AI must not infer academic status from a face.

## 7. Access Decisions

Required values:
- ALLOW
- REVIEW
- DENY
- UNKNOWN

Event and Decision are separate concepts.

Example:
- Event: FACE_RECOGNIZED
- Decision: REVIEW
- Reason: STUDENT_SUSPENDED

## 8. Camera Recognition Flow

`RTSP -> Detection -> Quality -> Liveness -> Alignment -> Embedding -> Vector Search -> MATCH/REVIEW/UNKNOWN -> person_uuid -> Backend lookup -> Policy -> Event + Decision -> Realtime Monitor`

Recognition thresholds are calibrated from pilot data, not chosen arbitrarily.

## 9. Realtime Monitor

Target users: discipline/security staff.

Must show:
- selected live camera feeds
- realtime event feed
- recognized student card
- academic status
- decision
- review queue
- unknown-person events
- cameras online/offline
- AI node health
- daily KPIs

Detailed disciplinary status must only be visible to authorized internal roles.

## 10. Historical Integrity

History is append-oriented:
- student status changes create status-history records;
- manual event review creates a new AccessDecision;
- audit logs are not silently rewritten;
- events retain original occurred-at time.

## 11. Reports

Required periods:
- Daily
- Weekly
- Monthly
- Yearly
- Academic year
- Custom range

Filters:
- person/student
- department/program/classroom
- academic status
- site/zone/camera
- event type
- decision
- date/time

Exports:
- XLSX
- CSV
- PDF
- Print

## 12. Site Hierarchy

Preferred hierarchy:

`Site -> Zone -> Camera -> Future Door -> Future Controller -> Policy`

AI Nodes are assigned cameras and may serve one or more sites according to deployment.

## 13. Security

Data classification:
- Public
- Internal
- Confidential
- Highly Sensitive

Face embeddings and biometric samples are Highly Sensitive.

Required controls include:
- TLS
- RBAC
- scope authorization
- admin MFA
- password/session controls
- encrypted secrets
- audit
- backup/restore
- camera-network segmentation
- service authentication
- rate limits
- secure object storage

## 14. Storage Architecture

PostgreSQL:
- relational master data
- event metadata
- decisions
- reports
- audit

pgvector:
- face embeddings

Redis:
- cache
- queue
- realtime
- cooldown
- locks

S3-compatible storage:
- person photos
- face samples
- event snapshots
- report exports

NVR/VMS:
- CCTV recordings

The application does not store continuous CCTV video in PostgreSQL.

## 15. Technology Stack

Frontend:
- Vue 3
- Vite
- Tailwind CSS
- Pinia
- Vue Router
- Axios

Backend:
- Laravel
- REST API
- Laravel Reverb
- Queue
- Scheduler

AI:
- Python
- FastAPI
- OpenCV
- ONNX Runtime
- GPU acceleration later as benchmarked

Data:
- PostgreSQL
- pgvector
- Redis
- S3-compatible storage

Infrastructure:
- Docker Compose
- Nginx

## 16. Module Sequence

Module 01: Authentication + User + RBAC  
Module 02: Person + Student + Academic Structure  
Module 03: Face Enrollment + Face AI Foundation  
Module 04: Camera + AI Node + RTSP  
Module 05: Event Engine + Policy + Live Monitor  
Module 06: Historical Search + Reporting  
Module 07: Security Hardening + Pilot Readiness

## 17. Pilot Target

Recommended pilot:
- 1 site
- 1 gate/area
- 1-2 cameras
- 1 AI node
- 50-100 students
- 2-5 operators

Pilot measurements:
- true match
- false match
- false non-match
- unknown rate
- review rate
- liveness failures
- recognition latency
- end-to-end latency
- event loss
- camera uptime
- AI node uptime

## 18. Future Phases

Phase 2:
Door access control + controller + MQTT

Phase 3:
Visitor/parent management

Phase 4:
Vehicle + ALPR

Phase 5:
Face+plate Smart Gate + AI Assistant + anomaly/analytics

Future phases must not be activated before human approval.

Approved by the owner on 2026-11-16 (docs/decisions/OWNER-DECISIONS-2026-11-16.md): all phases above, built one module
at a time, each after its spec is accepted. Feature flags stay off until the module is complete.
