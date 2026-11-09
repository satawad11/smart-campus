#!/bin/sh
# Idempotent bucket creation for the object store (compose service
# `objectstore-init`, same SeaweedFS image). Creates every bucket listed in
# buckets.txt that does not exist yet; existing buckets and objects are left
# untouched. Access control is defined by the identities in start.sh.
set -eu

MASTER="${OBJECTSTORE_MASTER:-objectstore:9333}"
BUCKETS_FILE="${OBJECTSTORE_BUCKETS_FILE:-/objectstore/buckets.txt}"

existing="$(echo "s3.bucket.list" | weed shell -master="$MASTER" 2>/dev/null)"

count=0
while IFS= read -r line || [ -n "$line" ]; do
    bucket=$(printf '%s' "$line" | tr -d '[:space:]')
    case "$bucket" in ''|'#'*) continue ;; esac
    if printf '%s\n' "$existing" | grep -Eq "(^|[[:space:]])${bucket}([[:space:]]|$)"; then
        echo "objectstore-init: bucket ${bucket} exists"
    else
        echo "s3.bucket.create -name ${bucket}" | weed shell -master="$MASTER" >/dev/null
        echo "objectstore-init: bucket ${bucket} created"
    fi
    count=$((count + 1))
done < "$BUCKETS_FILE"
[ "$count" -gt 0 ] || { echo "objectstore-init: no buckets read from ${BUCKETS_FILE}" >&2; exit 1; }

# Verify: every bucket is now listed.
listed="$(echo "s3.bucket.list" | weed shell -master="$MASTER" 2>/dev/null)"
missing=0
while IFS= read -r line || [ -n "$line" ]; do
    bucket=$(printf '%s' "$line" | tr -d '[:space:]')
    case "$bucket" in ''|'#'*) continue ;; esac
    printf '%s\n' "$listed" | grep -Eq "(^|[[:space:]])${bucket}([[:space:]]|$)" \
        || { echo "objectstore-init: bucket ${bucket} missing after create" >&2; missing=1; }
done < "$BUCKETS_FILE"
[ "$missing" -eq 0 ] || exit 1

echo "objectstore-init: ${count} buckets ready"
