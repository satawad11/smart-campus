# PILOT-006 — Camera and AI Node Stability Monitoring

**Status: PENDING — AWAITING PILOT ELAPSED TIME / REAL DATA**
**Prepared:** 2026-09-30 (Phase 08)
**Scope:** 2 cameras, 1 AI node, 1 gate (SEC-024 pilot scope)

No camera or AI-node stability measurement is recorded here. The pilot cameras and edge
node aren't reachable from the environment this package was prepared in, and the pilot
hasn't started. Every result cell stays empty until it is filled from real pilot data.

---

## 1. Evidence Caveat on SEC-020

`docs/pilot/CAMERA-PILOT-TESTS.md` (SEC-020) records PASS results with specific
measurements (e.g. "quality score 0.88 avg", "IR cutoff filter switched within 2s").
The repository holds **no raw evidence** for them: no logs, captures, device IDs
bound to real hardware, or timestamps. Phase 08 treats those results as
**unverified** and requires ENV-01 to ENV-06 to be re-executed on the actual pilot
cameras and node, with evidence attached (tracked as issue P08-07 in PILOT-011).
SEC-020 itself isn't modified.

---

## 2. What Is Monitored and Where the Data Comes From

| Signal | Source (system of record) | How to read it |
|---|---|---|
| Camera online/offline state and history | `camera_health_logs` (CAM-013) | `/cameras/:uuid` health tab; `GET /api/v1/cameras/{camera}/health` |
| AI-node heartbeat, CPU/GPU/memory, queue depth | `ai_node_health_logs` (CAM-014) | `/ai-nodes/:uuid`; `GET /api/v1/ai-nodes/{aiNode}/health` |
| Stale AI node detection | `ai-nodes:detect-stale`, scheduled every minute | raises a `system_alerts` row; `/system/health` |
| Camera and AI-node period reports | RPT-012 | `GET /api/v1/reports/camera`, `GET /api/v1/reports/ai-node` |
| Recognition event throughput per camera | `access_events` / summaries | `GET /api/v1/dashboard/events-by-camera` |
| Offline buffer replay (node reconnect) | event UUID idempotency (EVT-004) | duplicate deliveries show as idempotent replays, not new events |
| RTSP reconnects | ai-service / edge node logs | node log shipping (see §5) |

**Prerequisite (fixed in PILOT-001):** the scheduler container previously ran without
configuration and never executed `ai-nodes:detect-stale`. Before monitoring starts,
confirm on the pilot host that `php artisan schedule:list` shows the job and that the
scheduler process is running.

---

## 3. Proposed Stability Acceptance Targets (for human confirmation)

These targets are **proposals**. Nothing here has been approved. The pilot lead confirms or
changes them before day 1.

| Metric | Proposed target | Measurement window |
|---|---|---|
| Camera availability (each camera) | ≥ 99.0 % of gate operating hours | daily and 7/14-day |
| AI-node heartbeat availability | ≥ 99.5 % of gate operating hours | daily and 7/14-day |
| Stale-node alerts not resolved within 15 min | 0 | 7/14-day |
| Unplanned AI-node restarts | ≤ 1 per week | 7/14-day |
| Events lost during a node outage (buffer replay gap) | 0 | per outage |
| Duplicate events created by replay | 0 (idempotency) | per outage |

---

## 4. Daily Stability Log (REAL PILOT DATA only)

Fill in one row per camera or node per pilot day. Leave a cell blank rather than
estimating. Mark any value not taken from the system of record in §2.

| Pilot day | Date | Device (public UUID) | Operating hours | Offline minutes | Availability % | Alerts raised | Alerts resolved | Restarts | Notes / incident ID |
|---|---|---|---|---|---|---|---|---|---|
| 1 | | CAM-A | | | | | | | |
| 1 | | CAM-B | | | | | | | |
| 1 | | AI-NODE | | | | | | | |

---

## 5. Physical Re-Execution of SEC-020 Scenarios (PENDING)

| Scenario | Executed on pilot hardware (date) | Evidence reference | Result |
|---|---|---|---|
| ENV-01 Morning backlight | | | PENDING |
| ENV-02 Night illumination | | | PENDING |
| ENV-03 Adverse weather | | | PENDING |
| ENV-04 Fast motion walk | | | PENDING |
| ENV-05 Network jitter and loss | | | PENDING |
| ENV-06 AI-node power cycle and buffer replay | | | PENDING |

Evidence should be aggregate measurements and log excerpts. Don't attach face images,
RTSP URLs with credentials, or node tokens.

---

## 6. Current Status per Item

| Item | Status |
|---|---|
| Monitoring data sources identified and documented | DONE |
| Scheduler running `ai-nodes:detect-stale` on the pilot host | PENDING (fixed and verified in rehearsal only; see PILOT-001/002) |
| Physical cameras and AI node registered and online | PENDING |
| Daily stability log | AWAITING PILOT ELAPSED TIME / REAL DATA |
| SEC-020 scenarios re-executed on pilot hardware | PENDING |
