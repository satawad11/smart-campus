#!/usr/bin/env bash
# Build a standalone AI node installation kit (COM-04).
#
# Run on the vendor/build machine (needs this repository + Docker). Produces
#   dist/ai-node-kit-<version>.tar.gz
# containing the prebuilt image (ai-node-image.tar.gz), the deploy/ai-node
# scripts, .env.example, README, VERSION and SHA256SUMS. The node machine only
# needs Docker: no source code, no internet access to rebuild.
#
# Usage: scripts/package-ai-node.sh [version]   (default: git describe)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "${ROOT}"

VERSION="${1:-$(git describe --tags --always --dirty 2>/dev/null || echo dev)}"
IMAGE="smart-campus/ai-node:pilot"
KIT="ai-node-kit-${VERSION}"
OUT="${ROOT}/dist/${KIT}"

if [ -n "$(git status --porcelain -- apps/ai-service deploy/ai-node 2>/dev/null)" ]; then
  echo "WARNING: apps/ai-service or deploy/ai-node has uncommitted changes; the kit will not match a commit." >&2
fi

rm -rf "${OUT}" "${OUT}.tar.gz"
mkdir -p "${OUT}"

echo "Building ${IMAGE} from apps/ai-service (${VERSION})..."
docker build -q -t "${IMAGE}" apps/ai-service >/dev/null

echo "Saving image..."
docker save "${IMAGE}" | gzip -c > "${OUT}/ai-node-image.tar.gz"

cp deploy/ai-node/docker-compose.yml deploy/ai-node/.env.example deploy/ai-node/README.md "${OUT}/"
cp deploy/ai-node/*.sh "${OUT}/"
chmod +x "${OUT}"/*.sh
printf '%s\n' "${VERSION}" > "${OUT}/VERSION"
(cd "${OUT}" && sha256sum ai-node-image.tar.gz docker-compose.yml .env.example ./*.sh VERSION > SHA256SUMS)

tar -czf "${OUT}.tar.gz" -C "${ROOT}/dist" "${KIT}"
echo "Kit: dist/${KIT}.tar.gz ($(du -h "${OUT}.tar.gz" | cut -f1))"
