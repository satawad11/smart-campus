#!/usr/bin/env bash
#
# verify-structure.sh — FND-001 verification
#
# Asserts the approved monorepo directory skeleton
# (docs/04-development-blueprint.md §1 + IMPLEMENTATION.md) exists, that the
# pre-existing spec/control files are present, and that NO later-task /
# framework artifacts have leaked into the tree (FND-002+ out-of-scope gate).
#
# Exit 0 + "STRUCTURE OK" on success; non-zero with a clear message otherwise.

set -euo pipefail

# Resolve repo root as the parent of this script's directory.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

errors=0
fail() { echo "  MISSING/INVALID: $1"; errors=$((errors + 1)); }
forbid() { echo "  OUT-OF-SCOPE ARTIFACT PRESENT: $1"; errors=$((errors + 1)); }

# --- Required directories (approved skeleton) --------------------------------
required_dirs=(
  "apps"
  "apps/backend"
  "apps/frontend"
  "apps/ai-service"
  "infrastructure"
  "infrastructure/nginx"
  "infrastructure/postgres"
  "infrastructure/monitoring"
  "docker"
  "scripts"
  "tests"
  "tests/Acceptance"
  "tests/Architecture"
  "tests/Security"
  "docs"
  "contracts"
  "prompts"
)

# --- Required pre-existing control/spec files --------------------------------
required_files=(
  "PROJECT_RULES.md"
  "ACCEPTANCE_CRITERIA.md"
  "TASKS.md"
  "IMPLEMENTATION.md"
  "README.md"
  "docs/00-system-blueprint.md"
  "docs/04-development-blueprint.md"
)

# --- Forbidden (belongs to FND-004+ / framework bootstrap) -------------------
# NOTE: docker-compose.yml is expected from FND-002 onward (verified by
# scripts/verify-compose.sh). The Laravel backend is bootstrapped in FND-003
# (verified by scripts/verify-backend.sh), so apps/backend/* is no longer
# forbidden. Frontend (FND-004) and AI service (FND-005) remain placeholders.
forbidden_paths=(
  "apps/frontend/package.json"
  "apps/ai-service/requirements.txt"
  "apps/ai-service/pyproject.toml"
  ".env"
)

echo "Verifying monorepo structure at: ${ROOT}"

echo "[1/4] Required directories..."
for d in "${required_dirs[@]}"; do
  [ -d "$d" ] || fail "directory '$d'"
done

echo "[2/4] Placeholders in still-empty leaf dirs..."
# apps/backend is bootstrapped in FND-003, so it no longer carries a .gitkeep.
for d in apps/frontend apps/ai-service \
         infrastructure/nginx infrastructure/postgres infrastructure/monitoring \
         docker tests/Acceptance tests/Architecture tests/Security; do
  [ -f "$d/.gitkeep" ] || fail ".gitkeep in '$d'"
done

echo "[3/4] Required control/spec files..."
for f in "${required_files[@]}"; do
  [ -f "$f" ] || fail "file '$f'"
done

echo "[4/4] Out-of-scope artifacts must be absent..."
for p in "${forbidden_paths[@]}"; do
  [ -e "$p" ] && forbid "$p"
done
# No framework/service directories should exist yet under CI either.
[ -d ".github/workflows" ] && forbid ".github/workflows (FND-011)"

echo
if [ "$errors" -ne 0 ]; then
  echo "STRUCTURE FAILED: ${errors} problem(s) found."
  exit 1
fi
echo "STRUCTURE OK"
