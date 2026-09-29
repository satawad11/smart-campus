# PILOT-004 — Operator Onboarding Package

**Status: AWAITING HUMAN OPERATOR COMPLETION**
**Prepared:** 2026-09-30 (Phase 08, by AI coding agent)
**Satisfies:** SEC-024 go-live condition 3: mandatory operator walkthrough before live gate enablement.

No operator attendance, sign-off or competency result is recorded in this document.
The agent can't run a training session. Every attendance and sign-off field below
is blank and must be filled in by the pilot lead.

---

## 1. Pilot Operator Roster (to be completed by pilot lead)

Pilot scope allows **5 operators**. Adding more requires explicit human approval.

| # | Operator (name / staff ID) | Assigned role (canonical code) | Scope (GLOBAL / SITE / ZONE) | MFA enrolled | Walkthrough date | Trainer | Operator signature |
|---|---|---|---|---|---|---|---|
| 1 | | SECURITY_OFFICER | SITE (pilot site) | ☐ | | | |
| 2 | | SECURITY_OFFICER | SITE (pilot site) | ☐ | | | |
| 3 | | DISCIPLINE_OFFICER | SITE (pilot site) | ☐ | | | |
| 4 | | SYSTEM_ADMIN | GLOBAL | ☐ | | | |
| 5 | | EXECUTIVE_VIEWER | SITE (pilot site) | ☐ | | | |

The role mix shown is a suggestion based on the SEC-021 canonical roles. The pilot lead
decides the final assignment. Keep `SUPER_ADMIN` to a named administrator who isn't one
of the daily operators.

### Account provisioning rules
- Create accounts through **Users → New** (`/users/new`) with `must_change_password` on, so
  every operator sets their own password at first login.
- **Never run `InitialAdminUserSeeder` on the pilot host.** It creates development accounts
  (`superadmin`, `sysadmin`, `discipline`, `security`, `executive` @campus.local) that share
  one known password, with MFA off and no forced password change. `php artisan pilot:preflight`
  fails if any of them are active.
- Enable MFA for SUPER_ADMIN, SYSTEM_ADMIN and every account that can review events.
- Don't share accounts. Every review decision is attributed to the logged-in operator.

---

## 2. Walkthrough Curriculum (per operator, about 90 minutes)

Tick each item only after the operator has done it themselves on the pilot system.

### Module A: Access and account security (all roles)
| # | Task | UI location | Done |
|---|---|---|---|
| A1 | First login and forced password change | `/login` → `/change-password` | ☐ |
| A2 | MFA enrollment and MFA login | `/mfa` | ☐ |
| A3 | Logout; session expiry behaviour | user menu | ☐ |
| A4 | What to do after a lockout (contact SYSTEM_ADMIN; don't create new accounts) | — | ☐ |

### Module B: Live monitoring (SECURITY_OFFICER, DISCIPLINE_OFFICER)
| # | Task | UI location | Done |
|---|---|---|---|
| B1 | Open Live Monitor and recognise the connection status indicator | `/monitor` | ☐ |
| B2 | Read an event card: **recognition classification** (MATCH / REVIEW / UNKNOWN) is not the same as the **access decision** (ALLOW / REVIEW / DENY) | `/monitor` | ☐ |
| B3 | Missed-event recovery after a reconnect (events reload automatically) | `/monitor` | ☐ |
| B4 | Open the event detail page | `/events/:uuid` | ☐ |

### Module C: Review workflow (SECURITY_OFFICER, DISCIPLINE_OFFICER)
| # | Task | UI location | Done |
|---|---|---|---|
| C1 | Work the review queue: submit an append-only manual decision with a reason | `/events/:uuid` | ☐ |
| C2 | Understand that past decisions can't be edited, only superseded by a new decision | `/events/:uuid` | ☐ |
| C3 | Concurrency: what the "already reviewed" conflict message means | `/events/:uuid` | ☐ |
| C4 | Handle unknown persons without guessing identities | `/unknown-persons` | ☐ |
| C5 | Escalation: a suspected spoof or a wrong MATCH is reported to the pilot lead with the event UUID, **not** fixed by editing data | — | ☐ |

### Module D: History and reporting (DISCIPLINE_OFFICER, EXECUTIVE_VIEWER)
| # | Task | UI location | Done |
|---|---|---|---|
| D1 | Historical search with filters | `/history` | ☐ |
| D2 | Person timeline | `/persons/:uuid/timeline` | ☐ |
| D3 | Daily and weekly report; export (XLSX/CSV/PDF) and export expiry | `/reports` | ☐ |
| D4 | Privacy: exports contain personal data. Store them only in approved locations and never forward them outside the pilot team | — | ☐ |

### Module E: System operations (SYSTEM_ADMIN only)
| # | Task | UI location | Done |
|---|---|---|---|
| E1 | System health dashboard; camera and AI-node health | `/system/health`, `/cameras`, `/ai-nodes` | ☐ |
| E2 | Alert lifecycle OPEN → ACKNOWLEDGED → RESOLVED | `/system/health` | ☐ |
| E3 | Open an incident (SEV1–SEV3) and record the post-mortem. There is **no incident UI in V1**; use the API `POST/PUT /api/v1/system/incidents` (see PILOT-008) | API | ☐ |
| E4 | Run `php artisan pilot:preflight` and read the result | server console | ☐ |
| E5 | Backup: `php artisan backup:run --type=ALL --verify` must exit 0 | server console | ☐ |
| E6 | Settings changes are audited. **Don't change `face.*` thresholds or `feature.*` flags**: thresholds are human-approved (FACE-021) and locked features are hard-disabled by `FEATURE_*_ENABLED=false` | `/settings` | ☐ |

---

## 3. Operator Quick-Reference Card

1. **MATCH isn't ALLOW.** Recognition says who it probably is; policy decides access.
2. **REVIEW means a person decides.** Don't approve a REVIEW event you can't verify visually.
3. **UNKNOWN means not enrolled or not recognised.** Don't guess an identity.
4. **Door control is disabled in this pilot.** The system never opens a gate. Physical
   gate procedure stays manual.
5. **Something looks wrong?** Note the event UUID and the time, then report it to the pilot
   lead. Don't delete or edit anything.
6. **Camera or AI node offline?** Report it. Fall back to manual gate procedure.

---

## 4. Competency Check (pilot lead to administer)

| Question | Expected answer |
|---|---|
| An event shows MATCH 0.83 but decision REVIEW. Why? | Policy (schedule, status, zone) can require review even for a confident match |
| Can you undo a review decision you submitted by mistake? | No. Submit a new, superseding decision with a reason and tell the pilot lead |
| What do you do if the gate should open for a recognised student? | Follow the manual gate procedure. The system has no door control in this pilot |
| A student says they were marked UNKNOWN every day this week. What do you do? | Report it with the event UUIDs. Enrollment quality is reviewed by the pilot lead, not by changing thresholds |

---

## 5. Sign-Off (human only)

| Item | Value |
|---|---|
| All 5 operators completed Modules A–D (and E for SYSTEM_ADMIN) | ☐ YES ☐ NO |
| Competency check passed by all operators | ☐ YES ☐ NO |
| Pilot lead name | |
| Date | |
| Signature | |

Until this section is signed, live gate enablement is **not** permitted (SEC-024 condition 3).
