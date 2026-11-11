# ARCHITECTURE_CHANGE_REQUEST — ACR-OPS-01 System update from the web app

**Status:** APPROVED by the project owner (chat, 2026-11-10) · **Raised:** 2026-11-10 · **Requested by:** project owner
(chat, 2026-11-10: "อยากได้เมนูอัปเดตระบบในเว็บแอป …"; chose option B "one-click update",
host cache file for release data, curated Thai release notes)

```text
ARCHITECTURE_CHANGE_REQUEST

Problem:
  Updates are CLI-only (`sudo smart-campus update <version>` on the server). Administrators
  cannot see from the web app which version is installed, what changed, or what is new, and
  cannot start an update without a shell on the host.

Current specification:
  The backend container has no access to the host. Only root on the host can run
  campus.sh / install.sh (backup, docker image load, migrations, restart). The GitHub
  read-only token lives only in /opt/smart-campus/update.env (mode 600). Remote actions
  from the web are limited to allowlisted enums with no shell (T13, AI-node commands).

Proposed change:
  1. Host side (new, in the kit):
     - update-state/ directory (root-owned, 0755) holding token-free JSON written by root:
       installed.json (version, installed_at), releases.json (tag, name, published_at,
       prerelease, has_kit, body), history.json, status.json (current job). Mounted
       READ-ONLY into the backend container.
     - update-requests/ directory (owned by the backend's www-data uid, 0700). Mounted
       read-write into the backend container ONLY (not queue/scheduler/websocket/nginx).
     - systemd units: smart-campus-update-agent.path (DirectoryNotEmpty=update-requests)
       -> smart-campus-update-agent.service (root, oneshot) runs `smart-campus agent-run`;
       smart-campus-update-check.timer (daily) refreshes releases.json.
     - `smart-campus agent-run` accepts exactly two request types, as an allowlist:
       {"type":"check"} and {"type":"update","version":"<tag>"}. It verifies an HMAC-SHA256
       signature (key UPDATE_AGENT_KEY, generated at install, shared with the backend via
       campus.env), a 10-minute expiry, a one-time request id, the tag regex
       ^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?$, and that the tag is listed in
       releases.json as NEWER than the installed one with a kit for this arch. It then runs
       the existing `update <tag> --yes` path unchanged (SHA-256 check, verified backup,
       rollback images, maintenance mode, migrations, preflight). One job at a time (flock).
       No value from the request ever reaches a shell other than the validated tag.
  2. Backend (additive): GET /api/v1/system/update (state for the page),
     POST /api/v1/system/update/check, POST /api/v1/system/update (body: version,
     current_password). New permission system.update granted to SUPER_ADMIN only.
     Password re-entry is required to start an update. Every request is audited
     (SYSTEM_UPDATE_CHECK_REQUESTED, SYSTEM_UPDATE_REQUESTED, ..._REJECTED).
  3. Frontend: admin page "อัปเดตระบบ" — installed version, newer versions with Thai
     release notes rendered from Markdown (sanitised), update history, live job status,
     and an "อัปเดตเป็นรุ่นนี้" button with a confirmation dialog.
  4. Release notes: optional docs/releases/<tag>.md (Thai). release.yml uses it as the
     GitHub release body when present; otherwise keeps --generate-notes.

Reason:
  Owner request: see what changed and update from the web app, in Thai.

Impact:
  New host component (agent) running as root and triggered by the web tier. One permission,
  three endpoints, one page, one migration (permission grant), kit + install.sh changes.
  No change to existing tables or API contracts. No downgrade from the web (rollback stays CLI).

Migration impact:
  One migration that inserts system.update and grants it to SUPER_ADMIN on installs whose
  role matrix is already seeded (same pattern as student.sync). The first update to the
  version that contains the agent must still be done by CLI; later updates can use the web.

Alternatives:
  A. Read-only page with the exact CLI command to copy (no host agent, no boundary change).
  C. Backend calls the Docker socket / SSH to the host — rejected: equals root from the web tier.
```

## Threat model note (adds T16 to `docs/commercial/THREAT_MODEL.md`)

**New trust boundary:** web tier (backend container, www-data) → host root, through one request directory.

| Threat | Control |
|---|---|
| Compromised admin session starts an update | `system.update` (SUPER_ADMIN only), password re-entry, audit log, MFA if enabled, confirmation dialog. Impact is bounded: only a newer, SHA-256-verified, CI-built release from the configured repo can be installed, after a verified backup. |
| Compromised backend container (RCE) | It can only drop request files. The agent accepts two request types, a strict tag regex, and only tags already listed as newer in the root-written releases.json. No command, path, URL or option is taken from the request. Worst case: an unwanted update to a newer genuine release, or repeated `check` calls (rate-limited by the agent: one job at a time, check at most once per 5 minutes). |
| Another container or local user writes a request | Directory owned by the backend uid, mode 0700, mounted only into `backend`; HMAC signature with a key not visible to other containers. |
| Replay of an old signed request | 10-minute expiry and a processed-id list. |
| Token leak to the browser or container | The GitHub token stays in update.env on the host. update-state/ files are written by root with no token; the API returns only those fields. |
| Malicious release notes (XSS) | Notes are rendered from Markdown with raw HTML disabled and sanitised output. |
| Update breaks the system | Unchanged install.sh safeguards: verified backup first, rollback image tags, preflight; `sudo smart-campus rollback` stays available on the CLI. |
| Denial of service (update during school hours) | Confirmation dialog warns about downtime; the job status is visible to all admins on the page. Scheduling is out of scope. |

**Residual risk:** an attacker with full control of the backend container, or a SUPER_ADMIN account plus its password,
can force an update to any newer genuine release at a time of their choosing (a few minutes of downtime). Accepted only if
the owner approves this ACR. Turning the feature off: `sudo systemctl disable --now smart-campus-update-agent.path`
(the page then shows that web updates are disabled and the CLI command instead).
