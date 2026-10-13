# Product Blueprint — AI Smart Campus Commercial Edition

**Status:** DRAFT for owner review (2026-10-13). Describes the intended product, not a released one; gate status in
[COMMERCIAL_GAP_ANALYSIS](../audit/COMMERCIAL_GAP_ANALYSIS.md).

## 1. Problem and customer
Thai schools and vocational colleges need to know who enters and leaves campus, follow up on students who should or
shouldn't be present, and review exceptions, without sending video of minors to a cloud. First segment: vocational
colleges (the pilot institution type); then secondary schools.

## 2. Product shape (Hybrid Edge AI + Central Management)
| Part | Runs where | Role |
|---|---|---|
| Edge AI Node | on campus, next to cameras | RTSP decode, face detection/quality/embedding, offline event buffer; never stores video |
| Campus Platform | on campus server or dedicated VM, one per institution | identities, students, enrollment, matching, policy decisions, live monitor, review, history, reports, audit, backup, licence verification |
| Central Management Plane | vendor-hosted, multi-tenant | organisations, licences, fleet versions/health summaries, update notices, support (no biometric or student data) — **pending ACR-COM-01** |

Principles kept from V1: AI identifies → backend decides → policy controls → humans review exceptions → audit
records. MATCH/REVIEW/UNKNOWN thresholds stay at the FACE-021 approved values until a human approves a change.

## 3. Editions (proposal)
| Capability | Non-biometric | Standard | Notes |
|---|---|---|---|
| Student/person master data, import | ✓ | ✓ | |
| Camera + AI node management, health, alerts | ✓ | ✓ | |
| Face enrollment + recognition events | — | ✓ | blocked until a licensed, validated model is approved (B5) |
| Live monitor, review queue, history, reports, exports | ✓ (manual/other event sources) | ✓ | |
| Door, visitor, vehicle/ALPR, AI assistant | locked | locked | future phases, human approval required |

Licence entitlements (`features`, `max_cameras`, `max_ai_nodes`) implement these editions ([LICENSE_MODEL](LICENSE_MODEL.md)).

## 4. Out of scope for Commercial V1
Physical access actuation, cloud video storage, cross-institution identity, third-party integrations without
verified partner APIs (COM-07), automated disciplinary decisions.

## 5. Decisions the owner must make
ACR-COM-01 tenancy model; B5 model selection/licensing; edition and pricing catalogue
([PRICING_ASSUMPTIONS](PRICING_ASSUMPTIONS.md)); MFA enforcement policy for admins; retention schedule and PDPA
documents ([PDPA_DATA_MAP](PDPA_DATA_MAP.md)); support model and SLA.
