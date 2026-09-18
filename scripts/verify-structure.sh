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

# --- Forbidden (secrets / never-commit) --------------------------------------
# NOTE: docker-compose.yml (FND-002) and the three apps — backend (FND-003),
# frontend (FND-004), ai-service (FND-005) — are all bootstrapped and verified
# by their own scripts, so they are no longer forbidden. A root-level committed
# .env would be a secret leak and stays forbidden.
forbidden_paths=(
  ".env"
)

echo "Verifying monorepo structure at: ${ROOT}"

echo "[1/4] Required directories..."
for d in "${required_dirs[@]}"; do
  [ -d "$d" ] || fail "directory '$d'"
done

echo "[2/4] Placeholders in still-empty leaf dirs..."
# All three apps (backend/frontend/ai-service) are bootstrapped, so they no
# longer carry a .gitkeep. Infra/docker/tests dirs remain placeholders.
for d in infrastructure/nginx infrastructure/postgres infrastructure/monitoring \
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
# NOTE: .github/workflows is created in FND-011 (verified by verify-ci.sh) and
# is therefore no longer forbidden.

echo
if [ "$errors" -ne 0 ]; then
  echo "STRUCTURE FAILED: ${errors} problem(s) found."
  exit 1
fi
echo "STRUCTURE OK"
