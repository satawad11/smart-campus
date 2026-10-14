#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Update Script
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if [ -f ai-node-image.tar.gz ]; then
    echo "Loading the updated AI Node image from this kit..."
    gunzip -c ai-node-image.tar.gz | docker load
elif [ -d ../../apps/ai-service ]; then
    echo "Rebuilding AI Node Docker image from the repository..."
    docker build -t smart-campus/ai-node:pilot ../../apps/ai-service
else
    echo "ERROR: no ai-node-image.tar.gz and no repository to build from." >&2
    exit 1
fi

echo "Applying update (re-creating container; durable offline buffer volume is preserved)..."
docker compose up -d --remove-orphans

sleep 2
docker compose ps
echo ""
echo "Update applied. Running health check..."
./healthcheck.sh || true
