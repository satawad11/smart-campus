#!/usr/bin/env bash
# Upgrade a campus instance to an approved release tag (COM-04).
#
# Automates docs/runbooks/DEPLOYMENT.md §3 for an EXISTING installation and
# adds what the runbook lacked: a verified backup BEFORE anything changes.
# Stops at the first failure and prints the rollback steps.
#
# Usage: scripts/upgrade-campus.sh <release-tag> [--dry-run] [--yes]
#   --dry-run  print every step, change nothing
#   --yes      don't ask for confirmation
# Env:   DC                compose command (default: the runbook's pilot overlay)
#        MIGRATION_DB_PASSWORD  required for migrations (campus_migration_user)
#        MAINTENANCE_BYPASS_TOKEN  optional `artisan down --secret`
#
# Never run against an unapproved tag: see docs/commercial/RELEASE_CHECKLIST.md.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

TAG=""
DRY_RUN=0
ASSUME_YES=0
for arg in "$@"; do
  case "${arg}" in
    --dry-run) DRY_RUN=1 ;;
    --yes) ASSUME_YES=1 ;;
    -*) echo "unknown option: ${arg}" >&2; exit 2 ;;
    *) TAG="${arg}" ;;
  esac
done
[ -n "${TAG}" ] || { echo "usage: $0 <release-tag> [--dry-run] [--yes]" >&2; exit 2; }

DC="${DC:-docker compose --env-file apps/backend/.env -f docker-compose.yml -f docker-compose.pilot.yml}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
STATE_FILE="${ROOT}/.upgrade-state"

run() {
  echo "+ $*"
  if [ "${DRY_RUN}" -eq 0 ]; then
    eval "$@"
  fi
}

fail() {
  echo >&2
  echo "UPGRADE FAILED at: $1" >&2
  echo "Rollback (docs/runbooks/ROLLBACK.md):" >&2
  echo "  docker tag smart-campus/backend:rollback-${STAMP}  smart-campus/backend:pilot" >&2
  echo "  docker tag smart-campus/frontend:rollback-${STAMP} smart-campus/frontend:pilot" >&2
  echo "  git checkout ${PREVIOUS_REF:-<previous tag>} && ${DC} up -d --wait" >&2
  echo "  Schema/data problems: restore the pre-upgrade backup into a NEW database (ROLLBACK.md §3.3 B)." >&2
  echo "  The instance may still be in maintenance mode: ${DC} exec backend php artisan up" >&2
  exit 1
}

echo "== Preflight"
git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null || { echo "tag ${TAG} not found (git fetch --tags?)" >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "working tree not clean; refusing to upgrade" >&2; exit 1; }
[ -f apps/backend/.env ] || { echo "apps/backend/.env missing" >&2; exit 1; }
[ -n "${MIGRATION_DB_PASSWORD:-}" ] || [ "${DRY_RUN}" -eq 1 ] || { echo "MIGRATION_DB_PASSWORD is required" >&2; exit 1; }
PREVIOUS_REF="$(git describe --tags --exact-match 2>/dev/null || git rev-parse --short HEAD)"
echo "current: ${PREVIOUS_REF}  ->  target: ${TAG}"
if [ "${PREVIOUS_REF}" = "${TAG}" ]; then
  echo "already on ${TAG}; nothing to do"
  exit 0
fi

if [ "${DRY_RUN}" -eq 0 ] && [ "${ASSUME_YES}" -eq 0 ]; then
  read -r -p "Upgrade this instance from ${PREVIOUS_REF} to ${TAG}? [y/N] " answer
  answer="$(printf '%s' "${answer}" | LC_ALL=C tr -cd 'A-Za-z' | LC_ALL=C tr 'A-Z' 'a-z')"
  [ "${answer}" = "y" ] || [ "${answer}" = "yes" ] || { echo "aborted"; exit 1; }
fi

echo "== 1. Verified backup before any change"
run "${DC} exec -T backend php artisan backup:run --type=ALL --verify" || fail "pre-upgrade backup (must exit 0)"

echo "== 2. Keep current images for rollback, enter maintenance mode"
run "docker tag smart-campus/backend:pilot smart-campus/backend:rollback-${STAMP}" || fail "tag backend image"
run "docker tag smart-campus/frontend:pilot smart-campus/frontend:rollback-${STAMP}" || fail "tag frontend image"
if [ "${DRY_RUN}" -eq 0 ]; then
  printf 'previous_ref=%s\nrollback_tag=rollback-%s\nstarted_at=%s\ntarget=%s\n' \
    "${PREVIOUS_REF}" "${STAMP}" "${STAMP}" "${TAG}" > "${STATE_FILE}"
fi
SECRET_ARG=""
[ -n "${MAINTENANCE_BYPASS_TOKEN:-}" ] && SECRET_ARG="--secret=\"\${MAINTENANCE_BYPASS_TOKEN}\""
run "${DC} exec -T backend php artisan down --retry=60 ${SECRET_ARG}" || fail "maintenance mode"

echo "== 3. Checkout and build"
run "git checkout -q tags/${TAG}" || fail "checkout ${TAG}"
run "${DC} config --quiet" || fail "compose config (missing secret?)"
if [ "${AI_MODE:-}" = "onnx" ]; then
  # The model weights are intentionally not committed. Fetch the pinned files,
  # verify their checksums, and bake them into the campus AI service. The same
  # verified directory is then available to the edge-node packaging script.
  # shellcheck source=SCRIPTDIR/face-models/ensure.sh
  . "${ROOT}/scripts/face-models/ensure.sh"
  run "ensure_face_models" || fail "verify production face models"
fi
run "${DC} build backend frontend ai-service" || fail "image build"

echo "== 4. Migrate and approved seed"
run "${DC} up -d --wait backend" || fail "start backend"
M="-e DB_USERNAME=campus_migration_user -e DB_PASSWORD=\"\${MIGRATION_DB_PASSWORD}\""
run "${DC} exec -T ${M} backend php artisan migrate --force" || fail "migrations"
run "${DC} exec -T ${M} backend php artisan db:seed --force" || fail "approved seed"
run "${DC} exec -T ${M} backend php artisan db:seed --class=SystemSettingSeeder --force" || fail "system settings seed"

echo "== 5. Caches and services"
run "${DC} exec -T backend sh -c 'php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache'" || fail "cache optimisation"
run "${DC} up -d --wait" || fail "start services"

echo "== 6. Verification"
run "${DC} exec -T backend php artisan pilot:preflight" || echo "WARNING: preflight reported FAIL items; review them before leaving maintenance mode." >&2

echo "== 7. Leave maintenance mode"
run "${DC} exec -T backend php artisan up" || fail "leave maintenance mode"

echo
echo "Upgrade to ${TAG} finished. Rollback images: smart-campus/{backend,frontend}:rollback-${STAMP}."
echo "Next: smoke test (PILOT-HOST-DEPLOYMENT-CHECKLIST §10); upgrade AI nodes last (deploy/ai-node/update.sh)."
