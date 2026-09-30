#!/bin/sh
# Idempotent MinIO initialisation for the pilot (runs in the minio/mc image;
# docker-compose.pilot.yml service `minio-init`).
#   1. create every bucket in buckets.txt, private (no anonymous access)
#   2. create/update the least-privilege policy for the application buckets
#   3. create/update the application user and attach only that policy
# The application must use MINIO_APP_ACCESS_KEY/SECRET, never the root user.
set -eu

: "${MINIO_ROOT_USER:?required}"
: "${MINIO_ROOT_PASSWORD:?required}"
: "${MINIO_APP_ACCESS_KEY:?required}"
: "${MINIO_APP_SECRET_KEY:?required}"

mc alias set sc http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null

# Shell builtins only: the minio/mc image has no grep/awk.
count=0
while IFS= read -r line || [ -n "$line" ]; do
  bucket=$(printf '%s' "$line" | tr -d '[:space:]')
  case "$bucket" in ''|'#'*) continue ;; esac
  mc mb --ignore-existing "sc/$bucket"
  mc anonymous set none "sc/$bucket" >/dev/null
  count=$((count + 1))
done < /pilot/buckets.txt
[ "$count" -gt 0 ] || { echo "minio-init: no buckets read from buckets.txt" >&2; exit 1; }

mc admin policy create sc smart-campus-app /pilot/pilot-app-policy.json
mc admin user add sc "$MINIO_APP_ACCESS_KEY" "$MINIO_APP_SECRET_KEY"
case "$(mc admin user info sc "$MINIO_APP_ACCESS_KEY")" in
  *smart-campus-app*) ;;
  *) mc admin policy attach sc smart-campus-app --user "$MINIO_APP_ACCESS_KEY" ;;
esac

echo "minio-init: $count buckets and least-privilege application user ready"
