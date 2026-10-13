# PILOT-000B — Post-Approval Change Review Pack

**Status:** PREPARED — **AWAITING HUMAN REVIEW** (not self-approved) · **Prepared:** 2026-10-13 by AI coding agent
**Last human approval:** B4, recorded in `e84d57f` (2026-09-30), covering `f36a347`..`72ee47b`
**Currently tagged candidate:** `v1.0.0-pilot` → `ea2d69c`
**Delta under review:** `ea2d69c..2e4605a` on `master`, plus the development branch `claude/vibrant-yalow-69fe65`

## 1. Why this review is needed

`docs/runbooks/DEPLOYMENT.md` deploys `tags/v1.0.0-pilot`. That tag does **not** contain the BLOCKER fixes from the
2026-09-30 re-audit. Deploying it today would ship:

- **A-05 (BLOCKER):** SITE/ZONE-scoped users can read every site's events, cameras, reports and exports through REST;
- **A-01 (BLOCKER):** no supported way to create the first administrator on a fresh host;
- A-04, A-06, A-07, A-08, A-11, A-12 (HIGH) and others.

The fixes exist on `master` but have no recorded human change review. Neither option ("deploy the old tag" or
"deploy unreviewed code") is acceptable, so the owner needs to review the delta and approve a new release candidate.

## 2. Behaviour changes in the delta

| Commit | Change | Behaviour visible to users/operators | Risk of the change | Test evidence |
|---|---|---|---|---|
| `5b04fac` | REST scope enforcement (A-05), export owner check (A-06), export serialization (A-13) | SITE/ZONE users see only their scope; out-of-scope records → 404; exports visible to their owner only | Users with mis-assigned scopes lose visibility (correct behaviour, operator surprise possible) | `DataScopeEnforcementTest` |
| `2ffbe9c` | Server-side forced password change (A-04) | Users with `must_change_password` get 403 `PASSWORD_CHANGE_REQUIRED` on APIs until they change it | None beyond intent | `ForcedPasswordChangeTest` |
| `87604a6` | `access:bootstrap-admin`, `access:assign-role`, `locations:provision` CLI; login throttle (A-03) | New CLI only (no API change); login 429 above 30/min/IP or 10/min/IP+user | Shared NAT at a school could hit 30/min/IP at shift change (configurable) | `AccessBootstrapCommandsTest` |
| `21ac74e` | Ingestion race → idempotent (A-07); broadcast after commit (A-08) | No 500 on duplicate delivery; Reverb outage no longer loses events | Realtime may lag a failed broadcast; recovery via events API | `IngestionResilienceTest` |
| `d497fb2` | Health probes outside session; DB-cache scheduler locks; DB/Redis timeouts; preflight FAILs on mock/disabled PAD | Correct 503s; scheduler survives Redis outage; preflight now FAILs while `AI_MODE=mock` | Preflight blocks a *biometric* go-live until B5 (intended) | `PilotPreflightCommandTest` |
| `2e4605a` | Edge node orchestrator + `deploy/ai-node` kit | New runtime on the AI node (heartbeat, config sync, event delivery) | Never run on real hardware/cameras (R-09) | `test_node_orchestrator` (AI suite) |
| `b4f9b3a` (branch) | ruff formatting | none | none | AI suite |
| `b93197b`, `57f4f64` + extension (branch) | uuid filters validated before `exists` | Malformed uuid filter → 422 instead of 500 | none | `MalformedUuidFilterTest`, `EventHistoryApiTest` |
| branch | Dev seeder allowlist, ApiException log level, PostgreSQL CI job | No runtime behaviour change for production users | none | see EXISTING_SYSTEM_COMPLETION |

Thresholds (FACE-021), feature-flag locks and the RBAC matrix are **unchanged** by this delta (verified: no diff in
`config/face.php`, `FeatureFlagService::LOCKED_V1_FEATURES`, `RolePermissionSeeder`).

## 3. Reviewer checklist (human)

- [ ] Read §2 and the linked tests; spot-check `DataScopeEnforcementTest` scenarios against the pilot's scope plan.
- [ ] Confirm the login throttle limits suit the pilot site's network (shared NAT).
- [ ] Decide whether `2e4605a` (edge orchestrator) enters the pilot candidate or waits for hardware validation.
- [ ] Approve or reject: **create tag `v1.0.1-pilot`** on the approved commit (agent will not tag without approval).
- [ ] Update `docs/runbooks/DEPLOYMENT.md` and `ROLLBACK.md` to the new tag after approval.

## 4. Decision record

| Field | Value |
|---|---|
| Reviewer | _(name, role)_ |
| Date | |
| Decision | APPROVED / APPROVED WITH CONDITIONS / REJECTED |
| Approved commit | |
| Conditions | |
