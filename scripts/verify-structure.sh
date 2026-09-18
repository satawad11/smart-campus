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

# --- Forbidden (belongs to FND-002+ / framework bootstrap) -------------------
forbidden_paths=(
  "docker-compose.yml"
  "docker-compose.yaml"
  "apps/backend/composer.json"
  "apps/backend/artisan"
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

echo "[2/4] Placeholders in leaf app/infra/docker/test dirs..."
for d in apps/backend apps/frontend apps/ai-service \
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
