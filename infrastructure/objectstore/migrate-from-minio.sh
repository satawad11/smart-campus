#!/bin/sh
# One-off copy of every object from the legacy MinIO store into the new
# object store (runs in the rclone image: compose service `storage-migrate`).
#
# Copies each bucket in buckets.txt, then verifies byte for byte
# (`rclone check --download`: S3 ETags are not checksums for multipart
# uploads). Exits non-zero on any copy or verification error, so the
# installer stops before the backend is pointed at an incomplete store.
# Never deletes anything at the source; the MinIO volume is kept.
set -eu

: "${LEGACY_ENDPOINT:=http://minio:9000}"
: "${LEGACY_ACCESS_KEY:?required}"
: "${LEGACY_SECRET_KEY:?required}"
: "${OBJECTSTORE_ENDPOINT:=http://objectstore:8333}"
: "${OBJECTSTORE_ROOT_USER:?required}"
: "${OBJECTSTORE_ROOT_PASSWORD:?required}"
BUCKETS_FILE="${OBJECTSTORE_BUCKETS_FILE:-/objectstore/buckets.txt}"

# Remotes from the environment only (no config file with secrets on disk).
export RCLONE_CONFIG=/dev/null
export RCLONE_CONFIG_SRC_TYPE=s3 RCLONE_CONFIG_SRC_PROVIDER=Minio RCLONE_CONFIG_SRC_ENDPOINT="$LEGACY_ENDPOINT"
export RCLONE_CONFIG_SRC_ACCESS_KEY_ID="$LEGACY_ACCESS_KEY" RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY="$LEGACY_SECRET_KEY"
export RCLONE_CONFIG_SRC_FORCE_PATH_STYLE=true
export RCLONE_CONFIG_DST_TYPE=s3 RCLONE_CONFIG_DST_PROVIDER=SeaweedFS RCLONE_CONFIG_DST_ENDPOINT="$OBJECTSTORE_ENDPOINT"
export RCLONE_CONFIG_DST_ACCESS_KEY_ID="$OBJECTSTORE_ROOT_USER" RCLONE_CONFIG_DST_SECRET_ACCESS_KEY="$OBJECTSTORE_ROOT_PASSWORD"
export RCLONE_CONFIG_DST_FORCE_PATH_STYLE=true RCLONE_CONFIG_DST_NO_CHECK_BUCKET=true

# Both stores must be readable before anything is judged "absent": an
# authentication error must never look like an empty legacy store.
legacy_buckets="$(rclone lsd "src:")" || { echo "migrate: cannot list the legacy MinIO store (endpoint or credentials)" >&2; exit 1; }
rclone lsd "dst:" >/dev/null || { echo "migrate: cannot list the new object store (endpoint or credentials)" >&2; exit 1; }

total=0
while IFS= read -r line || [ -n "$line" ]; do
    bucket=$(printf '%s' "$line" | tr -d '[:space:]')
    case "$bucket" in ''|'#'*) continue ;; esac
    if ! printf '%s\n' "$legacy_buckets" | awk '{ print $NF }' | grep -qx "$bucket"; then
        echo "migrate: ${bucket}: not in legacy store, skipped"
        continue
    fi
    rclone copy "src:${bucket}" "dst:${bucket}" --transfers 4 --checkers 8 --retries 3
    rclone check "src:${bucket}" "dst:${bucket}" --one-way --download
    n=$(rclone size "src:${bucket}" --json | sed -n 's/.*"count":\([0-9]*\).*/\1/p')
    echo "migrate: ${bucket}: ${n:-0} objects copied and verified"
    total=$((total + ${n:-0}))
done < "$BUCKETS_FILE"

echo "migrate: done, ${total} objects verified byte for byte"
