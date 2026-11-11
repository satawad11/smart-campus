#!/usr/bin/env bash
#
# verify-storage.sh — FND-008 verification (object storage: SeaweedFS S3)
#
# 1. Static: bucket manifest lists the four approved buckets; docker-compose.yml
#    defines the objectstore and objectstore-init services; config/filesystems.php
#    defines the per-bucket S3 disks.
# 2. Live (when docker is available): boot a clean object store with the real
#    infrastructure/objectstore/start.sh and init.sh, then check with rclone
#    that every bucket exists and the app identity is least-privilege (can
#    write/read its buckets, cannot create buckets or set a public object ACL,
#    anonymous access refused).
#
# Ephemeral containers + network (no named volume); never touches dev data.
# Exit 0 + "STORAGE OK" on success; non-zero otherwise.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${ROOT}"

BUCKETS_FILE="${ROOT}/infrastructure/objectstore/buckets.txt"
COMPOSE="${ROOT}/docker-compose.yml"
FS_CONFIG="${ROOT}/apps/backend/config/filesystems.php"

errors=0
fail() { echo "  FAIL: $1"; errors=$((errors + 1)); }

echo "[1/3] Static checks (manifest, compose, filesystem disks)..."
[ -f "${BUCKETS_FILE}" ] || fail "missing infrastructure/objectstore/buckets.txt"
mapfile -t BUCKETS < <(grep -vE '^\s*(#|$)' "${BUCKETS_FILE}" 2>/dev/null || true)
for b in person-photos face-samples event-snapshots report-exports; do
  printf '%s\n' "${BUCKETS[@]}" | grep -qx "$b" || fail "bucket '$b' not in manifest"
done
grep -qE "^\s{2}objectstore:" "${COMPOSE}" || fail "objectstore service missing from docker-compose.yml"
grep -qE "^\s{2}objectstore-init:" "${COMPOSE}" || fail "objectstore-init service missing from docker-compose.yml"
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

IMAGE="$(grep -m1 -oE 'chrislusf/seaweedfs:[0-9.]+@sha256:[0-9a-f]+' "${COMPOSE}")"
RCLONE="$(grep -m1 -oE 'rclone/rclone:[0-9.]+@sha256:[0-9a-f]+' "${ROOT}/docker-compose.pilot.yml")"
NET="sc-storage-net-$$"
STORE="objectstore"
cleanup() {
  docker rm -f "sc-objectstore-verify-$$" >/dev/null 2>&1 || true
  docker network rm "${NET}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "[2/3] Booting a clean object store + creating buckets..."
docker network create "${NET}" >/dev/null
docker run -d --name "sc-objectstore-verify-$$" --network "${NET}" --network-alias "${STORE}" \
  -v "${ROOT}/infrastructure/objectstore:/objectstore:ro" \
  -e OBJECTSTORE_ROOT_USER=verify-root -e OBJECTSTORE_ROOT_PASSWORD=verify-root-secret \
  -e OBJECTSTORE_APP_ACCESS_KEY=verify-app -e OBJECTSTORE_APP_SECRET_KEY=verify-app-secret \
  --entrypoint /bin/sh "${IMAGE}" /objectstore/start.sh >/dev/null
for _ in $(seq 1 60); do
  docker exec "sc-objectstore-verify-$$" wget -qO- http://127.0.0.1:8333/healthz >/dev/null 2>&1 && break
  sleep 1
done
docker run --rm --network "${NET}" -v "${ROOT}/infrastructure/objectstore:/objectstore:ro" \
  --entrypoint /bin/sh "${IMAGE}" /objectstore/init.sh >/dev/null || fail "objectstore-init failed"

echo "[3/3] Buckets and least privilege (rclone)..."
bucket_list="${BUCKETS[*]}"
if ! docker run --rm --network "${NET}" --entrypoint sh \
  -e RCLONE_CONFIG=/dev/null \
  -e RCLONE_CONFIG_APP_TYPE=s3 -e RCLONE_CONFIG_APP_PROVIDER=SeaweedFS \
  -e RCLONE_CONFIG_APP_ENDPOINT="http://${STORE}:8333" -e RCLONE_CONFIG_APP_FORCE_PATH_STYLE=true \
  -e RCLONE_CONFIG_APP_ACCESS_KEY_ID=verify-app -e RCLONE_CONFIG_APP_SECRET_ACCESS_KEY=verify-app-secret \
  -e RCLONE_CONFIG_APP_NO_CHECK_BUCKET=true \
  -e RCLONE_CONFIG_APPMK_TYPE=s3 -e RCLONE_CONFIG_APPMK_PROVIDER=SeaweedFS \
  -e RCLONE_CONFIG_APPMK_ENDPOINT="http://${STORE}:8333" -e RCLONE_CONFIG_APPMK_FORCE_PATH_STYLE=true \
  -e RCLONE_CONFIG_APPMK_ACCESS_KEY_ID=verify-app -e RCLONE_CONFIG_APPMK_SECRET_ACCESS_KEY=verify-app-secret \
  -e RCLONE_CONFIG_ANON_TYPE=s3 -e RCLONE_CONFIG_ANON_PROVIDER=SeaweedFS \
  -e RCLONE_CONFIG_ANON_ENDPOINT="http://${STORE}:8333" -e RCLONE_CONFIG_ANON_FORCE_PATH_STYLE=true \
  "${RCLONE}" -c "
  set -e
  echo probe > /tmp/p.txt
  for b in ${bucket_list}; do
    rclone copyto /tmp/p.txt app:\$b/verify.txt
    [ \"\$(rclone cat app:\$b/verify.txt)\" = probe ]
    rclone deletefile app:\$b/verify.txt
  done
  if rclone mkdir appmk:rogue-bucket 2>/dev/null; then echo 'app identity could create a bucket'; exit 1; fi
  # A public-read object ACL would be served anonymously: must be refused.
  if rclone copyto --s3-acl public-read /tmp/p.txt app:face-samples/public.txt 2>/dev/null; then echo 'app identity could set a public ACL'; exit 1; fi
  if rclone ls anon:face-samples >/dev/null 2>&1; then echo 'anonymous read allowed'; exit 1; fi
" >/dev/null; then
  fail "bucket access or least-privilege check failed"
fi

echo
if [ "${errors}" -ne 0 ]; then
  echo "STORAGE FAILED: ${errors} problem(s)."
  exit 1
fi
echo "STORAGE OK"
