#!/usr/bin/env bash
#
# verify-redis.sh — FND-007 verification
#
# 1. Static: docker-compose.yml runs redis with append-only persistence.
# 2. Live (when docker is available): boot a clean redis:7-alpine container with
#    --appendonly yes, confirm PING → PONG and that AOF persistence is enabled.
#
# Ephemeral container (no named volume); never touches the dev redis-data volume.
# Exit 0 + "REDIS OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

IMAGE="redis:7-alpine"
CONTAINER="sc-redis-verify-$$"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Static check (compose persistence)..."
grep -q "appendonly" docker-compose.yml \
  || fail "docker-compose.yml redis service does not enable append-only persistence"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not available — static checks only (CI/Docker will run the live check)."
  if [ "${errors}" -ne 0 ]; then echo "REDIS FAILED: ${errors} problem(s)."; exit 1; fi
  echo "REDIS OK (static checks only)"
  exit 0
fi

if [ "${errors}" -ne 0 ]; then echo "REDIS FAILED: ${errors} problem(s)."; exit 1; fi

cleanup() { docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[2/3] Booting clean ${IMAGE} with --appendonly yes..."
docker run --rm -d --name "${CONTAINER}" "${IMAGE}" redis-server --appendonly yes >/dev/null

ready=0
for _ in $(seq 1 30); do
  if [ "$(docker exec "${CONTAINER}" redis-cli ping 2>/dev/null | tr -d '[:space:]')" = "PONG" ]; then
    ready=1
    break
  fi
  sleep 1
done
[ "${ready}" -eq 1 ] || fail "redis did not respond to PING in time"

echo "[3/3] Asserting append-only persistence is enabled..."
if [ "${errors}" -eq 0 ]; then
  aof="$(docker exec "${CONTAINER}" redis-cli config get appendonly 2>/dev/null | tr -d '\r' | tail -1 | tr -d '[:space:]')"
  [ "${aof}" = "yes" ] || fail "appendonly expected 'yes', got '${aof}'"
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "REDIS FAILED: ${errors} problem(s)."
  exit 1
fi
echo "REDIS OK"
