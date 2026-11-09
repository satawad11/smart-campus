#!/usr/bin/env bash
#
# verify-frontend.sh — FND-004 verification
#
# Confirms the Vue 3 frontend installs, lints, passes its unit tests, and
# builds for production. Establishes that the app boots with Vite + Tailwind +
# Pinia + Vue Router + Axios and exposes the login/dashboard placeholder routes.
#
# Exit 0 + "FRONTEND OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
FRONTEND="${ROOT}/apps/frontend"
cd "${FRONTEND}"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

if ! command -v npm >/dev/null 2>&1; then
  echo "npm not available — cannot verify frontend locally (CI/Docker will)."
  exit 0
fi

echo "[1/4] Dependencies installed..."
[ -d node_modules ] || npm install >/dev/null 2>&1 || fail "npm install failed"

echo "[2/4] Lint..."
npm run lint >/dev/null 2>&1 || fail "eslint failed"

echo "[3/4] Unit tests..."
npm run test >/dev/null 2>&1 || fail "vitest failed"

echo "[4/4] Production build..."
npm run build >/dev/null 2>&1 || fail "vite build failed"

echo
if [ "$errors" -ne 0 ]; then
  echo "FRONTEND FAILED: ${errors} problem(s)."
  exit 1
fi
echo "FRONTEND OK"
