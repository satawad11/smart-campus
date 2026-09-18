# AI Smart Campus Security & Access Platform — UX/UI Blueprint V1

## 1. Design Goals

- Fast situational awareness
- Clear exception handling
- Thai-first UI
- Modern administrative interface
- High contrast and readable status
- Desktop/tablet primary
- Mobile for monitoring/alerts/report summaries

## 2. Main Navigation

- Dashboard
- Live Monitor
- บุคคล
  - นักเรียน
  - Face Enrollment
  - Import Data
- กล้องและ AI
  - Cameras
  - AI Nodes
- เหตุการณ์
  - Event History
  - Review Required
  - Unknown Persons
- รายงาน
  - Daily
  - Weekly
  - Monthly
  - Yearly
  - Custom
- ผู้ใช้งาน
  - Users
  - Roles
  - Permissions
- ระบบ
  - Sites
  - Zones
  - Settings
  - Audit Logs
  - System Health

Future menu modules remain hidden by feature flags.

## 3. Login

Fields:
- username
- password
- MFA OTP when required

Errors must not reveal username existence.

## 4. Dashboard

KPIs:
- detected today
- recognized
- review required
- unknown
- allow
- deny
- cameras online/total
- AI nodes online/total

Charts:
- events by hour
- decisions
- cameras
- departments

Role-specific dashboards are allowed.

## 5. Live Monitor

Supports camera grid:
- 1
- 2
- 4
- 9

Shows:
- live camera tile
- realtime event feed
- status/decision cards
- system status
- pending review count

Event drawer:
- photo/snapshot
- name
- student code
- department/class
- academic status
- similarity/quality/liveness
- camera/time
- decision history
- review action when permitted

## 6. Decision Visuals

Do not rely on color alone.

- ALLOW: icon + text
- REVIEW: icon + text
- DENY: icon + text
- UNKNOWN: icon + text

## 7. Student Management

List columns:
- photo
- student code
- name
- department
- classroom
- education level
- academic status
- face enrollment status
- actions

Filters:
- keyword
- department
- program
- classroom
- level
- year
- status
- face enrolled

Student detail tabs:
- general
- academic
- status history
- face
- event history
- audit

## 8. Student Status Change

Must use a dedicated action/dialog, not a casual inline dropdown.

Fields:
- new status
- effective from
- effective to
- reason

Confirmation required for impactful transitions.

## 9. Student Import Wizard

Steps:
1. upload
2. column mapping
3. validation
4. preview
5. import
6. results

Show valid/update/error counts before confirmation.

## 10. Face Enrollment

Wizard:
1. notice
2. camera permission
3. front capture
4. left capture
5. right capture
6. quality validation
7. create profile
8. verification capture

Capture indicators:
- face detected
- brightness
- sharpness
- face size
- pose

## 11. Face Profile UI

Show:
- status
- model/version
- enrollment date
- quality
- sample count
- re-enroll/revoke actions

Do not display raw embedding.

## 12. Camera Management

Camera list:
- code/name
- IP
- zone
- AI node
- online/offline
- face enabled
- liveness enabled

Wizard:
1. general
2. connection
3. AI
4. test
5. save

Camera detail tabs:
- overview
- preview
- AI settings
- ROI
- health
- events

## 13. AI Node

Show:
- status
- CPU
- RAM
- GPU
- GPU memory
- temperature
- active cameras
- queue depth

## 14. Events

Event history filters:
- date
- person/student
- site
- zone
- camera
- event
- decision
- academic status

Review queue:
- snapshot
- person
- status
- reason
- camera
- time
- review action

Unknown page:
- snapshot
- camera
- time
- quality
- event detail

Do not auto-create Person from Unknown.

## 15. Reports

Home:
- daily
- weekly
- monthly
- yearly
- custom

Shared filter panel:
- date range
- site
- zone
- camera
- department
- program
- classroom
- academic status
- event type
- decision

Exports:
- XLSX
- CSV
- PDF
- Print

Large export shows job status.

## 16. Users/Roles

User list:
- username
- name
- role
- scope
- status
- last login

User detail tabs:
- account
- roles/permissions
- scope
- sessions
- login history
- audit

Role detail:
- name
- description
- users
- permission matrix

## 17. System Settings

Tabs:
- General
- Face AI
- Event
- Storage
- Security
- Notification
- Integration
- Feature Flags

Face AI settings include thresholds and cooldown with warning text.

## 18. Audit Logs

Filters:
- user
- module
- action
- date range

Detail displays old/new values when applicable.

## 19. System Health

Show:
- backend
- database
- Redis
- object storage
- websocket
- AI service
- AI nodes
- cameras
- queue
- scheduler

States:
- HEALTHY
- DEGRADED
- UNHEALTHY
- UNKNOWN

## 20. Localization

Internal enums remain English.

Thai UI mapping example:
- ACTIVE = ปกติ
- SUSPENDED = พักการเรียน
- WITHDRAWN = ลาออก
- GRADUATED = จบการศึกษา

API uses Gregorian ISO timestamps.

UI may display Buddhist Era.

## 21. Components

Reusable:
- AppSidebar
- AppTopbar
- DataTable
- FilterPanel
- StatusBadge
- DecisionBadge
- PersonCard
- CameraCard
- EventCard
- MetricCard
- ConfirmDialog
- Pagination
- DateRangePicker
- LoadingSkeleton
- SystemStatus

## 22. Required States

Every major page/component should support:
- loading
- empty
- error
- success
- unauthorized where relevant

## 23. Accessibility

At minimum:
- keyboard navigation
- visible focus
- semantic labels/ARIA
- adequate contrast
- icon + text for status
