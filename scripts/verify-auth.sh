#!/usr/bin/env bash
#
# verify-auth.sh — AUTH-001 verification (PostgreSQL)
#
# 1. Static: the AUTH-001 migration files exist.
# 2. App-level: the backend schema test suite passes (SQLite, host).
# 3. Live PostgreSQL (docker + backend image): run migrations against a CLEAN
#    database, assert the AUTH tables and key PG column types (uuid,
#    timestamptz) and the enum CHECK constraint, then verify rollback + fresh.
#
# Ephemeral container + network; never touches the dev postgres-data volume.
# Exit 0 + "AUTH OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

MIG="${ROOT}/apps/backend/database/migrations"
PG_IMAGE="pgvector/pgvector:pg16"
BACKEND_IMAGE="smart-campus/backend:dev"
# Throwaway APP_KEY (32 zero bytes) — no real encryption is exercised by migrate.
APP_KEY="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/4] Static: migration files present..."
for t in create_roles_table create_permissions_table create_role_permissions_table \
         create_user_roles_table create_user_permissions_table \
         create_user_sessions_table create_login_logs_table; do
  ls "${MIG}"/*"${t}".php >/dev/null 2>&1 || fail "migration for ${t} missing"
done
grep -q "uuid" "${MIG}/0001_01_01_000000_create_users_table.php" || fail "users migration not updated with uuid"

echo "[2/4] App-level schema tests (SQLite)..."
if command -v php >/dev/null 2>&1 && [ -d apps/backend/vendor ]; then
  ( cd apps/backend && php artisan test --filter AuthSchema >/dev/null 2>&1 ) || fail "AuthSchema tests failed"
else
  echo "    php/vendor unavailable — skipping (CI will run)."
fi

if ! command -v docker >/dev/null 2>&1; then
  echo "docker unavailable — skipping live PostgreSQL check."
  if [ "${errors}" -ne 0 ]; then echo "AUTH FAILED: ${errors} problem(s)."; exit 1; fi
  echo "AUTH OK (static + app checks only)"
  exit 0
fi

if [ "${errors}" -ne 0 ]; then echo "AUTH FAILED: ${errors} problem(s)."; exit 1; fi

if ! docker image inspect "${BACKEND_IMAGE}" >/dev/null 2>&1; then
  echo "    building backend image (${BACKEND_IMAGE})..."
  docker compose build backend >/dev/null 2>&1 || fail "backend image build failed"
fi

NET="sc-auth-net-$$"
PG="sc-auth-pg-$$"
cleanup() {
  docker rm -f "${PG}" >/dev/null 2>&1 || true
  docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

runb() { # run an artisan command in the backend image against the PG container
  docker run --rm --network "${NET}" \
    -e APP_KEY="${APP_KEY}" -e APP_ENV=local \
    -e DB_CONNECTION=pgsql -e DB_HOST="${PG}" -e DB_PORT=5432 \
    -e DB_DATABASE=authdb -e DB_USERNAME=authuser -e DB_PASSWORD=authpass \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
    "${BACKEND_IMAGE}" php artisan "$@"
}
psql_q() { docker exec "${PG}" psql -U authuser -d authdb -tAc "$1" 2>/dev/null | tr -d '[:space:]'; }

echo "[3/4] Clean PostgreSQL migrate..."
docker network create "${NET}" >/dev/null
docker run -d --name "${PG}" --network "${NET}" \
  -e POSTGRES_USER=authuser -e POSTGRES_PASSWORD=authpass -e POSTGRES_DB=authdb \
  "${PG_IMAGE}" >/dev/null

ready=0
for _ in $(seq 1 60); do
  docker exec "${PG}" psql -U authuser -d authdb -tAc "SELECT 1" >/dev/null 2>&1 && { ready=1; break; }
  sleep 1
done
[ "${ready}" -eq 1 ] || fail "postgres not ready"

if [ "${errors}" -eq 0 ]; then
  runb migrate --force >/dev/null 2>&1 || fail "migrate --force failed on clean PostgreSQL"
fi

echo "[4/4] Assert AUTH schema + PG types + rollback/fresh..."
if [ "${errors}" -eq 0 ]; then
  for t in users roles permissions role_permissions user_roles user_permissions user_sessions login_logs; do
    [ "$(psql_q "SELECT to_regclass('public.${t}') IS NOT NULL;")" = "t" ] || fail "table ${t} missing in PostgreSQL"
  done
  # PostgreSQL-specific types (materially different from SQLite).
  [ "$(psql_q "SELECT data_type FROM information_schema.columns WHERE table_name='users' AND column_name='uuid';")" = "uuid" ] \
    || fail "users.uuid is not a PostgreSQL uuid type"
  [ "$(psql_q "SELECT data_type FROM information_schema.columns WHERE table_name='users' AND column_name='created_at';")" = "timestampwithtimezone" ] \
    || fail "users.created_at is not timestamptz"
  # enum CHECK constraint on user_permissions.effect.
  [ "$(psql_q "SELECT count(*) FROM pg_constraint WHERE conrelid='user_permissions'::regclass AND contype='c';")" -ge 1 ] 2>/dev/null \
    || fail "no CHECK constraint on user_permissions (effect enum)"

  # Rollback then re-migrate, then fresh (development reversibility).
  runb migrate:rollback --force >/dev/null 2>&1 || fail "migrate:rollback failed"
  runb migrate --force >/dev/null 2>&1 || fail "re-migrate after rollback failed"
  runb migrate:fresh --force >/dev/null 2>&1 || fail "migrate:fresh failed"
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "AUTH FAILED: ${errors} problem(s)."
  exit 1
fi
echo "AUTH OK"
