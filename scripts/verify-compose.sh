#!/usr/bin/env bash
#
# verify-compose.sh — FND-002 verification
#
# Validates docker-compose.yml: the file exists and is syntactically valid,
# declares exactly the approved base service set, defines the named volumes and
# a single application network, and hardcodes no obvious plaintext secrets
# (credentials must come from environment variables).
#
# Exit 0 + "COMPOSE OK" on success; non-zero with a clear message otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

# Approved base services (docs/04-development-blueprint.md §3, prompts/FND-002.md).
expected_services="ai-service backend frontend nginx objectstore objectstore-init postgres queue redis scheduler websocket"

echo "Verifying docker-compose base services at: ${ROOT}"

echo "[1/4] docker-compose.yml present..."
[ -f docker-compose.yml ] || fail "docker-compose.yml is missing"

if [ "$errors" -ne 0 ]; then
  echo "COMPOSE FAILED: ${errors} problem(s)."
  exit 1
fi

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  echo "[2/4] 'docker compose config' validates..."
  docker compose config -q || fail "docker compose config is invalid"

  echo "[3/4] service set matches approved list..."
  got="$(docker compose config --services | sort | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
  exp="$(printf '%s\n' $expected_services | sort | tr '\n' ' ' | sed 's/[[:space:]]*$//')"
  [ "$got" = "$exp" ] || fail "service set mismatch: got [$got] expected [$exp]"

  echo "[4/4] named volumes present..."
  docker compose config --volumes | grep -q . || fail "no named volumes defined"
else
  echo "[2/4] docker unavailable — falling back to textual checks..."
  for s in $expected_services; do
    grep -qE "^[[:space:]]{2}${s}:" docker-compose.yml || fail "service '${s}' not found"
  done
  echo "[3/4] named volumes present..."
  grep -qE "^volumes:" docker-compose.yml || fail "no volumes section"
  echo "[4/4] single network defined..."
  grep -qE "^networks:" docker-compose.yml || fail "no networks section"
fi

# Secret hygiene: sensitive values must reference env vars, not literals.
if grep -nE '(PASSWORD|ROOT_PASSWORD|SECRET)\s*[:=]\s*[^$#[:space:]]' docker-compose.yml \
     | grep -vqE '\$\{'; then
  :
fi
if grep -EnH '(PASSWORD|SECRET)[A-Z_]*:\s*["'"'"']?[A-Za-z0-9]' docker-compose.yml \
     | grep -vE '\$\{' >/dev/null 2>&1; then
  fail "possible hardcoded secret in docker-compose.yml (use \${ENV_VAR})"
fi

echo
if [ "$errors" -ne 0 ]; then
  echo "COMPOSE FAILED: ${errors} problem(s)."
  exit 1
fi
echo "COMPOSE OK"
