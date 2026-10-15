#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node one-time enrollment (COM-03e)
#
# Usage: ./enroll.sh <ENROLLMENT-CODE>
#
# Exchanges the code an administrator issued in the web console
# (AI node management -> enrollment code) for this node's UUID and token, and
# writes both into .env (mode 600). Set BACKEND_BASE_URL in .env first.
# The token is never printed. A code works once and expires after 30 minutes.
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo "Usage: ./enroll.sh <ENROLLMENT-CODE>" >&2
    exit 1
fi
if [ ! -f .env ]; then
    echo "ERROR: .env not found. Run ./install.sh first and set BACKEND_BASE_URL." >&2
    exit 1
fi

umask 077
RESULT="$(mktemp)"
trap 'rm -f "${RESULT}" "${RESULT}.env"' EXIT

echo "Enrolling this node with the backend..."
if ! docker compose run --rm --no-deps -T ai-node python -m app.enroll "$1" > "${RESULT}"; then
    exit 1
fi

NEW_UUID="$(grep '^AI_NODE_UUID=' "${RESULT}" | cut -d= -f2-)"
NEW_TOKEN="$(grep '^AI_NODE_TOKEN=' "${RESULT}" | cut -d= -f2-)"
if [ -z "${NEW_UUID}" ] || [ -z "${NEW_TOKEN}" ]; then
    echo "ERROR: enrollment returned no credentials; .env was not changed." >&2
    exit 1
fi

# Replace (or append) the two keys; every other line of .env is kept as is.
awk -v uuid="${NEW_UUID}" -v token="${NEW_TOKEN}" '
    /^AI_NODE_UUID=/  { print "AI_NODE_UUID=" uuid; u = 1; next }
    /^AI_NODE_TOKEN=/ { print "AI_NODE_TOKEN=" token; t = 1; next }
    { print }
    END {
        if (!u) print "AI_NODE_UUID=" uuid
        if (!t) print "AI_NODE_TOKEN=" token
    }' .env > "${RESULT}.env"
cat "${RESULT}.env" > .env
chmod 600 .env

echo "Enrolled as AI node ${NEW_UUID}; credentials saved to .env (token not shown)."
echo "Any earlier token of this node is now revoked. Apply with: ./start.sh"
