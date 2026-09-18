#!/usr/bin/env bash
#
# verify-postgres.sh — FND-006 verification
#
# 1. Static: the extension init script exists and docker-compose.yml wires it
#    into the postgres service's /docker-entrypoint-initdb.d.
# 2. Live (when docker is available): boot a CLEAN, throwaway container from the
#    locked pgvector image, apply the init SQL to an empty database, and assert
#    the `vector` and `pg_trgm` extensions are enabled.
#
# The live check uses an ephemeral container (no named volume) and pipes the SQL
# via stdin, so it starts from an empty DB every run and never touches the dev
# `postgres-data` volume.
#
# Exit 0 + "POSTGRES OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

SQL="${ROOT}/infrastructure/postgres/init/001-extensions.sql"
IMAGE="pgvector/pgvector:pg16"
CONTAINER="sc-pg-verify-$$"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Static checks (init script + compose wiring)..."
[ -f "${SQL}" ] || fail "missing ${SQL#${ROOT}/}"
grep -q "docker-entrypoint-initdb.d" docker-compose.yml \
  || fail "docker-compose.yml does not mount the init dir into postgres"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not available — static checks only (CI/Docker will run the live check)."
  if [ "${errors}" -ne 0 ]; then echo "POSTGRES FAILED: ${errors} problem(s)."; exit 1; fi
  echo "POSTGRES OK (static checks only)"
  exit 0
fi

if [ "${errors}" -ne 0 ]; then echo "POSTGRES FAILED: ${errors} problem(s)."; exit 1; fi

cleanup() { docker rm -f "${CONTAINER}" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "[2/3] Booting clean ${IMAGE} and applying init SQL..."
docker run --rm -d --name "${CONTAINER}" \
  -e POSTGRES_USER=verify \
  -e POSTGRES_PASSWORD=verify \
  -e POSTGRES_DB=verify \
  "${IMAGE}" >/dev/null

# Gate on a real query succeeding (not pg_isready, which can pass against the
# temporary server the entrypoint runs during initdb, before the real restart).
ready=0
for _ in $(seq 1 60); do
  if docker exec "${CONTAINER}" psql -U verify -d verify -tAc "SELECT 1" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 1
done
[ "${ready}" -eq 1 ] || fail "postgres did not become ready in time"

if [ "${errors}" -eq 0 ]; then
  # Apply the deterministic init SQL via stdin (avoids bind-mount path issues).
  applied=0
  for _ in $(seq 1 5); do
    if docker exec -i "${CONTAINER}" psql -v ON_ERROR_STOP=1 -U verify -d verify < "${SQL}" >/dev/null 2>&1; then
      applied=1
      break
    fi
    sleep 1
  done
  [ "${applied}" -eq 1 ] || fail "applying init SQL failed"
fi

echo "[3/3] Asserting extensions are enabled..."
if [ "${errors}" -eq 0 ]; then
  count="$(docker exec "${CONTAINER}" psql -U verify -d verify -tAc \
    "SELECT count(*) FROM pg_extension WHERE extname IN ('vector','pg_trgm');" 2>/dev/null | tr -d '[:space:]')"
  if [ "${count}" != "2" ]; then
    fail "expected vector + pg_trgm enabled, found count='${count}'"
    docker exec "${CONTAINER}" psql -U verify -d verify -c \
      "SELECT extname FROM pg_extension ORDER BY extname;" 2>/dev/null || true
  fi
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "POSTGRES FAILED: ${errors} problem(s)."
  exit 1
fi
echo "POSTGRES OK"
