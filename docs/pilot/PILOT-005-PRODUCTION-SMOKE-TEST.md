# PILOT-005 — Production Smoke Test

**Status: PASS (automated smoke, rehearsal environment) / PENDING (live pilot host, physical and biometric checks)**
**Executed:** 2026-09-29T19:18:48Z, re-run on the final image at 2026-09-29T19:44:14Z (30/30). Rehearsal container, `APP_ENV=production`

Additional checks on the refreshed dev stack, through the nginx gateway: all six `/health/*` probes
return `200 application/json`; Reverb WebSocket handshake `101 Switching Protocols`; queue worker
up with no restarts; scheduler running (`ai-nodes:detect-stale` every minute).

---

## 1. Automated Smoke Results (TEST DATA, rehearsal)

Run with a script inside the production-mode rehearsal container, against `php artisan serve`.
It is read-only except for one database transaction that is rolled back.
**Result: 30 passed, 0 failed.**

| # | Check | Expected | Result |
|---|---|---|---|
| 1–6 | `GET /health`, `/health/database`, `/health/redis`, `/health/storage`, `/health/ai`, `/health/full` | 200 | PASS (6/6) |
| 7–11 | `GET /api/v1/features`: door, visitor, vehicle, alpr, ai_assistant | `false` | PASS (5/5) |
| 12–16 | `GET /api/v1/doors`, `/visitors`, `/vehicles`, `/alpr`, `/ai-assistant` (unauthenticated) | 401 | PASS (5/5) |
| 17–18 | `GET /api/v1/users`, `/api/v1/settings` (unauthenticated) | 401 | PASS |
| 19 | `POST /api/v1/auth/login` without CSRF token | 419 | PASS |
| 20–23 | Security headers: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, HSTS, CSP | present | PASS |
| 24 | CORS: configured origin allowed | echoed | PASS |
| 25 | CORS: foreign origin (`https://evil.example`) | not echoed | PASS |
| 26 | Unknown route returns no stack trace or exception detail | no trace | PASS |
| 27 | **Door disabled even if an admin enables it**: `updateGroup(feature.door=true, feature.alpr=true)`, then `isEnabled` | BLOCKED | PASS (transaction rolled back) |
| 28–30 | Scheduled jobs registered: `ai-nodes:detect-stale`, `events:refresh-summaries`, `retention:cleanup` | present | PASS |

Authenticated feature gates (403 `FEATURE_DISABLED` for an authenticated user with all
permissions, kill switch engaged) are covered by `FeatureKillSwitchTest`, and by
`FeatureFlagsApiTest` (SEC-002) with the switch unset.

Smoke-script defect found and fixed during the run: the first version treated any
`Access-Control-Allow-Origin` header on a foreign-origin request as a failure. With a
single configured origin the CORS layer always returns that fixed origin, which a browser
rejects for any other origin. The check was corrected to assert that the foreign origin is
never echoed. It was a defect in the smoke check, not in the application. The first run's
result was 29/30.

### Gateway-level probe defect (P08-12)
Before this phase, `/health/*` requests through nginx were answered by the SPA
(HTTP 200, HTML). The runbook's `curl -f https://…/health/…` checks would have
passed even with the backend down. nginx now routes these paths to the backend; see
PILOT-002 §7 for the gateway re-check.

---

## 2. Live Smoke Checklist (pilot host, PENDING, human-executed)

| # | Check | Result | By / time |
|---|---|---|---|
| L1 | `pilot:preflight --require-backup` PASS | PENDING | |
| L2 | Health probes through the **public HTTPS gateway** return JSON 200 | PENDING | |
| L3 | Login with a real operator account + MFA; forced password change works | PENDING | |
| L4 | `/api/v1/features` shows all locked features false; the door UI isn't reachable | PENDING | |
| L5 | Both cameras ONLINE on `/cameras`; AI node ONLINE on `/ai-nodes` | PENDING (physical) | |
| L6 | Enrolled pilot volunteer walks through → event appears on Live Monitor within target latency, classification and decision shown separately | PENDING (physical, biometric) | |
| L7 | Non-enrolled volunteer → UNKNOWN; appears on `/unknown-persons` | PENDING (physical) | |
| L8 | REVIEW event can be reviewed; the decision is append-only and audited | PENDING | |
| L9 | Live Monitor reconnect recovers missed events (stop and restart websocket) | PENDING | |
| L10 | **Gate is not actuated by the system** (door control disabled); manual gate procedure confirmed | PENDING | |
| L11 | Daily report renders for the smoke day | PENDING | |

Use consenting pilot volunteers for L6–L7. Record outcomes as pass/fail and event
UUIDs only. No face images or names in this document.

## Pre-Deployment Update (2026-09-30)

Production candidate: code at `72ee47b`, release tag `v1.0.0-pilot`. Full package and rehearsal: `PILOT-HOST-DEPLOYMENT-CHECKLIST.md`; delta review: `PILOT-000`.

**Status: PENDING PHYSICAL WALKTHROUGH.** HTTPS smoke through the pilot TLS gateway (overlay rehearsal, final
images): **26/26 PASS**. Covered: 6 health probes JSON, HTTP→HTTPS 301, 5 locked features false, 7 protected or locked
routes 401, CSRF 419, HSTS, session cookie Secure + HttpOnly, static SPA (no Vite client), deep-link fallback, WSS
handshake 101. Live checks L1–L11 need the pilot host, cameras, AI node and volunteers. Biometric checks (L6/L7) are
also blocked by B5 (no production model).
