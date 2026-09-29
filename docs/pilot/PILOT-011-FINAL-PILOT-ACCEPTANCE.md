# PILOT-011 — Final Pilot Acceptance Pack

## Status: AWAITING HUMAN PILOT ACCEPTANCE

**Prepared:** 2026-09-30 by the AI coding agent (Phase 08)
**This is a human gate.** The agent doesn't approve the pilot, pilot expansion, or any
risk acceptance. Every "accepted risk" below is a **proposal** until a human signs §27.
**Future modules remain LOCKED:** DOOR-\*, VIS-\*, VEH-\*, AIA-\*. Nothing in Phase 08 unlocks them.

### Evidence labels used in this pack
- **REAL PILOT DATA**: none exists yet. The pilot hasn't started.
- **TEST DATA / REHEARSAL**: production-mode deployment rehearsal on the local Docker stack
  (`pilot_rehearsal` DB), 2026-09-29/30.
- **SYNTHETIC DATA**: earlier load and benchmark tests (EVT-021, RPT-023, CAM-020).

### Where the pilot stands
The software is ready for a pilot deployment. The pilot itself **hasn't started**. There is no
pilot host, no cameras or AI node on site, no trained operators, and no elapsed pilot days. Phase 08 found and
fixed several real defects, including two that would have silently defeated SEC-024 conditions 1
and 2. Those fixes change code after SEC-024 approval and need human acknowledgement (issue B4).

---

## 1. Pilot Scope
Approved and unchanged: **1 site, 1 gate, 2 cameras, 1 AI node, 80 students, 5 operators.**
`pilot:preflight` warns if the configured sites, cameras, AI nodes or students exceed this.
No scope expansion was made or proposed.

## 2. Production Environment Result (PILOT-001)
**Software checks PASS in rehearsal; pilot host and physical checks PENDING.** Final rehearsal
`pilot:preflight --require-backup`: 0 failures, 1 warning (Redis without password). Host items
H1–H11 (TLS/DNS, secrets, cameras, AI node, NTP, off-host backup, paging) are PENDING.

## 3. Deployment Result (PILOT-002)
**Rehearsal PASS; pilot host PENDING.** 49/49 migrations as `campus_migration_user`; approved seed
only (DemoDataSeeder self-skipped in production; InitialAdminUserSeeder not run); caches built.
Test gates: SQLite 1054 passed / 0 failed; PostgreSQL gate 1062 passed / 0 failed; vitest 149/149;
production frontend build OK.

## 4. Initial Backup Result (PILOT-003)
**Live backup PENDING (can't PASS without the pilot host).** Rehearsal `backup:run --type=ALL --verify`
exit 0, both jobs VERIFIED, 189,490-byte real pg_dump, no credentials in records or dump.
Before Phase 08 this command would have recorded an empty or simulated dump as COMPLETED and exited 0
(P08-04), skipped all MinIO data (P08-05), and lost every backup on redeploy (P08-16).

## 5. Operator Onboarding (PILOT-004)
**AWAITING HUMAN OPERATOR COMPLETION.** Training package, curriculum, quick-reference card,
competency check and sign-off sheet are ready. No attendance is recorded.

## 6. Smoke Test (PILOT-005)
**Automated rehearsal smoke 30/30 PASS**: health probes, locked features false, 401 on
protected and locked routes, CSRF 419, security headers, CORS, no debug leakage, kill switch
overrides an admin enabling door/alpr, scheduled jobs present. Live checks L1–L11 (cameras,
biometric walk-through, Live Monitor, manual gate) are PENDING.

## 7. Camera Uptime
**AWAITING PILOT ELAPSED TIME / REAL DATA.** Monitoring sources and daily log are in PILOT-006.
The SEC-020 physical results have no raw evidence (P08-07) and must be re-executed on pilot hardware.

## 8. AI Node Uptime
**AWAITING PILOT ELAPSED TIME / REAL DATA.** A stale heartbeat now raises an `AI_NODE_DOWN` CRITICAL alert
(previously no alert). The scheduler that runs the sweep had never run before Phase 08 (P08-02).

## 9. MATCH / REVIEW / UNKNOWN Statistics
**AWAITING PILOT ELAPSED TIME / REAL DATA.** Collection and labelling procedure in PILOT-007 §2–3.

## 10. False Match Evidence
**None collected. AWAITING REAL DATA.** Every sampled event gets an operator ground-truth label; false
matches are counted, never removed.

## 11. False Non-Match Evidence
**None collected. AWAITING REAL DATA.** Same procedure as §10.

## 12. PAD Statistics
**AWAITING REAL DATA.** PAD thresholds REAL ≥ 0.70, SPOOF < 0.40, INCONCLUSIVE between (stored as `UNCERTAIN`)
confirmed in config by preflight. Spoof attempts must be scripted with consenting staff (SEC-018).

## 13. Duplicate-Review Statistics
**AWAITING REAL DATA** (enrollment of the 80 pilot students hasn't happened). Threshold 0.65 confirmed.

## 14. Realtime Reliability
**Rehearsal only.** Reverb handshake `101` through the gateway; missed-event recovery is covered by
EVT-015 tests. EVT-021 (SYNTHETIC) established correctness under load; its production capacity claim
remains **NOT APPROVED / PENDING PILOT**. Live L9 check PENDING.

## 15. Event Dedup / Idempotency
**Covered by existing tests (EVT-004/005, EVT-021 SYNTHETIC concurrency).** On the pilot: verify with the
ENV-06 node power-cycle replay (PILOT-006 §5). PENDING.

## 16. Alert / Incident Statistics
**Live: AWAITING REAL DATA.** Pre-pilot: 19 technical findings logged (PILOT-008 §3). Alert lifecycle
OPEN → ACKNOWLEDGED → RESOLVED covered by SEC-012 tests; new automatic producers for AI-node-down and
backup failure.

## 17. Backup Status (PILOT-009)
**Rehearsal: two full restores into an empty DB, exit 0, 50/50 tables, row counts identical apart from
post-snapshot rows.** Pilot continuity (daily verified backups, mid-pilot restore) PENDING. Gaps:
no backup schedule or retention in code (P08-18).

## 18. Operator Feedback
**AWAITING HUMAN OPERATOR COMPLETION.** Template in PILOT-010 §3.7.

## 19. Performance / Latency
**No real measurements.** Prior results are SYNTHETIC (CAM-020, EVT-021, RPT-023). The SEC-018 target
(≤ 1500 ms P95 frame → decision) has to be measured on pilot hardware. PENDING.

## 20. Known Defects (open)
See the issue register (§26): HIGH H1–H5, MEDIUM M1–M3, LOW L1–L3.

## 21. Open Risks
1. Pilot host security posture is unverified (TLS, secrets, network isolation of Redis, PostgreSQL, MinIO).
2. Backups hold biometric source images once enrollment starts; off-host storage, encryption and access control are undefined.
3. No external paging: an overnight outage is only seen when an operator checks the dashboard.
4. Physical camera behaviour (backlight, night, rain) is unverified (P08-07).
5. Event snapshot retention is 30 days and raw event retention 90 days. Pilot evidence must be reviewed and exported (aggregates) before snapshots age out.
6. The code deployed differs from the SEC-024-approved commit by the Phase 08 fixes (B4).

## 22. Accepted Risks
**None accepted by the agent.** Proposed for human decision (§26, ACCEPTED RISK rows):
Redis without password on an isolated network; no incident UI; no external paging during the pilot
with manual dashboard checks at shift start and mid-shift.

## 23. Threshold Review Evidence
**Threshold change proposed: NO.** There is no real data. Config matches FACE-021 exactly (preflight).
Any future proposal must use the PILOT-007 §4 template and needs human approval. It is never auto-applied.

## 24. Recommendations
1. Review and acknowledge the Phase 08 pilot fix commit (B4) before deploying anything.
2. Create tag `v1.0.0-pilot` on the acknowledged commit and follow PILOT-002 §4 on the pilot host.
3. Provision the pilot host (H1–H11), then run `pilot:preflight --require-backup` until PASS.
4. Take the live initial backup (PILOT-003 §3) and copy it off-host before enrollment.
5. Complete the operator walkthrough and sign PILOT-004 before enabling live gate monitoring.
6. Re-execute SEC-020 scenarios on the pilot cameras and node with evidence.
7. Run the 7-day and 14-day reviews (PILOT-010) on real data. Only then revisit this pack.
8. Post-pilot backlog candidates (not implemented, not unlocked): scheduled backups with retention,
   external paging, camera-offline and DB/Redis alert producers, incident UI, 0600 backup files,
   production frontend serving, a fix for LEAST-PRIVILEGE-ROLES.sql.

## 25. Expansion Readiness
**NOT READY. No pilot data exists.** Expansion beyond 1 site / 1 gate / 2 cameras / 1 AI node /
80 students / 5 operators can't be assessed until the 14-day review is complete, and it requires
explicit human approval. The agent doesn't recommend or approve expansion.

---

## 26. Issue Register

| ID | Class | Issue | Source | Owner / action |
|---|---|---|---|---|
| B1 | **BLOCKER** | Pilot host not provisioned or validated (PILOT-001 H1–H11) | PILOT-001 | Infrastructure |
| B2 | **BLOCKER** | Live initial verified backup not taken (SEC-024 condition 2) | PILOT-003 | SYSTEM_ADMIN on the pilot host |
| B3 | **BLOCKER** | Operator walkthrough not completed (SEC-024 condition 3) | PILOT-004 | Pilot lead |
| B4 | **BLOCKER** | Phase 08 fix commit changes code after SEC-024 approval; human review and acknowledgement required before deploy | PILOT-002 §6 | Human reviewer |
| H1 | HIGH | Release tag `v1.0.0-pilot` missing; DEPLOYMENT.md assumes systemd, the repository ships compose (P08-09) | PILOT-002 | Human |
| H2 | HIGH | No production serving path for the built frontend; compose runs the Vite dev server (P08-14) | PILOT-001 | Infrastructure |
| H3 | HIGH | Backups not scheduled, no retention, no off-host copy (P08-18, H9) | PILOT-009 | SYSTEM_ADMIN |
| H4 | HIGH | SEC-020 physical results unverified (P08-07) | PILOT-006 | Site team |
| H5 | HIGH | No external paging; DB, Redis and camera-offline conditions produce no alert rows (PILOT-008 §2.3) | PILOT-008 | Human decision (fix or accept) |
| M1 | MEDIUM | `InitialAdminUserSeeder` (shared known password) in the repository and image (P08-08); preflight guards against it | PILOT-002 | Human |
| M2 | MEDIUM | `LEAST-PRIVILEGE-ROLES.sql` statement error and hardcoded DB name (P08-11) | PILOT-001 | Human |
| M3 | MEDIUM | Pilot evidence retention: snapshots 30 days, events 90 days; export aggregates before expiry | §21 | Pilot lead |
| L1 | LOW | No incident UI (API only) (P08-10) | PILOT-004 | — |
| L2 | LOW | Backup file mode 0644 (P08-17) | PILOT-003 | — |
| L3 | LOW | Pre-existing pint style issue in `routes/api.php` (P08-15) | PILOT-002 | — |
| AR1 | ACCEPTED RISK (proposed) | Redis without password, provided it's reachable only on the private container network | PILOT-001 | Human to accept or reject |
| AR2 | ACCEPTED RISK (proposed) | Manual dashboard checks instead of external paging for the 14-day pilot | PILOT-008 | Human to accept or reject |
| AR3 | ACCEPTED RISK (proposed) | Incidents managed through the API only during the pilot | PILOT-004 | Human to accept or reject |

Fixed in Phase 08 (tested, see PILOT-008 §3): P08-01 locked features enable-able via settings,
P08-02 queue/scheduler unconfigured, P08-03 stale images and DB, P08-04 fake/empty backups,
P08-05 MinIO excluded from backup, P08-06 no alert producers, P08-12 gateway health probes,
P08-13 buckets missing, P08-16 backups lost on redeploy, P08-19 Phase 08 test leaked a PostgreSQL transaction.

## 27. Human Decision (to be completed by the approver)

| Item | Decision |
|---|---|
| Phase 08 fix commit reviewed and acknowledged (B4) | ☐ YES ☐ NO |
| Proposed accepted risks AR1 / AR2 / AR3 | ☐ accept ☐ reject (each) |
| Pilot go-live authorised once B1–B3 are closed | ☐ YES ☐ NO |
| After the 14-day review: pilot accepted? | ☐ ACCEPT ☐ EXTEND ☐ REJECT |
| Scope expansion approved? (requires a separate decision) | ☐ NO ☐ YES (describe) |
| Future modules DOOR/VIS/VEH/AIA | remain LOCKED unless a separate approval is recorded |
| Approver name / role / date / signature | |

**AI-generated final status: AWAITING HUMAN PILOT ACCEPTANCE**
