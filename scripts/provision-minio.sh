#!/usr/bin/env bash
#
# provision-minio.sh — create the approved buckets in the running MinIO (dev).
#
# Idempotent. Reads bucket names from infrastructure/minio/buckets.txt and uses
# the minio/mc client. Intended for local development / CI bootstrapping.
#
# Env (defaults match docker-compose.yml / .env.example):
#   MINIO_ENDPOINT        default http://minio:9000 (use http://localhost:9000 from host)
#   MINIO_ROOT_USER       default minioadmin
#   MINIO_ROOT_PASSWORD   default minioadmin

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"

ENDPOINT="${MINIO_ENDPOINT:-http://localhost:9000}"
USER="${MINIO_ROOT_USER:-minioadmin}"
PASS="${MINIO_ROOT_PASSWORD:-minioadmin}"
BUCKETS_FILE="${ROOT}/infrastructure/minio/buckets.txt"

if ! command -v docker >/dev/null 2>&1; then
  echo "docker is required to run the minio/mc client." >&2
  exit 1
fi

# Read non-empty, non-comment bucket names.
mapfile -t BUCKETS < <(grep -vE '^\s*(#|$)' "${BUCKETS_FILE}")

echo "Provisioning ${#BUCKETS[@]} bucket(s) on ${ENDPOINT}..."
docker run --rm --network host --entrypoint sh quay.io/minio/mc -c "
  set -e
  mc alias set sc '${ENDPOINT}' '${USER}' '${PASS}' >/dev/null
  for b in ${BUCKETS[*]}; do
    mc mb --ignore-existing \"sc/\$b\"
  done
  mc ls sc
"
echo "Done."
