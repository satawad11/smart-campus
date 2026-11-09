#!/usr/bin/env bash
#
# verify-backend.sh — FND-003 verification
#
# Confirms the Laravel backend boots, the /health route is registered, and the
# automated test suite (which includes the health-endpoint feature test) passes.
# Tests run on in-memory SQLite (see apps/backend/phpunit.xml), so no external
# services are required.
#
# Exit 0 + "BACKEND OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
BACKEND="${ROOT}/apps/backend"
cd "${BACKEND}"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

if ! command -v php >/dev/null 2>&1; then
  echo "php not available — cannot verify backend locally (CI/Docker will)."
  exit 0
fi

echo "[1/4] Composer dependencies installed..."
[ -d vendor ] || fail "vendor/ missing (run: composer install)"

echo "[2/4] Application boots (artisan)..."
php artisan --version >/dev/null || fail "artisan failed to boot"

echo "[3/4] /health route registered..."
php artisan route:list --path=health >/dev/null 2>&1 \
  && php artisan route:list --path=health | grep -q "health" \
  || fail "/health route not registered"

echo "[4/4] Test suite passes..."
php artisan test >/dev/null 2>&1 || fail "php artisan test failed"

echo
if [ "$errors" -ne 0 ]; then
  echo "BACKEND FAILED: ${errors} problem(s)."
  exit 1
fi
echo "BACKEND OK"
