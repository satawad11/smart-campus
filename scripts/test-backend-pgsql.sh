#!/usr/bin/env bash
# Backend test suite against a disposable PostgreSQL 16 + pgvector database.
#
# SQLite (phpunit.xml default) cannot observe PostgreSQL-only behaviour: native
# uuid columns (22P02 on malformed input), enum CHECK constraints, pgvector and
# pg_trgm. This script is the repeatable PostgreSQL gate.
#
# Isolation: the database is a throwaway container with its data on tmpfs. It
# never touches the dev/pilot stack's volumes.
#
# Usage: scripts/test-backend-pgsql.sh [extra phpunit args]
#   e.g. scripts/test-backend-pgsql.sh --filter=MalformedUuidFilterTest
# Env:   RUNNER        docker (default): run PHPUnit in BACKEND_IMAGE on a private network
#                      local: run the host's PHP against a loopback-only published port
#                             (loads pdo_pgsql and sodium with -d; much faster where
#                             bind mounts are slow, e.g. Docker Desktop on Windows)
#        BACKEND_IMAGE (default smart-campus/backend:dev)
#        PG_IMAGE      (default pgvector/pgvector:pg16)
#        PG_PORT       (local runner; default 55432)
set -euo pipefail
# Git Bash on Windows would otherwise rewrite container paths (no-op elsewhere).
export MSYS_NO_PATHCONV=1

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUNNER="${RUNNER:-docker}"
BACKEND_IMAGE="${BACKEND_IMAGE:-smart-campus/backend:dev}"
PG_IMAGE="${PG_IMAGE:-pgvector/pgvector:pg16}"
PG_PORT="${PG_PORT:-55432}"
NET="sc-test-net-$$"
PG="sc-test-pg-$$"

cleanup() {
  docker rm -f "${PG}" >/dev/null 2>&1 || true
  docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

if [ ! -d "${ROOT}/apps/backend/vendor" ]; then
  echo "apps/backend/vendor missing: run 'composer install' in apps/backend first." >&2
  exit 2
fi

PG_ENV=(-e POSTGRES_USER=testing -e POSTGRES_PASSWORD=testing -e POSTGRES_DB=testing)
if [ "${RUNNER}" = "local" ]; then
  docker run -d --name "${PG}" --tmpfs /var/lib/postgresql/data \
    -p "127.0.0.1:${PG_PORT}:5432" "${PG_ENV[@]}" "${PG_IMAGE}" >/dev/null
else
  docker network create "${NET}" >/dev/null
  docker run -d --name "${PG}" --network "${NET}" --tmpfs /var/lib/postgresql/data \
    "${PG_ENV[@]}" "${PG_IMAGE}" >/dev/null
fi

for _ in $(seq 1 60); do
  docker exec "${PG}" pg_isready -U testing -d testing >/dev/null 2>&1 && break
  sleep 1
done
docker exec "${PG}" psql -U testing -d testing -c "SELECT 1" >/dev/null

if [ "${RUNNER}" = "local" ]; then
  cd "${ROOT}/apps/backend"
  # phpunit.xml's <env> values don't override variables that are already set.
  ext_flags=()
  php -m | grep -qi '^pdo_pgsql$' || ext_flags+=(-d extension=pdo_pgsql)
  php -m | grep -qi '^sodium$' || ext_flags+=(-d extension=sodium)
  DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT}" \
    DB_DATABASE=testing DB_USERNAME=testing DB_PASSWORD=testing DB_URL= \
    php "${ext_flags[@]}" vendor/bin/phpunit "$@"
else
  docker run --rm --network "${NET}" \
    -v "${ROOT}/apps/backend:/var/www/html" -w /var/www/html \
    -e DB_CONNECTION=pgsql -e DB_HOST="${PG}" -e DB_PORT=5432 \
    -e DB_DATABASE=testing -e DB_USERNAME=testing -e DB_PASSWORD=testing \
    -e DB_URL= \
    "${BACKEND_IMAGE}" php vendor/bin/phpunit "$@"
fi
