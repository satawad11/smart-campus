#!/bin/sh
# S3-compatible object storage (SeaweedFS) for AI Smart Campus.
#
# Runs as the `objectstore` service (docker-compose.yml). Generates the S3
# identities file from the environment at every start, so credentials are
# never written into the repository or a mounted file:
#   - root identity (OBJECTSTORE_ROOT_USER / _PASSWORD): Admin; used only by
#     objectstore-init and the one-off MinIO migration, never by the app
#   - app identity (OBJECTSTORE_APP_ACCESS_KEY / _SECRET_KEY = the backend's
#     AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY): Read/Write/List/Tagging on the
#     buckets in buckets.txt only; cannot create, delete or list other buckets
# No anonymous identity exists, so unauthenticated requests are refused.
set -eu

: "${OBJECTSTORE_ROOT_USER:?required}"
: "${OBJECTSTORE_ROOT_PASSWORD:?required}"
: "${OBJECTSTORE_APP_ACCESS_KEY:?required}"
: "${OBJECTSTORE_APP_SECRET_KEY:?required}"
BUCKETS_FILE="${OBJECTSTORE_BUCKETS_FILE:-/objectstore/buckets.txt}"
CONFIG=/tmp/s3.json

json_escape() { printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g'; }

actions=""
while IFS= read -r line || [ -n "$line" ]; do
    bucket=$(printf '%s' "$line" | tr -d '[:space:]')
    case "$bucket" in ''|'#'*) continue ;; esac
    for action in Read Write List Tagging; do
        actions="${actions:+${actions}, }\"${action}:${bucket}\""
    done
done < "$BUCKETS_FILE"
[ -n "$actions" ] || { echo "objectstore: no buckets read from ${BUCKETS_FILE}" >&2; exit 1; }

umask 077
cat > "$CONFIG" <<EOF
{
  "identities": [
    {
      "name": "root",
      "credentials": [{ "accessKey": "$(json_escape "$OBJECTSTORE_ROOT_USER")", "secretKey": "$(json_escape "$OBJECTSTORE_ROOT_PASSWORD")" }],
      "actions": ["Admin", "Read", "Write", "List", "Tagging"]
    },
    {
      "name": "app",
      "credentials": [{ "accessKey": "$(json_escape "$OBJECTSTORE_APP_ACCESS_KEY")", "secretKey": "$(json_escape "$OBJECTSTORE_APP_SECRET_KEY")" }],
      "actions": [${actions}]
    }
  ]
}
EOF

exec weed server \
    -dir=/data \
    -master.volumeSizeLimitMB="${OBJECTSTORE_VOLUME_SIZE_MB:-1024}" \
    -volume.max=0 \
    -s3 -s3.port=8333 -s3.config="$CONFIG"
