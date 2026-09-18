#!/usr/bin/env bash
#
# verify-ai.sh — FND-005 verification
#
# Confirms the FastAPI AI service lints, is formatted, and passes its tests
# (health, version, and mock recognize endpoints). Creates/uses a local venv.
#
# Exit 0 + "AI OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
AI="${ROOT}/apps/ai-service"
cd "${AI}"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

PYBIN="${PYTHON:-python}"
if ! command -v "$PYBIN" >/dev/null 2>&1; then
  echo "python not available — cannot verify AI service locally (CI/Docker will)."
  exit 0
fi

echo "[1/4] Virtualenv + dependencies..."
if [ ! -d .venv ]; then
  "$PYBIN" -m venv .venv || fail "venv creation failed"
fi
if [ -f .venv/Scripts/python.exe ]; then
  VENV_PY=".venv/Scripts/python.exe"
else
  VENV_PY=".venv/bin/python"
fi
"$VENV_PY" -m pip install -q -r requirements-dev.txt || fail "pip install failed"

echo "[2/4] Lint (ruff)..."
"$VENV_PY" -m ruff check . >/dev/null 2>&1 || fail "ruff check failed"

echo "[3/4] Format check (ruff)..."
"$VENV_PY" -m ruff format --check . >/dev/null 2>&1 || fail "ruff format check failed"

echo "[4/4] Tests (pytest)..."
"$VENV_PY" -m pytest -q >/dev/null 2>&1 || fail "pytest failed"

echo
if [ "$errors" -ne 0 ]; then
  echo "AI FAILED: ${errors} problem(s)."
  exit 1
fi
echo "AI OK"
