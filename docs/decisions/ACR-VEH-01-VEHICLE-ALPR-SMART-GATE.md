# ARCHITECTURE_CHANGE_REQUEST — ACR-VEH-01 Vehicle + ALPR (Phase 4) and face-first Smart Gate (Phase 5)

**Status:** APPROVED by the project owner (chat, 2026-11-12: "Approve as recommended") — alternative A,
decisions D1–D6 as recommended. Tasks run one at a time in TASKS.md order, starting with VEH-001.
· **Raised:** 2026-11-12 · **Requested by:** project owner (chat, 2026-11-12: "ดำเนินการตาม แผนในเอกสาร
(docs/00-system-blueprint.md §18) … Phase 4 = Vehicle + ALPR และ Phase 5 = Smart Gate ที่ใช้ใบหน้าคู่กับทะเบียนรถ")

## สรุปภาษาไทยสำหรับผู้อนุมัติ

- **ใบหน้าเป็นหลักเสมอ** ระบบยังรู้จำใบหน้าผ่าน AI Node เหมือนเดิม ส่วนการอ่านทะเบียนรถ (ALPR) เป็นโมดูลเสริม
  ที่เปิดหรือปิดได้ 2 ชั้น คือเปิดทั้งระบบ (`feature.alpr`) และเปิดรายกล้อง (`plate_recognition_enabled`)
- **Phase 4** เพิ่มข้อมูลรถ (ทะเบียน จังหวัด เจ้าของ = บุคคลในระบบ) และให้ AI Node อ่านป้ายทะเบียน แล้วส่งผลเป็น
  เหตุการณ์ (event) เข้าระบบเดิม เจ้าหน้าที่ค้นหาและตรวจย้อนหลังได้
- **Phase 5** จับคู่ "ใบหน้า + ทะเบียน" ที่จุดเข้าออกเดียวกันในช่วงเวลาสั้น ๆ แล้วให้นโยบาย (policy) ตัดสิน เช่น
  หน้าตรงและรถเป็นของคนนั้น = อนุญาต, หน้าตรงแต่รถไม่ใช่ของเขา = ให้เจ้าหน้าที่ตรวจ
- **ยังไม่สั่งเปิดไม้กั้นจริง** ในรอบนี้ ระบบตัดสินและแจ้งผลเท่านั้น การสั่งไม้กั้นต้องใช้ Phase 2
  (controller + sensor) ซึ่งต้องขออนุมัติแยก ตามกฎ "AI ห้ามเปิดประตูเอง"
- ก่อนเริ่มต้องตอบคำถาม D1–D6 ด้านล่าง (มีค่าที่แนะนำไว้แล้ว ตอบว่า "ตามที่แนะนำ" ได้)

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  The owner wants the platform to work like a gate licence-plate system, but with the face as
  the primary identifier and plate reading as an optional module that can be switched on or
  off. Vehicle and ALPR are reserved future modules (Phase 4) and the face+plate Smart Gate is
  Phase 5. Both are locked for Production V1: FeatureFlagService::LOCKED_V1_FEATURES contains
  'vehicle' and 'alpr', config/features.php kill switches must be false, pilot:preflight fails
  otherwise, and PROJECT_RULES / blueprint §18 say future phases must not be activated before
  human approval. Phases 2 (door/controller) and 3 (visitor) have not been started.

Current specification:
  - docs/00-system-blueprint.md §3: AI identifies -> Backend decides -> Policy controls ->
    Controller acts -> Sensor confirms -> Audit records. AI never opens a door.
  - §18: Phase 4 = Vehicle + ALPR; Phase 5 = Face+plate Smart Gate + AI Assistant + analytics.
  - docs/01-database-blueprint.md §15: vehicles and vehicle_ownership_histories reserved.
  - docs/11-module-07-security-pilot.md: VEHICLE=false, ALPR=false in Production V1.
  - cameras has face_detection_enabled / face_recognition_enabled / liveness_enabled toggles.
  - access_events: person_id, camera_id, ai_node_id, event_type -> event_types.code,
    face_score, liveness_score, quality_score, track_id, snapshot_path, metadata jsonb.
  - AI nodes are CPU-class (Raspberry Pi 5) running ONNX Runtime / OpenCV (ACR-AI-01).

Proposed change:
  Phase 4 — Vehicle + ALPR (face remains primary)
  1. Data (new tables, append-only history, UUID external ids, no change to existing columns):
     - vehicles: uuid, plate_number (normalised Thai text, e.g. "1กข1234"), plate_province
       (code from a fixed Thai province list), vehicle_type, brand, model, color, status
       ACTIVE|INACTIVE|BLOCKED, notes, timestamps, soft delete.
       Unique (plate_number, plate_province) among non-deleted rows.
     - vehicle_ownership_histories: vehicle_id, person_id, relation OWNER|DRIVER|AUTHORIZED,
       effective_from, effective_to, reason, created_by. Never overwritten; ending a relation
       closes effective_to. A vehicle may be linked to several persons.
     - cameras: new boolean plate_recognition_enabled default false (per-camera toggle, same
       pattern as face_detection_enabled).
     - access_events: no new columns. Plate results go in new event types and metadata:
       PLATE_RECOGNIZED, PLATE_UNKNOWN, PLATE_LOW_CONFIDENCE (event_types seed), with
       metadata.plate = {text, province, ocr_score, detection_score, vehicle_uuid|null,
       plate_crop_path}. vehicle_id is resolved by the backend, never by the node.
  2. Two-level switch, both fail closed:
     - system: feature.vehicle (vehicle registry UI/API) and feature.alpr (plate ingestion and
       node plate pipeline) through the existing FeatureFlagService + kill switches;
     - per camera: plate_recognition_enabled, synced to the node by the existing camera
       configuration sync (CAM-015). A node never reads plates on a camera that is not
       enabled, and the backend rejects plate results for such cameras.
     'vehicle' and 'alpr' leave LOCKED_V1_FEATURES only after approval; the env kill switches
     stay, so an operator can still force them off. pilot:preflight changes from "must be
     false" to "must be explicitly set" for these two.
  3. AI node plate pipeline (apps/ai-service), alongside — not replacing — the face pipeline:
     plate detection -> perspective crop -> OCR (Thai characters + digits) -> province
     classification -> text normalisation -> per-track vote across frames -> one result per
     vehicle pass. Same offline buffer, retry and event UUID idempotency as face events
     (CAM-011/012, EVT-004). Models are vendored with licence texts and SHA-256 checksums like
     ACR-AI-01; model choice is decision D4 and needs licence verification first.
  4. Backend: internal ingestion endpoint for plate results (/internal/v1, per-node auth),
     plate -> vehicle lookup, event persistence, realtime broadcast, history search by plate,
     reports. New permissions: vehicle.view, vehicle.manage, plate.view (seeded only after
     approval; SUPER_ADMIN / SYSTEM_ADMIN get them, SECURITY_OFFICER gets view only).
  5. UI (Thai, hidden unless the flags are on): จัดการยานพาหนะ (list/create/edit, link owner),
     plate column and plate crop in Live Monitor / event detail, search history by plate,
     camera form toggle "อ่านป้ายทะเบียน".

  Phase 5 — Face-first Smart Gate (decision only, no actuation)
  6. A gate = a zone entry/exit point with one or more cameras (face camera, plate camera, or
     one camera doing both). Config table gates: uuid, site_id, zone_id, name, direction
     IN|OUT|BOTH, correlation_window_seconds (default 10), mode (decision D2), status.
     gate_cameras links cameras to a gate with a role FACE|PLATE|BOTH.
  7. Correlation service (backend): when a face event and a plate event from the same gate
     fall within the window, it creates one gate pass (gate_passes: uuid, gate_id,
     face_event_id, plate_event_id, person_id, vehicle_id, match_result, created_at) — the
     original events are untouched. match_result: MATCHED (person is linked to the vehicle),
     MISMATCH (both recognised, not linked), FACE_ONLY, PLATE_ONLY, UNKNOWN.
  8. Policy: new AccessContext fields (gate, vehicle, ownership link, match_result) so the
     existing policy matcher decides ALLOW / REVIEW / DENY per gate mode. Face stays the
     primary identity: a plate alone never produces ALLOW in the recommended mode.
     Decisions go into access_decisions as today; manual review appends, never overwrites.
  9. Actuation is OUT OF SCOPE. The gate shows the decision in Live Monitor and notifications
     only. Opening a barrier requires Phase 2 (controller + sensor confirmation) and its own
     ACR. Nothing in this ACR lets the AI node or the AI service drive a relay.

Reason:
  The owner's requested operating model. The blueprint already reserves these modules and the
  identity/event/policy model was designed to absorb them without redesign: vehicles hang off
  Person, plate results are AccessEvents, gate decisions are AccessDecisions.

Impact:
  - Backend: 5 new tables (vehicles, vehicle_ownership_histories, gates, gate_cameras,
    gate_passes), 1 new camera column, event type and permission seeds, ingestion
    endpoint, correlation service, policy context, APIs, tests. Locked-feature list, preflight
    and SEC-024 wording change for vehicle/alpr only (door, visitor, ai_assistant stay locked).
  - AI service / node kit: plate pipeline and models (kit size grows; extra CPU per plate
    camera — must be benchmarked like CAM-020 before pilot).
  - Frontend: vehicle pages, gate pages, plate fields in monitor/events/history, feature-gated.
  - API contract: additive only (new endpoints/fields); no existing field renamed.
  - Privacy (PDPA, KNOWN_RISKS R-10): plate numbers and plate crops are personal data linked to
    a person. Needs retention settings (reuse RETENTION settings), audit of every vehicle
    record change and of plate searches, and an update to the data inventory. Legal review
    of the PDPA position stays a human task.
  - Docs: 00-system-blueprint §18 status, 01-database-blueprint §15 -> real tables,
    02-api-contract new sections, 03-ui-ux-blueprint menu, 11-module-07 flag table,
    SEC-024 / DEPLOYMENT runbook flag lists.

Migration impact:
  Additive migrations only; existing rows untouched. With feature.vehicle / feature.alpr off
  (the default) the system behaves exactly as today. Rollback: switch the flags off; the new
  tables can stay empty. No data migration for existing events.

Alternatives:
  A. Proposed: Phase 4 then Phase 5 decision-only, actuation deferred to Phase 2.
  B. Plate-only gate (classic ALPR, face optional). Rejected: the owner wants face first.
  C. Skip Phase 4 and build Phase 5 with ad-hoc plate strings and no vehicle registry.
     Rejected: no ownership link means MISMATCH cannot be computed.
  D. Do Phase 2 (controller + barrier) first so the gate can open for real at the end of
     Phase 5. Longer, needs hardware choice; can follow this ACR.
  E. Buy a commercial ALPR engine / camera with on-board LPR and ingest its results instead of
     running OCR on the node. Better Thai accuracy out of the box, licence cost, vendor lock-in.
     The ingestion endpoint in step 4 is designed so this can be added later as a second source.
```

## Owner decisions needed before work starts

| ID | Question | Recommended | Why |
|---|---|---|---|
| D1 | Where is plate reading switched on/off? | Both: system flag `feature.alpr` **and** per camera `plate_recognition_enabled` | System switch for licensing/policy, per-camera because only gate cameras see plates |
| D2 | Gate decision when face and plate disagree | Mode `FACE_PRIMARY`: face MATCH + vehicle linked → ALLOW; face MATCH + plate not linked or unknown → ALLOW with note (configurable to REVIEW per gate); face UNKNOWN + plate known → REVIEW; both unknown → REVIEW; plate BLOCKED → DENY | Face stays the identity; a plate alone never lets anyone in |
| D3 | Cameras | Support both one camera for face+plate and separate face/plate cameras per gate | Plate cameras usually need a different angle, zoom and shutter speed from face cameras |
| D4 | ALPR model | Evaluate open models with commercial-use licences (candidates to verify: OpenCV Zoo plate detector, PaddleOCR Thai recognition, fast-plate-ocr) on real Thai plates; option E (commercial engine/camera LPR) as fallback | Same licence discipline as ACR-AI-01; Thai plate OCR accuracy must be measured, not assumed |
| D5 | Real barrier control | Not in this ACR. Decision + notification only; Phase 2 ACR later | PROJECT_RULES: controller acts only after backend decision, with sensor confirmation |
| D6 | Who may register vehicles | SUPER_ADMIN / SYSTEM_ADMIN manage; SECURITY_OFFICER view; owner may later allow self-registration | Least privilege; vehicle ↔ person link is personal data |

## Task breakdown (added to TASKS.md as `[R]`, unlocked one at a time after approval)

Phase 4 — Vehicle + ALPR: VEH-001 … VEH-014. Phase 5 — Smart Gate: GATE-001 … GATE-010.
See `TASKS.md` § "PHASE 4 — VEHICLE + ALPR" and § "PHASE 5 — FACE-FIRST SMART GATE".

## Approval

APPROVED 2026-11-12 by the project owner in chat ("Approve as recommended"):

- Alternative **A** (Phase 4, then Phase 5 decision-only; actuation deferred to a Phase 2 ACR).
- D1–D6 exactly as in the "Recommended" column above.
- Still requiring separate human approval later: the ALPR model and its thresholds (VEH-007,
  after the Thai-plate evaluation and licence check), and any physical barrier control (Phase 2).

## Ordering with Module 09 (2026-11-16)

OWNER-DECISIONS-2026-11-16 (U2) says to build one module at a time, starting with Module 09 (people).
When this branch was merged with that decision, the owner was asked whether to pause the vehicle work
and chose in chat to **continue VEH-004** in parallel. U4 still applies: `feature.vehicle` and
`feature.alpr` stay off until the module is complete and accepted.

## Smart Gate access (GATE-002, 2026-11-17)

Asked in chat before GATE-002, the owner chose the recommended options: new permissions `gate.view` /
`gate.manage` (SUPER_ADMIN and SYSTEM_ADMIN manage, SECURITY_OFFICER views), and the gate pages and API
sit behind `feature.vehicle`, so gates can be set up while plate reading (`feature.alpr`) is still off.

## Smart Gate correlation (GATE-004, 2026-11-19)

How the correlation service (`App\Services\Gate\GateCorrelationService`) applies item 7. No new owner
decision was needed; the choices below follow the ACR and the existing ingestion pipeline.

- When: right after a face or plate event is stored (after commit, after broadcast), the service tries
  to pair it with an unpaired event of the other kind at the same gate whose `occurred_at` is within
  the gate's `correlation_window_seconds`. A failure there is logged and never loses the event.
- Lone events: `gates:correlate` runs every five seconds. An event still alone once the window plus
  5 s grace has passed since it was received gets a FACE_ONLY or PLATE_ONLY pass. The sweep tries to
  pair first, so a pairing missed at ingest time is not lost. It looks back 15 minutes only, so turning
  a gate on never backfills old history.
- Which events: camera linked to an ACTIVE gate, camera role reads that kind (FACE/BOTH for face
  events, PLATE/BOTH for plate events), event in the gate's site and received after the camera was
  linked. All face types (FACE_RECOGNIZED, UNKNOWN_PERSON, LIVENESS_FAILED) and all plate types count.
- Several candidates in the window: the closest in time wins; an event is used by one pass only.
- match_result: MATCHED when the face is FACE_RECOGNIZED with a person, the plate is PLATE_RECOGNIZED
  with a vehicle, and that person has a current ownership link to that vehicle at the face event's
  time; MISMATCH when both are known without such a link; UNKNOWN when either is not recognised.
- Only with `feature.vehicle` on. The service never writes an AccessDecision (GATE-005..007) and never
  modifies an access event. Two cars, tailgating and late or out-of-order delivery are GATE-009.

## Smart Gate policy context (GATE-005, 2026-11-19)

How item 8 is applied. No new owner decision was needed.

- `AccessContext` gains gate fields, all null for an ordinary event: gate id and mode, the pass's
  match_result, the vehicle and its current status, and the relation of the person's ownership link
  to that vehicle at the face event's time (null when there is none).
- `AccessContextResolver::resolvePass(GatePass)` builds it. The subject comes from the face event's
  person only, never from the vehicle owner, so a plate alone leaves the subject unknown. Site and zone
  are the gate's; the event type is the face event's, or the plate event's for a PLATE_ONLY pass.
- `access_policies` gains four wildcard-when-null dimensions read by the existing matcher: `gate_id`,
  `gate_mode`, `match_result`, `vehicle_status`. An ordinary event cannot match a policy that sets any
  of them. `gate_id` is restrict-delete so removing a gate never widens its policy to every gate.
  The ownership relation stays in the context for the decision record; match_result already says
  whether the link exists.
- With only the existing default policies a PLATE_ONLY pass falls to REVIEW / NO_MATCHING_POLICY.
  The FACE_PRIMARY policies themselves are GATE-006; writing gate decisions is GATE-007.

## FACE_PRIMARY gate policies (GATE-006, 2026-11-19)

How D2 is seeded (rows listed in `docs/09-module-05-event-policy-monitor.md` "Smart Gate Policies"):

- BLOCKED vehicle -> DENY ahead of everything; plate only, unknown face and failed liveness -> REVIEW.
- "Face MATCH -> ALLOW" is not a gate policy. A recognised face falls through to the person's own policies,
  so the gate never allows anyone the ordinary rules would review or deny (inactive record, suspended,
  expelled, visitor, parent). For an entitled person this gives D2's ALLOW, with match_result as the note.
- "Configurable to REVIEW per gate" is two disabled template policies (mismatch, plate unknown) at priority
  150, which can only make a decision stricter. Per-gate use means copying a template with `gate_id`.
  There is no policy editing UI or API; a gate-level setting for this would need an owner decision.

## Gate pass decisions (GATE-007, 2026-11-20)

- Owner decision in chat (2026-11-20, recommended option): **one alert per pass**. At a camera linked
  to an ACTIVE gate (with `feature.vehicle` on) the face event's own decision is still stored and
  pushed, but it sends no notification; the alert comes with the gate decision. A lone face therefore
  alerts up to the window plus 5 s later (about 15 s with the default window).
- Each pass gets one AccessDecision on its face event (its plate event for a PLATE_ONLY pass), tagged
  with `access_decisions.gate_pass_id`. As that event's latest decision it is what the Live Monitor,
  history and the review queue show; the face event's earlier decision stays in its history.
- The gate never overrides a human: if an officer already ruled on the event before the pass formed,
  no gate decision is added. A pass whose decision failed is retried by `gates:correlate`.
- An officer reviews a gate decision like any event; the review appends and keeps the pass link. The
  plate event of a PLATE_ONLY pass is reviewable for that reason; other plate reads still are not.
- Reports by gate are GATE-008. Nothing here opens a barrier (D5).
