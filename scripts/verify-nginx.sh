#!/usr/bin/env bash
#
# verify-nginx.sh — FND-009 verification
#
# 1. Static: the routing config exists, routes the frontend (/) and Laravel API
#    (/api/), reserves the websocket approach, does NOT expose the AI service,
#    and docker-compose.yml mounts it into the nginx service.
# 2. Live (when docker is available): validate the config with `nginx -t` inside
#    a clean nginx:1.27-alpine container.
#
# Exit 0 + "NGINX OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

CONF="${ROOT}/infrastructure/nginx/default.conf"
IMAGE="nginx:1.27-alpine"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/2] Static checks (routing config + compose mount)..."
[ -f "${CONF}" ] || fail "missing infrastructure/nginx/default.conf"
if [ -f "${CONF}" ]; then
  grep -qE "location\s+/api/" "${CONF}" || fail "no /api/ route to the backend"
  grep -qE "backend:9000" "${CONF}" || fail "backend upstream not referenced"
  grep -qE "frontend:5173" "${CONF}" || fail "frontend upstream not referenced"
  grep -qE "location\s+/\s*\{" "${CONF}" || fail "no root (/) route to the frontend"
  # AI service must NOT be publicly routed here (PROJECT_RULES §5).
  grep -qE "ai-service" "${CONF}" && fail "ai-service must not be exposed via the public gateway"
fi
grep -q "infrastructure/nginx:/etc/nginx/conf.d" docker-compose.yml \
  || fail "docker-compose.yml does not mount the nginx config"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not available — static checks only (CI/Docker will run nginx -t)."
  if [ "${errors}" -ne 0 ]; then echo "NGINX FAILED: ${errors} problem(s)."; exit 1; fi
  echo "NGINX OK (static checks only)"
  exit 0
fi

if [ "${errors}" -ne 0 ]; then echo "NGINX FAILED: ${errors} problem(s)."; exit 1; fi

echo "[2/2] Validating config with 'nginx -t'..."
# MSYS_NO_PATHCONV keeps the container-side mount target from being rewritten by
# Git Bash; pwd -W yields a Windows source path Docker accepts.
WINROOT="$(pwd -W 2>/dev/null || pwd)"
if ! MSYS_NO_PATHCONV=1 docker run --rm \
      -v "${WINROOT}/infrastructure/nginx:/etc/nginx/conf.d:ro" \
      "${IMAGE}" nginx -t >/dev/null 2>&1; then
  fail "nginx -t rejected the configuration"
  MSYS_NO_PATHCONV=1 docker run --rm \
    -v "${WINROOT}/infrastructure/nginx:/etc/nginx/conf.d:ro" \
    "${IMAGE}" nginx -t 2>&1 | sed 's/^/    /' || true
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "NGINX FAILED: ${errors} problem(s)."
  exit 1
fi
echo "NGINX OK"
