#!/usr/bin/env bash
#
# verify-seed.sh — FND-012 verification
#
# 1. Static: the dev seed strategy exists — DemoDataSeeder + README, and
#    DatabaseSeeder wires the demo seeder (and does NOT seed business tables).
# 2. App-level: the seeder runs cleanly on a fresh DB and is development-only.
#
# Exit 0 + "SEED OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
SEEDERS="${ROOT}/apps/backend/database/seeders"
cd "${ROOT}"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/2] Static checks (seeder + docs + wiring)..."
[ -f "${SEEDERS}/DemoDataSeeder.php" ] || fail "DemoDataSeeder.php missing"
[ -f "${SEEDERS}/README.md" ] || fail "seeders README (demo data flow) missing"
grep -q "DemoDataSeeder::class" "${SEEDERS}/DatabaseSeeder.php" \
  || fail "DatabaseSeeder does not call DemoDataSeeder"
# Guard: no later-module business tables seeded early.
grep -qiE "Site::|Zone::|Role::|Permission::|AccessEvent::" "${SEEDERS}/DemoDataSeeder.php" \
  && fail "DemoDataSeeder references later-module models (should be a placeholder)"

echo "[2/2] Seeder runs cleanly + is development-only..."
if command -v php >/dev/null 2>&1 && [ -d apps/backend/vendor ]; then
  ( cd apps/backend && php artisan test --filter "DevSeeder" >/dev/null 2>&1 ) \
    || fail "seeder tests failed"
else
  echo "    php/vendor unavailable — skipping app tests (CI will run them)."
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "SEED FAILED: ${errors} problem(s)."
  exit 1
fi
echo "SEED OK"
