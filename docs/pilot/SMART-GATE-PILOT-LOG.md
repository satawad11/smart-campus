# Smart Gate Pilot Log (ACR-VEH-01 Phase 5)

Results of the scenarios in `SMART-GATE-PILOT-RUNBOOK.md` §3, as reported by the project owner.
Only what was reported is recorded here; nothing below was observed by the agent.

**Overall status: IN PROGRESS — issues reported, details pending.**

---

## Run 1 — 2026-11-20

- **Environment:** live pilot server (owner report).
- **Build:** not stated (latest on PR #18 at the time: `5851401`). To confirm.
- **Reported by:** project owner, in chat.

| # | Scenario | Result | Notes |
|---|---|---|---|
| 1 | Registered driver in own car | NOT REPORTED | Needs plate reads (VEH-008 not delivered) |
| 2 | Registered person in someone else's car | NOT REPORTED | Needs plate reads |
| 3 | Unknown face, registered car | NOT REPORTED | Needs plate reads |
| 4 | Tailgating | NOT REPORTED | Needs plate reads |
| 5 | Blocked vehicle | NOT REPORTED | Needs plate reads |
| 6 | Photo / screen held to the face camera | NOT REPORTED | |
| 7 | Driver and passenger | NOT REPORTED | Needs plate reads |
| 8 | Two cars close behind each other | NOT REPORTED | Needs plate reads |
| 9 | Pedestrian, face only | NOT REPORTED | |
| 10 | AI node offline 5 min, then reconnected | **PASS** (owner) | Late events shown as ordinary events, no gate pass |
| 11 | Officer reviews a gate decision | NOT REPORTED | |
| 12 | Daily report gate table | NOT REPORTED | |

### Issues

| ID | Reported | Scenario | What happened | Status |
|---|---|---|---|---|
| SG-PILOT-1 | 2026-11-20 | not stated | Owner reported "some issues"; description, screenshots or errors still to be provided | OPEN — awaiting details |

### Next

- Owner to describe the issues (what was done, what the screen showed, any error text or screenshot).
- Owner to confirm which other scenarios were run.
- Plate scenarios (#1–5, 7, 8) cannot run on real cameras until the plate evaluation
  (`PLATE-MODEL-EVALUATION.md`) is approved and plate reading is switched on; VEH-008 is built.
