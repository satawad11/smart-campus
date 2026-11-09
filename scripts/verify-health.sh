#!/usr/bin/env bash
#
# verify-health.sh — FND-010 verification
#
# 1. Static: the backend health routes exist (liveness + per-dependency + full),
#    the nginx gateway exposes /healthz, and docker-compose.yml defines Docker
#    healthchecks for the core services.
# 2. App-level: run the backend health test suite (in-memory, deps mocked).
#
# Exit 0 + "HEALTH OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

WEB="${ROOT}/apps/backend/routes/web.php"
NGINX_CONF="${ROOT}/infrastructure/nginx/default.conf"
COMPOSE="${ROOT}/docker-compose.yml"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Health routes present..."
for path in "/health'" "/health/database" "/health/redis" "/health/storage" "/health/ai" "/health/full"; do
  grep -q "${path}" "${WEB}" || fail "route ${path} missing from web.php"
done

echo "[2/3] Gateway /healthz + compose healthchecks present..."
grep -q "location = /healthz" "${NGINX_CONF}" || fail "nginx /healthz endpoint missing"
# Every service block that should have one.
hc_count="$(grep -c "healthcheck:" "${COMPOSE}" || true)"
[ "${hc_count}" -ge 7 ] || fail "expected >=7 compose healthchecks, found ${hc_count}"

echo "[3/3] Backend health tests..."
if command -v php >/dev/null 2>&1 && [ -d apps/backend/vendor ]; then
  ( cd apps/backend && php artisan test --filter "Health" >/dev/null 2>&1 ) \
    || fail "backend health tests failed"
else
  echo "    php/vendor unavailable — skipping app tests (CI will run them)."
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "HEALTH FAILED: ${errors} problem(s)."
  exit 1
fi
echo "HEALTH OK"
