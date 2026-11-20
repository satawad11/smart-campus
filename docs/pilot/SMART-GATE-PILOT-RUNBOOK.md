# Smart Gate Pilot Runbook (GATE-010, ACR-VEH-01 Phase 5)

**Status:** software accepted by automated tests (`SmartGateAcceptanceSecurityTest` and GATE-001..009
tests). **Not yet run on a live gate.** Plate reading on AI nodes (VEH-008) and the ALPR model choice
(VEH-007, needs real Thai plate photos and owner approval) are still open, so until then a live
pilot can only exercise face events at a gate (FACE_ONLY passes).

**Decision and notification only.** The Smart Gate never opens a barrier, relay or door. Physical
barrier control needs Phase 2 and its own ACR (ACR-VEH-01 D5). Officers act on what the screen shows.

---

## 1. Preconditions

- [ ] Production V1 pilot is live and passing PILOT-005 smoke checks.
- [ ] Owner has decided to use the optional modules. In `.env`: `FEATURE_VEHICLE_ENABLED=true`
      (and `FEATURE_ALPR_ENABLED=true` once VEH-008 is delivered). This only allows an administrator
      to switch them on; they stay off until switched on in การตั้งค่าระบบ.
- [ ] Privacy notice updated for plate reading at the gate (PDPA_DATA_MAP G-VEH-2, legal review).
- [ ] Vehicle record retention decided (G-VEH-1). Plate reads are wiped after
      `retention.plate_reads_days` (30) and passes deleted with their events (`retention.events_days`).
- [ ] The scheduler container is running (`docker compose ps scheduler`). It runs
      `gates:correlate` every 5 seconds; without it lone events never get a pass or a decision.
- [ ] Every AI node and the backend are NTP-synchronised (drift under 2 s, AI-NODE-INSTALLATION-GUIDE
      §1.2). Pairing uses the time an event happened; an event that reaches the backend more than
      120 s after it happened is treated as an ordinary camera event, not a gate event.

## 2. Setup (administrator, in the web app)

1. **การตั้งค่าระบบ** → switch on the vehicle module (`feature.vehicle`).
2. **จัดการยานพาหนะ** → register pilot vehicles and link each to its owner/driver (from-date today).
3. **จุดเข้าออกอัจฉริยะ** → create the gate in the gate zone: direction, correlation window
   (default 10 s; raise it only if face and plate cameras see a car more than 10 s apart), status
   ACTIVE.
4. Link the cameras with their role: FACE, PLATE, or BOTH for one camera that does both. A gate needs
   at least one camera that reads faces. On a PLATE or BOTH camera switch on **อ่านป้ายทะเบียน**
   (once ALPR is enabled).
5. Check the policies are present (seeded by migration `2026_11_19_900005`): blocked vehicle → DENY,
   plate only / unknown face / failed liveness → REVIEW. A recognised face is decided by the
   person's own policies. Disabled templates (mismatch, unknown plate → REVIEW) stay disabled unless
   the owner decides otherwise.

From this moment, face events at the gate cameras alert **once per pass, with the gate decision**,
up to about 15 s after the car passed (window + 5 s), not immediately.

## 3. Pilot scenarios

Run each at the gate with test people and test vehicles. Record the result in the pilot log.

| # | Scenario | Expected on screen (Live Monitor / alert / ประวัติการผ่านจุดเข้าออก) |
|---|---|---|
| 1 | Registered driver in own car | One row, ใบหน้าตรงกับรถ, decision by the person's policy (ALLOW for an active student/staff) |
| 2 | Registered person in someone else's registered car | ไม่ใช่รถของบุคคลนี้, decision by the person's policy |
| 3 | Unknown face, registered car | REVIEW (GATE_FACE_UNKNOWN), alert with gate and plate |
| 4 | Car with nobody's face seen (tailgating) | เห็นเฉพาะป้ายทะเบียน, REVIEW (GATE_PLATE_ONLY), alert |
| 5 | Blocked vehicle (set in จัดการยานพาหนะ) | DENY (GATE_VEHICLE_BLOCKED), even with the owner's face |
| 6 | Photo / screen held to the face camera | REVIEW (GATE_LIVENESS_FAILED) |
| 7 | Driver and passenger both seen | Driver paired with the car; passenger gets เห็นเฉพาะใบหน้า and their own decision |
| 8 | Two cars close behind each other | Each face paired with its own car, in passing order |
| 9 | Pedestrian at the face camera, no car | เห็นเฉพาะใบหน้า, decision by the person's policy |
| 10 | Unplug the AI node network for 5 minutes during passes, then reconnect | Buffered events arrive late and appear as ordinary events (own decision and alert), no gate pass |
| 11 | Officer reviews a gate decision | Review appends; the history shows ตรวจสอบโดยเจ้าหน้าที่ and the new result |
| 12 | Report for the day (รายงานและการส่งออก) | การผ่านจุดเข้าออก table counts the passes above by result and decision |

Pass criteria: every row as expected, no duplicate alerts for one pass, nothing opens a barrier.
Record each run in `SMART-GATE-PILOT-LOG.md`.

## 4. What the operator sees

- Live Monitor: the face event row (or plate row for a plate-only pass) updates in place with
  จุดเข้าออก, the match result and the plate. Text on the event page: "ระบบตัดสินและแจ้งเตือนเท่านั้น
  ไม่ได้สั่งเปิดไม้กั้น".
- Alerts: one per pass, naming the gate, what it saw and the plate.
- History: ประวัติการผ่านจุดเข้าออก (needs report.view). Plate searches are written to the audit log.

## 5. Troubleshooting

| Symptom | Check |
|---|---|
| No passes at all | `feature.vehicle` on; gate ACTIVE; camera linked with the right role; scheduler running; camera linked *before* the events (events received earlier are ignored) |
| Face alerts arrive immediately, no gate block | The camera is not linked to an ACTIVE gate, the module is off, or the event arrived more than 120 s late |
| Faces and plates not paired | Clock drift between nodes; window too short for the camera layout; plate camera role/toggle |
| Many REVIEW plate-only passes | Face camera angle or lighting; window too short; real tailgating |

## 6. Stopping or rolling back

- Stop one gate: set it INACTIVE in จุดเข้าออกอัจฉริยะ. Its cameras go back to ordinary alerts at once.
- Stop all: switch off `feature.vehicle` in การตั้งค่าระบบ (or `FEATURE_VEHICLE_ENABLED=false` and
  restart). Passes, decisions and history already written are kept; no data migration is needed.
- Gates are never deleted, so their history stays readable.
