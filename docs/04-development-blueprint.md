# AI Smart Campus Security & Access Platform — Development Blueprint V1

## 1. Monorepo

```text
smart-campus/
├── apps/
│   ├── backend/
│   ├── frontend/
│   └── ai-service/
├── infrastructure/
│   ├── nginx/
│   ├── postgres/
│   └── monitoring/
├── docker/
├── docs/
├── contracts/
├── prompts/
├── scripts/
├── tests/
├── docker-compose.yml
└── README.md
```

## 2. Environments

- Development
- Staging
- Pilot
- Production

Production data is not used casually in local development.

## 3. Docker Services

Initial:
- nginx
- frontend
- backend
- queue
- scheduler
- websocket
- ai-service
- postgres
- redis
- objectstore (S3-compatible; SeaweedFS since 2026-11, previously MinIO)

Future:
- mqtt
- prometheus
- grafana
- loki

## 4. Backend Architecture

Laravel modular monolith.

Modules:
- Auth
- User
- RBAC
- Identity
- Academic
- Student
- Face
- Camera
- AI
- Event
- Access
- Monitoring
- Reporting
- Audit
- System

Typical module:
- Controllers
- Models
- Services
- Repositories
- Requests
- Resources
- DTOs
- Policies
- Events
- Listeners
- Jobs
- Tests

Controllers remain thin.

## 5. Frontend Architecture

Vue 3 structure:
- modules
- components
- layouts
- router
- stores
- services
- composables
- utils

Recommended modules:
- auth
- dashboard
- monitor
- students
- faces
- cameras
- ai-nodes
- events
- reports
- users
- roles
- audit
- system

## 6. AI Service Architecture

FastAPI:
- api
- core
- detection
- alignment
- quality
- liveness
- embeddings
- recognition
- streams
- workers
- models
- schemas

AI service provides perception, not business authorization.

## 7. Redis Uses

- cache
- queue
- websocket/realtime
- rate limiting
- recognition cooldown
- distributed locks

Redis is not a permanent event store.

## 8. Object Storage Buckets

- person-photos
- face-samples
- event-snapshots
- report-exports

## 9. CI

On push/PR:
- backend lint/format
- backend tests
- frontend lint/test/build
- AI tests
- dependency/security scan
- Docker build

## 10. CD

Develop -> development environment  
Release candidate -> staging  
Approved pilot -> pilot  
Approved release -> production

No production deploy directly from a developer workstation.

## 11. Testing Strategy

- Unit tests
- Feature/API tests
- Integration tests
- Security tests
- Architecture/contract tests
- AI dataset evaluation
- Load tests
- UAT
- Failover/recovery tests

## 12. Observability

Health endpoints for Laravel and AI service.

Measure:
- API latency
- error rate
- queue depth
- database connections
- GPU use
- recognition latency
- event processing latency
- WebSocket delivery latency
- camera FPS/availability

## 13. Mock Mode

Support:
`AI_MODE=mock`

Mock outcomes:
- MATCH
- UNKNOWN
- LOW_CONFIDENCE
- LOW_QUALITY
- LIVENESS_FAIL

This allows frontend/backend work without real AI hardware.

## 14. Development Sequence

Foundation:
- repo
- Docker
- Laravel
- Vue
- FastAPI
- PostgreSQL/pgvector
- Redis
- object storage
- Nginx
- health
- CI
- demo data

Then Module 01-07 in approved order.

## 15. Definition of Done

A task is done only when:
- code completed
- tests added
- tests pass
- contract respected
- permissions/scope verified
- audit added if needed
- error handling complete
- no secret committed
- implementation report delivered
