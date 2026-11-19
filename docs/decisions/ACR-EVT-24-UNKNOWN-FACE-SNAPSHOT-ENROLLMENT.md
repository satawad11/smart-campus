# ARCHITECTURE_CHANGE_REQUEST — Unknown-event image and enrollment action

**Status:** PENDING OWNER APPROVAL
**Raised:** 2026-11-17
**Related:** EVT-022, EVT-018, FACE-016, CAM-011, SEC-010

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  The live monitor creates durable UNKNOWN and REVIEW events and alerts operators, but the
  event-delivery path carries no image. AccessEvent.snapshot_path is currently always null,
  and event APIs intentionally do not return snapshot bytes. An operator therefore cannot
  inspect the detected face or continue enrollment from the exact alert.

Current specification:
  docs/02-api-contract.md defines POST /internal/v1/events/recognition. The AI node sends
  classification and evidence, not a raw frame or embedding. docs/03-ui-ux-blueprint.md
  calls for event snapshots in the drawer and unknown-person page. Biometric samples are
  highly sensitive, stored privately, and may not be exposed in normal event JSON.

Proposed change:
  1. After the backend persists an event, allow the authenticated AI node to upload a
     bounded JPEG face crop for that event through a dedicated internal endpoint. Reject
     unknown events, camera/node ownership mismatches, invalid MIME types, and files over a
     configured size limit. Do not accept full video frames or embeddings.
  2. Store the crop in the private event-snapshots bucket and associate its key with the
     existing AccessEvent.snapshot_path. Never return the key or bytes in ordinary event
     responses. Add a scope-checked, permission-protected snapshot endpoint that issues a
     short-lived signed read URL; audit successful snapshot access.
  3. Add a snapshot_available boolean to event responses. The alert and event detail page
     can show the crop only to authorized operators. The alert may offer “ดูภาพ”, “ลงทะเบียน
     ใบหน้า”, and “ละเว้นการแจ้งเตือน”.
  4. “ลงทะเบียนใบหน้า” must use the existing person picker and consent notice. It must not
     auto-create a Person or FaceProfile. The operator identifies an existing person (or
     first creates one through the proper people menu), obtains consent, and completes the
     normal audited enrollment workflow. The event crop is a preview/source only and is not
     made an active profile without the normal quality, duplicate, model and consent checks.
  5. “ละเว้นการแจ้งเตือน” marks only the notification read. It does not delete, suppress,
     whitelist or reclassify the event; the event remains available in history and review.
  6. Apply the existing configurable retention cleanup to the private image and clear the
     event snapshot reference when purged. Add tests for authorization/scope, upload
     validation, retention, no-image behavior, and frontend actions.

Reason:
  Operators need to see and investigate an unknown/non-routine face promptly. Reusing the
  existing event record, private object-storage bucket, person identity model, consent-gated
  enrollment service, and notification read API preserves event/decision separation and
  prevents an unknown face from becoming an identity automatically.

Impact:
  AI service delivery client and schema; internal upload API and validation; private object
  storage; event resource gains a boolean; scoped snapshot access endpoint; notification and
  event-detail UI; audit actions; retention configuration/tests. Requires camera/node load,
  privacy and storage-failure testing before live operation.

Migration impact:
  AccessEvent.snapshot_path already exists, so a database migration is not expected. Object
  storage policy and retention defaults require review. API additions are additive.

Alternatives:
  A. Keep image capture/enrollment separate from alerts: operators use the existing face
     enrollment webcam flow and manually find/associate the durable event. No image is added
     to the event pipeline, but the exact alert face cannot be inspected later.
  B. Use a fresh live camera snapshot when the operator opens an alert. This may show a
     different person or an empty scene and is not reliable for identifying the historical
     event subject.
  C. Send raw frames in normal event JSON — rejected because it increases payload size and
     expands exposure of sensitive face data.
```

## Decisions needed

| Question | Proposal |
|---|---|
| Store event face crops in the existing private event-snapshots bucket | Approve with size cap and retention |
| Permit role/scope-checked temporary viewing | Approve; audit access |
| Allow enrollment to start from an alert | Approve only through existing person selection, consent, quality and audit workflow |
| Meaning of “ละเว้น” | Mark notification read only; retain the event |
