#!/usr/bin/env bash
#
# verify-storage.sh — FND-008 verification
#
# 1. Static: bucket manifest lists the four approved buckets; docker-compose.yml
#    defines the minio service; config/filesystems.php defines the per-bucket
#    S3 disks.
# 2. Live (when docker is available): boot a clean MinIO container, create the
#    approved buckets with minio/mc, and assert every one exists.
#
# Ephemeral container + network (no named volume); never touches dev data.
# Exit 0 + "STORAGE OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

BUCKETS_FILE="${ROOT}/infrastructure/minio/buckets.txt"
COMPOSE="${ROOT}/docker-compose.yml"
FS_CONFIG="${ROOT}/apps/backend/config/filesystems.php"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Static checks (manifest, compose, filesystem disks)..."
[ -f "${BUCKETS_FILE}" ] || fail "missing infrastructure/minio/buckets.txt"
mapfile -t BUCKETS < <(grep -vE '^\s*(#|$)' "${BUCKETS_FILE}" 2>/dev/null || true)
for b in person-photos face-samples event-snapshots report-exports; do
  printf '%s\n' "${BUCKETS[@]}" | grep -qx "$b" || fail "bucket '$b' not in manifest"
done
grep -qE "^\s{2}minio:" "${COMPOSE}" || fail "minio service missing from docker-compose.yml"
for disk in person_photos face_samples event_snapshots report_exports; do
  grep -q "'${disk}' =>" "${FS_CONFIG}" || fail "disk '${disk}' missing from filesystems.php"
done

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not available — static checks only (CI/Docker will run the live check)."
  if [ "${errors}" -ne 0 ]; then echo "STORAGE FAILED: ${errors} problem(s)."; exit 1; fi
  echo "STORAGE OK (static checks only)"
  exit 0
fi

if [ "${errors}" -ne 0 ]; then echo "STORAGE FAILED: ${errors} problem(s)."; exit 1; fi

NET="sc-storage-net-$$"
MINIO="sc-minio-verify-$$"
cleanup() {
  docker rm -f "${MINIO}" >/dev/null 2>&1 || true
  docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[2/3] Booting clean MinIO + creating buckets..."
docker network create "${NET}" >/dev/null
docker run -d --name "${MINIO}" --network "${NET}" \
  -e MINIO_ROOT_USER=verify \
  -e MINIO_ROOT_PASSWORD=verify12345 \
  minio/minio server /data >/dev/null

bucket_list="${BUCKETS[*]}"
if ! docker run --rm --network "${NET}" --entrypoint sh quay.io/minio/mc -c "
  set -e
  for i in \$(seq 1 30); do
    mc alias set sc http://${MINIO}:9000 verify verify12345 >/dev/null 2>&1 && \
    mc ls sc >/dev/null 2>&1 && break
    sleep 1
  done
  for b in ${bucket_list}; do mc mb --ignore-existing \"sc/\$b\"; done
  for b in ${bucket_list}; do mc stat \"sc/\$b\" >/dev/null; done
" >/dev/null 2>&1; then
  fail "bucket creation/verification failed in MinIO"
fi

echo "[3/3] Done."
echo
if [ "${errors}" -ne 0 ]; then
  echo "STORAGE FAILED: ${errors} problem(s)."
  exit 1
fi
echo "STORAGE OK"
