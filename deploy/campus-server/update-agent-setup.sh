#!/usr/bin/env bash
# AI Smart Campus — set up updates from the web app (ACR-OPS-01).
# Called by install.sh (install and update) as root:  update-agent-setup.sh <install dir>
#
#   update-state/      root 0755, token-free JSON for the web app (mounted read-only in backend)
#   update-requests/   owned by the backend's www-data (uid 82), 0700, mounted read-write in
#                      backend only; the web app drops signed requests in inbox/
#   update-logs/       root 0700, full logs of each web-triggered job
#   UPDATE_AGENT_KEY   HMAC key in campus.env shared by backend and agent
#   systemd            smart-campus-update-agent.path/.service (react to inbox),
#                      smart-campus-update-check.timer/.service (daily release check)
#
# Idempotent. Web updates can be switched off with: sudo smart-campus web-update off
set -euo pipefail

INSTALL_DIR="${1:?usage: update-agent-setup.sh <install dir>}"
SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${INSTALL_DIR}/campus.env"
WWW_UID=82 # www-data in php:*-alpine (apps/backend/Dockerfile)
UNIT_DIR="${SC_SYSTEMD_DIR:-/etc/systemd/system}"

if [ "${SRC_DIR}" != "${INSTALL_DIR}" ]; then
    install -m 0755 "${SRC_DIR}/update-agent.py" "${INSTALL_DIR}/update-agent.py"
fi

install -d -m 0755 "${INSTALL_DIR}/update-state"
install -d -m 0700 -o "${WWW_UID}" -g "${WWW_UID}" "${INSTALL_DIR}/update-requests"
install -d -m 0700 -o "${WWW_UID}" -g "${WWW_UID}" "${INSTALL_DIR}/update-requests/inbox" "${INSTALL_DIR}/update-requests/tmp"
install -d -m 0700 "${INSTALL_DIR}/update-logs"

if ! grep -qE '^UPDATE_AGENT_KEY=.{32,}' "${ENV_FILE}"; then
    sed -i '/^UPDATE_AGENT_KEY=/d' "${ENV_FILE}"
    printf 'UPDATE_AGENT_KEY=%s\n' "$(openssl rand -hex 32)" >> "${ENV_FILE}"
fi

if ! command -v python3 >/dev/null || ! command -v systemctl >/dev/null || [ ! -d /run/systemd/system ]; then
    printf '{"enabled": false, "reason": "NO_SYSTEMD_OR_PYTHON"}\n' > "${INSTALL_DIR}/update-state/agent.json"
    echo "web update disabled: needs python3 and systemd" >&2
    exit 0
fi

cat > "${UNIT_DIR}/smart-campus-update-agent.path" <<UNIT
[Unit]
Description=AI Smart Campus: watch web update requests (ACR-OPS-01)

[Path]
DirectoryNotEmpty=${INSTALL_DIR}/update-requests/inbox

[Install]
WantedBy=multi-user.target
UNIT

cat > "${UNIT_DIR}/smart-campus-update-agent.service" <<UNIT
[Unit]
Description=AI Smart Campus: run web update requests (ACR-OPS-01)
After=docker.service network-online.target

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 -I ${INSTALL_DIR}/update-agent.py run --install-dir ${INSTALL_DIR}
TimeoutStartSec=3h
# Stopping the unit must never kill install.sh half-way through an update.
KillMode=process
UNIT

cat > "${UNIT_DIR}/smart-campus-update-check.service" <<UNIT
[Unit]
Description=AI Smart Campus: check GitHub for new releases (ACR-OPS-01)
After=network-online.target

[Service]
Type=oneshot
ExecCondition=/usr/bin/test -f ${INSTALL_DIR}/update.env
ExecStart=/usr/local/bin/smart-campus update-check
UNIT

cat > "${UNIT_DIR}/smart-campus-update-check.timer" <<UNIT
[Unit]
Description=AI Smart Campus: daily release check (ACR-OPS-01)

[Timer]
OnCalendar=*-*-* 06:00
RandomizedDelaySec=1h
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload
systemctl enable --now smart-campus-update-check.timer >/dev/null 2>&1
# Respect an administrator's earlier "web-update off".
if [ ! -f "${INSTALL_DIR}/.web-update-off" ]; then
    systemctl enable --now smart-campus-update-agent.path >/dev/null 2>&1
    printf '{"enabled": true, "reason": null}\n' > "${INSTALL_DIR}/update-state/agent.json"
else
    printf '{"enabled": false, "reason": "DISABLED_BY_ADMIN"}\n' > "${INSTALL_DIR}/update-state/agent.json"
fi
chmod 0644 "${INSTALL_DIR}/update-state/agent.json"
