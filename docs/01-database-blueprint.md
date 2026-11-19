# AI Smart Campus Security & Access Platform — Database Blueprint V1

## 1. Database Platform

Use:
- PostgreSQL
- pgvector
- pg_trgm
- Redis for ephemeral state
- S3-compatible storage for media

Timestamps use `TIMESTAMPTZ`. Database stores UTC. Frontend displays `Asia/Bangkok`.

## 2. Key Design Rules

- Internal PK: BIGINT/BIGSERIAL.
- External identifier: UUID.
- Public API should use UUID.
- Historical tables are append-oriented.
- Do not delete event/audit/status history through normal workflows.
- Face profiles reference Person.

## 3. Core Tables

### persons
- id
- uuid
- person_code nullable unique
- person_type
- first_name
- last_name
- display_name nullable
- gender nullable
- primary_photo nullable
- status
- created_at
- updated_at
- deleted_at nullable

### departments
- id
- uuid
- code
- name
- description nullable
- status
- timestamps

### programs
- id
- uuid
- department_id
- code
- name
- education_level
- status
- timestamps

### classrooms
- id
- uuid
- department_id
- program_id nullable
- code
- name
- academic_year
- year_level
- status
- timestamps

### student_profiles
- id
- uuid
- person_id unique
- student_code unique
- department_id
- program_id nullable
- classroom_id nullable
- education_level
- year_level
- academic_status
- status_start_date nullable
- status_end_date nullable
- admission_year nullable
- graduation_year nullable
- metadata JSONB nullable
- timestamps

### student_status_histories
- id
- uuid
- student_profile_id
- old_status nullable
- new_status
- effective_from
- effective_to nullable
- reason nullable
- source
- changed_by_user_id nullable
- created_at

Sources:
- MANUAL
- IMPORT
- API
- SIS_SYNC

## 4. Authentication/RBAC Tables

### users
- id
- uuid
- person_id nullable
- username unique
- email nullable unique
- password
- status
- mfa_enabled
- mfa_secret_encrypted nullable
- failed_login_count
- locked_until nullable
- last_login_at nullable
- password_changed_at nullable
- must_change_password
- created_at
- updated_at
- deleted_at nullable

User status:
- ACTIVE
- LOCKED
- DISABLED
- EXPIRED

### roles
- id
- uuid
- code unique
- name
- description nullable
- system_role boolean
- status
- timestamps

### permissions
- id
- code unique
- module
- name
- description nullable

### role_permissions
- role_id
- permission_id
- PK(role_id, permission_id)

### user_roles
- id
- user_id
- role_id
- site_id nullable
- zone_id nullable
- valid_from nullable
- valid_until nullable
- created_at

### user_permissions
- user_id
- permission_id
- effect ALLOW/DENY
- created_at

### user_sessions
- id
- uuid
- user_id
- session_id
- ip_address
- user_agent
- device_name nullable
- login_at
- last_activity_at
- logout_at nullable
- revoked_at nullable

### login_logs
- id
- user_id nullable
- username
- success
- failure_reason nullable
- ip_address
- user_agent
- created_at

## 5. Face AI Tables

### ai_models
- id
- uuid
- model_type
- name
- version
- provider nullable
- embedding_dimension nullable
- distance_metric nullable
- threshold_high nullable
- threshold_low nullable
- status
- model_checksum nullable
- deployed_at nullable
- timestamps

Model types:
- FACE_DETECTION
- FACE_RECOGNITION
- LIVENESS

### face_profiles
- id
- uuid
- person_id
- recognition_model_id
- embedding vector(N)
- embedding_dimension
- quality_score nullable
- status
- enrolled_by_user_id nullable
- enrolled_at
- revoked_at nullable
- timestamps

Statuses:
- ACTIVE
- PENDING
- REVOKED
- RE_ENROLL_REQUIRED

### face_samples
- id
- uuid
- face_profile_id
- image_path
- pose
- quality_score nullable
- blur_score nullable
- brightness_score nullable
- face_size_score nullable
- yaw nullable
- pitch nullable
- roll nullable
- accepted
- rejection_reason nullable
- created_at

## 6. Site/Camera/AI Tables

### sites
- id
- uuid
- code
- name
- address nullable
- timezone
- status
- timestamps

### zones
- id
- uuid
- site_id
- parent_zone_id nullable
- code
- name
- zone_type
- status
- timestamps

### ai_nodes
- id
- uuid
- site_id nullable
- node_code
- name
- hostname
- ip_address
- api_url
- hardware_type
- cpu_model nullable
- gpu_name nullable
- gpu_memory_mb nullable
- max_camera nullable
- status
- last_seen_at nullable
- timestamps

### cameras
- id
- uuid
- site_id
- zone_id
- ai_node_id nullable
- camera_code
- name
- camera_type
- ip_address nullable
- stream_protocol
- rtsp_url_encrypted
- username_encrypted nullable
- password_encrypted nullable
- onvif_url nullable
- face_detection_enabled
- face_recognition_enabled
- liveness_enabled
- snapshot_enabled
- status
- last_seen_at nullable
- timestamps
- deleted_at nullable

### camera_health_logs
- id
- camera_id
- status
- fps nullable
- latency_ms nullable
- dropped_frames nullable
- resolution nullable
- brightness_score nullable
- blur_score nullable
- reconnect_count nullable
- recorded_at

### ai_node_health_logs
- id
- ai_node_id
- cpu_usage nullable
- ram_usage nullable
- gpu_usage nullable
- gpu_memory_usage nullable
- temperature nullable
- active_camera_count nullable
- queue_depth nullable
- status
- recorded_at

## 7. Event/Policy Tables

### event_types
- id
- code unique
- name_th
- name_en
- category
- severity
- active

### access_events
- id
- uuid unique
- person_id nullable
- site_id
- zone_id nullable
- camera_id nullable
- ai_node_id nullable
- event_type
- occurred_at
- received_at nullable
- face_score nullable
- liveness_score nullable
- quality_score nullable
- track_id nullable
- snapshot_path nullable
- metadata JSONB nullable
- created_at

### access_decisions
- id
- uuid
- event_id
- person_id nullable
- decision
- reason_code
- access_policy_id nullable
- decision_source
- decided_by_user_id nullable
- reason_text nullable
- created_at

Decisions:
- ALLOW
- REVIEW
- DENY
- UNKNOWN

Decision source:
- SYSTEM
- POLICY
- OFFICER

### access_policies
- id
- uuid
- name
- subject_type nullable
- subject_status nullable
- site_id nullable
- zone_id nullable
- event_type nullable
- gate_id nullable (GATE-005; restrict delete)
- gate_mode nullable (GATE-005; GateMode)
- match_result nullable (GATE-005; GateMatchResult)
- vehicle_status nullable (GATE-005; VehicleStatus)
- decision
- reason_code
- priority
- enabled
- valid_from nullable
- valid_until nullable
- timestamps

Every dimension is a wildcard when null. The four Smart Gate dimensions (ACR-VEH-01 Phase 5 item 8)
are null in an ordinary event's AccessContext, so a policy that sets any of them applies only to a
gate pass (`AccessContextResolver::resolvePass`).

### event_notes
- id
- event_id
- user_id
- note
- created_at

## 8. Notifications/Audit/Settings

### notifications
- id
- uuid
- user_id nullable
- site_id nullable
- zone_id nullable
- type
- severity
- title
- message
- event_id nullable
- read_at nullable
- created_at

### audit_logs
- id
- uuid
- user_id nullable
- action
- module
- entity_type
- entity_id
- old_values JSONB nullable
- new_values JSONB nullable
- ip_address nullable
- user_agent nullable
- request_id nullable
- created_at

### system_settings
- id
- setting_group
- setting_key unique
- setting_value JSONB
- is_secret
- updated_by_user_id nullable
- updated_at

## 9. Import/Sync Tables

### import_jobs
- id
- uuid
- type
- file_path
- status
- total_rows
- valid_rows
- invalid_rows
- success_rows
- failed_rows
- created_by
- started_at nullable
- completed_at nullable
- created_at

### import_errors
- id
- import_job_id
- row_number
- field
- value nullable
- error_code
- error_message
- created_at

### external_sources
- id
- name
- type
- base_url nullable
- status
- last_sync_at nullable

### sync_jobs
- id
- source_id
- sync_type
- status
- records_processed
- started_at
- completed_at nullable

## 10. Reporting/System Tables

### report_exports
- id
- uuid
- user_id
- report_type
- format
- filters JSONB
- status
- file_path nullable
- expires_at nullable
- started_at nullable
- completed_at nullable
- created_at

### scheduled_reports
- id
- uuid
- name
- report_type
- schedule_type
- schedule_config JSONB
- filters JSONB
- format
- enabled
- created_by
- last_run_at nullable
- next_run_at nullable

### system_alerts
- id
- uuid
- alert_type
- severity
- source_type
- source_id nullable
- status
- message
- opened_at
- acknowledged_at nullable
- resolved_at nullable

### incidents
- id
- uuid
- incident_type
- severity
- title
- description
- related_event_id nullable
- status
- reported_by
- assigned_to nullable
- opened_at
- resolved_at nullable

## 11. Index Strategy

Minimum:
- persons(person_code)
- persons(person_type, status)
- student_profiles(student_code)
- student_profiles(academic_status)
- student_profiles(department_id)
- student_profiles(classroom_id)
- access_events(occurred_at)
- access_events(person_id, occurred_at DESC)
- access_events(camera_id, occurred_at DESC)
- access_events(site_id, occurred_at DESC)
- access_events(event_type, occurred_at DESC)

Use pg_trgm for Thai name search when required.

Use HNSW vector index only after the chosen model metric/dimension is confirmed.

## 12. Partitioning

Prepare `access_events` for monthly range partitioning when event volume requires it.

Do not prematurely partition every table.

## 13. Media Storage

Store object keys, not large image binary blobs.

Buckets:
- person-photos
- face-samples
- event-snapshots
- report-exports

Continuous CCTV remains in NVR/VMS.

## 14. Deletion Rules

Do not cascade-delete historical access events/decisions/audit records.

Use archive/disable/revoke flows.

## 15. Future Tables Reserved

Future, feature-disabled:
- doors
- controllers
- visitor_profiles
- visits
- visitor_passes

## 16. Vehicle Tables (ACR-VEH-01, VEH-002)

Approved optional module (`feature.vehicle`, off by default). Face stays the primary identity; a
vehicle is linked to persons, never to users or students.

### vehicles
- id
- uuid
- plate_number (canonical Thai plate text: Arabic digits, no spaces/dashes, e.g. `1กข1234`, `701234`)
- plate_province (ISO 3166-2:TH province code, e.g. `TH-10` = กรุงเทพมหานคร)

Plate and province rules are defined once in `contracts/thai-plates.json` (VEH-003) and implemented
in the backend (`App\Support\Vehicles\ThaiPlate`, `ThaiProvince`) and the AI service
(`app/plates/thai.py`); both test suites check the contract file.
- vehicle_type CAR | MOTORCYCLE | PICKUP | VAN | TRUCK | BUS | OTHER
- brand nullable
- model nullable
- color nullable
- status ACTIVE | INACTIVE | BLOCKED
- notes nullable
- created_by_user_id nullable
- timestamps
- deleted_at nullable

Unique `(plate_number, plate_province)` among rows where `deleted_at IS NULL`.

### vehicle_ownership_histories
- id
- uuid
- vehicle_id (restrict delete)
- person_id (restrict delete)
- relation OWNER | DRIVER | AUTHORIZED
- effective_from
- effective_to nullable
- reason nullable
- created_by_user_id nullable
- created_at

Append-oriented: a row is never rewritten or deleted. The only allowed change is closing it once by
setting `effective_to` (not earlier than `effective_from`). A vehicle may have several current links.

## 17. Smart Gate Tables (ACR-VEH-01 Phase 5, GATE-001)

Configuration for the face-first Smart Gate. Decision and notification only: no table here drives a
barrier (decision D5; actuation needs Phase 2 and its own ACR). Gate passes are written only by the
correlation service `App\Services\Gate\GateCorrelationService` (GATE-004), with `feature.vehicle` on.

### gates
- id
- uuid
- site_id (restrict delete)
- zone_id (restrict delete; must belong to `site_id`, enforced in `App\Models\Gate`)
- code
- name
- direction IN | OUT | BOTH (default BOTH)
- correlation_window_seconds (default 10; face and plate events of one pass must fall within it)
- mode FACE_PRIMARY (the only approved mode, decision D2: a plate alone never produces ALLOW)
- status ACTIVE | INACTIVE
- notes nullable
- created_by_user_id nullable
- timestamps
- deleted_at nullable

Unique `(site_id, code)` among rows where `deleted_at IS NULL`.

### gate_cameras
- id
- gate_id (cascade delete: configuration link)
- camera_id (restrict delete; unique, so a camera serves at most one gate and an event's camera
  resolves to one gate; must be in the gate's site, enforced in `App\Models\GateCamera`)
- role FACE | PLATE | BOTH (decision D3: separate face and plate cameras, or one camera for both)
- timestamps

### gate_passes (GATE-003)
One row per pass through a gate. It links the face event and/or the plate event the correlation
service (GATE-004) put together; the `access_events` rows themselves are never modified. Holds no
ALLOW/DENY: the decision stays in `access_decisions`.
- id
- uuid
- gate_id (restrict delete)
- face_event_id nullable (restrict delete; unique: an event belongs to at most one pass; must be a
  RECOGNITION event)
- plate_event_id nullable (restrict delete; unique; must be a PLATE event)
- person_id nullable (null on delete; identity at correlation time)
- vehicle_id nullable (null on delete; identity at correlation time)
- match_result MATCHED | MISMATCH | UNKNOWN | FACE_ONLY | PLATE_ONLY
  - MATCHED: face and plate events, both recognised, and the person is linked to the vehicle
  - MISMATCH: face and plate events, both recognised, but no link
  - UNKNOWN: face and plate events, but the face or the plate was not recognised
  - FACE_ONLY / PLATE_ONLY: only that event in the window
- occurred_at (earliest occurred_at of its events)
- created_at (no updated_at)

Immutable, enforced in `App\Models\GatePass`: on create it checks that the events fit
`match_result`, MATCHED/MISMATCH carry both person_id and vehicle_id, each event has the right
category and is in the gate's site; it is never updated or deleted through the model. Retention is
the only later writer: passes are deleted with their events (`retention.events_days`) and lose
`vehicle_id` when their plate read is redacted (`retention.plate_reads_days`).
