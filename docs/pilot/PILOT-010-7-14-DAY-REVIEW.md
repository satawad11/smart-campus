# PILOT-010 — 7-Day and 14-Day Pilot Review

**Status: AWAITING PILOT ELAPSED TIME / REAL DATA**
**Prepared:** 2026-09-30 (Phase 08)

The pilot hasn't started, so no day of pilot operation has elapsed. This document is the
review template and data-collection procedure. Nothing here is a result. Don't fill it in
from synthetic time, simulated events, load tests or the rehearsal stack.

---

## 1. Review Schedule

| Review | Window | Due | Chaired by | Status |
|---|---|---|---|---|
| 7-day review | pilot day 1–7 | pilot day 8 | pilot lead | AWAITING PILOT ELAPSED TIME / REAL DATA |
| 14-day review | pilot day 1–14 | pilot day 15 | pilot lead + approver | AWAITING PILOT ELAPSED TIME / REAL DATA |

Pilot day 1 = the first day live gate monitoring is enabled after SEC-024 condition 3
(operator walkthrough, PILOT-004) is signed. Record that date here: `__________`.

---

## 2. Data Sources (all read-only)

| Section | Source | Endpoint / command |
|---|---|---|
| Traffic and decisions | event summaries (RPT-003/004) | `GET /api/v1/reports/weekly`, `GET /api/v1/reports/custom` |
| Review workload | review report (RPT-012) | `GET /api/v1/reports/review` |
| Unknown persons | unknown report | `GET /api/v1/reports/unknown` |
| Camera and AI-node stability | PILOT-006 daily log | `GET /api/v1/reports/camera`, `/reports/ai-node` |
| Recognition quality | PILOT-007 labelled sample | PILOT-007 §3 |
| Alerts and incidents | PILOT-008 §4 | `GET /api/v1/system/alerts`, `/system/incidents` |
| Backups | PILOT-009 daily log | `backup_jobs`; `php artisan pilot:preflight --require-backup` |
| Configuration drift | preflight | `php artisan pilot:preflight --json` (run at each review; attach output) |

Use aggregates and public UUIDs only. No names, student IDs, face images or embeddings.

---

## 3. Review Template (complete once for day 7 and once for day 14)

### 3.1 Operations summary
| Metric | Day 1–7 | Day 1–14 |
|---|---|---|
| Gate operating days | | |
| Recognition events | | |
| Access decisions ALLOW / REVIEW / DENY | | |
| Classification MATCH / REVIEW / UNKNOWN | | |
| Manual reviews completed / median time to review | | |
| Events still pending review at window end | | |

### 3.2 Stability (from PILOT-006)
| Metric | Target (proposed) | Day 1–7 | Day 1–14 |
|---|---|---|---|
| Camera A availability | ≥ 99.0 % | | |
| Camera B availability | ≥ 99.0 % | | |
| AI-node availability | ≥ 99.5 % | | |
| Events lost during outages | 0 | | |

### 3.3 Recognition quality (from PILOT-007)
| Metric | Day 1–7 | Day 1–14 |
|---|---|---|
| Labelled genuine / impostor attempts | | |
| False matches (count) / FAR | | |
| False non-matches (count) / FRR | | |
| PAD attacks detected / attempted | | |
| Threshold change proposed? (template in PILOT-007 §4; never auto-applied) | | |

### 3.4 Incidents and alerts (from PILOT-008)
| Metric | Day 1–7 | Day 1–14 |
|---|---|---|
| CRITICAL alerts / median time to acknowledge | | |
| SEV1 / SEV2 / SEV3 incidents | | |
| Open issues at window end | | |

### 3.5 Backups (from PILOT-009)
| Metric | Day 1–7 | Day 1–14 |
|---|---|---|
| Days with a VERIFIED PostgreSQL backup | | |
| Days with a VERIFIED storage backup | | |
| Full restore test performed (date, result) | | |

### 3.6 Configuration integrity
| Check | Day 7 | Day 14 |
|---|---|---|
| `pilot:preflight` result (PASS/FAIL) | | |
| Locked features still disabled (door, visitor, vehicle, alpr, ai_assistant) | | |
| Thresholds unchanged from FACE-021 | | |
| Scope unchanged (1 site, 1 gate, 2 cameras, 1 AI node, ≤ 80 students, 5 operators) | | |

### 3.7 Operator feedback
| Operator role | Main issues reported | Suggested improvements |
|---|---|---|
| | | |

### 3.8 Review conclusion (human)
| Item | Value |
|---|---|
| Continue pilot / Pause / Roll back | |
| Issues raised to PILOT-011 (ID, severity) | |
| Reviewer name / date | |
