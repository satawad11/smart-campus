# Owner Decisions — 2026-11-16: unlock the future phases

**How this was made:** in chat on 2026-11-16 the project owner instructed "UNLock ทั้งหมดเลย" (unlock everything).
Asked what that meant, the owner chose: **record approval of every locked phase, then build them one at a time**,
starting with **visitors / parents / staff**. This is an explicit owner instruction, not a delegated recommendation.

| # | Topic | Decision |
|---|---|---|
| U1 | Locked phases in `TASKS.md` ("FUTURE — LOCKED UNTIL APPROVAL") and `docs/00-system-blueprint.md` §18 | **Approved**: Door Access Control, Visitor/Parent, Vehicle + ALPR, AI Assistant + Advanced Analytics |
| U2 | Delivery order | One module at a time. First: **Module 09 — People: staff, visitors, parents** (`docs/12-module-09-people-visitor.md`). The owner picks the next one when Module 09 is done |
| U3 | Gate before code | Each module needs a written spec the owner accepts before implementation (`Spec -> Contract -> Task -> Test -> Code`) |
| U4 | Feature flags | `FEATURE_*_ENABLED` stay `false` until that module is complete and accepted; partial code is never exposed (PROJECT_RULES §13) |

## What this does not change
- Locked stack, domain model, AI boundary ("AI identifies -> Backend decides") and security rules still apply to every
  new module. Anything that would change them still needs an `ARCHITECTURE_CHANGE_REQUEST`.
- **Door Access Control** drives physical hardware. It stays fail-closed, needs the owner to name the controller
  hardware/protocol, and needs an on-site safety test plan before any door is wired to the system.
- **Vehicle + ALPR** and the **AI Assistant** need their model/provider chosen and licence-checked first, the same way
  as ACR-AI-01 for face models.
- PDPA: visitor and parent data are personal data of non-students; the DRAFT documents in `docs/legal/` must cover
  them before real use.
