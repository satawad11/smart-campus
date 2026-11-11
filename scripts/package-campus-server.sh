#!/usr/bin/env bash
# Build the offline campus server install kit (deploy/campus-server).
#
# Run on the vendor/build machine (this repository + Docker). Produces
#   dist/campus-server-kit-<version>-<arch>.tar.gz
# with every image the pilot stack needs (images.tar.gz), the compose files,
# configuration templates, install.sh / campus.sh, VERSION, ARCH,
# REVERB_APP_KEY and SHA256SUMS. The campus server needs only Linux and
# Docker: no source code and no Internet access during installation.
#
# Usage: scripts/package-campus-server.sh [version]   (default: git describe)
#   CAMPUS_PLATFORM=linux/amd64 (default)
# Build from a clean approved tag for anything shipped to a customer
# (docs/commercial/RELEASE_CHECKLIST.md).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

VERSION="${1:-$(git describe --tags --always --dirty 2>/dev/null || echo dev)}"
PLATFORM="${CAMPUS_PLATFORM:-linux/amd64}"
ARCH="${PLATFORM#linux/}"
KIT="campus-server-kit-${VERSION}-${ARCH}"
OUT="${ROOT}/dist/${KIT}"

if [ -n "$(git status --porcelain -- apps deploy/campus-server docker-compose*.yml infrastructure 2>/dev/null)" ]; then
  echo "WARNING: uncommitted changes; the kit will not match a commit." >&2
fi

rm -rf "${OUT}" "${OUT}.tar.gz"
mkdir -p "${OUT}"

# Public Reverb app key baked into the frontend bundle (not a secret: every
# browser sees it). Each installation generates its own REVERB_APP_SECRET.
REVERB_APP_KEY="sc-$(openssl rand -hex 10)"

build() { # build <tag> <context> [docker build args...]
  local tag="$1" context="$2"; shift 2
  echo "Building ${tag} (${PLATFORM})..."
  docker buildx build -q --platform "${PLATFORM}" --load -t "${tag}" "$@" "${context}" >/dev/null
}
build smart-campus/backend:pilot apps/backend
build smart-campus/frontend:pilot apps/frontend -f apps/frontend/Dockerfile.prod --build-arg "VITE_REVERB_APP_KEY=${REVERB_APP_KEY}"
# shellcheck source=SCRIPTDIR/face-models/ensure.sh
. "${ROOT}/scripts/face-models/ensure.sh"
ensure_face_models
build smart-campus/ai-service:pilot apps/ai-service

# Third-party images exactly as referenced by the compose files.
pull_tag() { # pull_tag <reference> [local tag]: local copy if present, else pull
  if docker image inspect "$1" >/dev/null 2>&1; then
    echo "Using local $1"
  else
    echo "Pulling $1 (${PLATFORM})..."
    docker pull -q --platform "${PLATFORM}" "$1" >/dev/null
  fi
  [ -z "${2:-}" ] || docker tag "$1" "$2"
}
pull_tag nginx:1.27-alpine
pull_tag pgvector/pgvector:pg16
pull_tag redis:7-alpine
# Digest-pinned images get a local tag (docker load does not restore digests);
# deploy/campus-server/docker-compose.kit.yml uses these tags.
pull_tag "$(grep -m1 -oE 'chrislusf/seaweedfs:[0-9.]+@sha256:[0-9a-f]+' docker-compose.yml)" smart-campus/vendor-seaweedfs:kit
pull_tag "$(grep -m1 -oE 'rclone/rclone:[0-9.]+@sha256:[0-9a-f]+' docker-compose.pilot.yml)" smart-campus/vendor-rclone:kit
pull_tag "$(grep -m1 -oE 'clamav/clamav:[0-9.]+@sha256:[0-9a-f]+' docker-compose.pilot.yml)" smart-campus/vendor-clamav:kit

IMAGES=(
  smart-campus/backend:pilot smart-campus/frontend:pilot smart-campus/ai-service:pilot
  nginx:1.27-alpine pgvector/pgvector:pg16 redis:7-alpine
  smart-campus/vendor-seaweedfs:kit smart-campus/vendor-rclone:kit smart-campus/vendor-clamav:kit
)
for image in "${IMAGES[@]}"; do
  arch="$(docker image inspect "${image}" --format '{{.Architecture}}')"
  [ "${arch}" = "${ARCH}" ] || { echo "ERROR: ${image} is ${arch}, expected ${ARCH}." >&2; exit 1; }
done

echo "Saving ${#IMAGES[@]} images..."
docker save "${IMAGES[@]}" | gzip -c > "${OUT}/images.tar.gz"

# Runtime files only. Never copy infrastructure/pilot/{certs,ca,letsencrypt}
# contents: they may hold this machine's private keys.
cp docker-compose.yml docker-compose.pilot.yml "${OUT}/"
cp deploy/campus-server/docker-compose.kit.yml deploy/campus-server/install.sh \
   deploy/campus-server/campus.sh deploy/campus-server/README.md \
   deploy/campus-server/update-agent.py deploy/campus-server/update-agent-setup.sh "${OUT}/"
mkdir -p "${OUT}/infrastructure/pilot" "${OUT}/scripts" "${OUT}/docs/security"
cp -r infrastructure/objectstore infrastructure/postgres "${OUT}/infrastructure/"
cp -r infrastructure/pilot/nginx "${OUT}/infrastructure/pilot/"
cp infrastructure/pilot/pilot.env.example "${OUT}/infrastructure/pilot/"
cp scripts/tls-setup.sh "${OUT}/scripts/"
cp docs/security/LEAST-PRIVILEGE-ROLES.sql "${OUT}/docs/security/"
chmod +x "${OUT}"/*.sh "${OUT}/scripts/tls-setup.sh"
# A kit built on Windows may carry CRLF text files; the server is Linux.
find "${OUT}" -type f ! -name images.tar.gz -exec sed -i 's/\r$//' {} +
printf '%s\n' "${VERSION}" > "${OUT}/VERSION"
printf '%s\n' "${ARCH}" > "${OUT}/ARCH"
printf '%s\n' "${REVERB_APP_KEY}" > "${OUT}/REVERB_APP_KEY"
(cd "${OUT}" && find . -type f ! -name SHA256SUMS | LC_ALL=C sort | xargs sha256sum > SHA256SUMS)

tar -czf "${OUT}.tar.gz" -C "${ROOT}/dist" "${KIT}"
echo "Kit: dist/${KIT}.tar.gz ($(du -h "${OUT}.tar.gz" | cut -f1))"
