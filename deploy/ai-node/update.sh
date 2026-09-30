#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Update Script
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

echo "Rebuilding AI Node Docker image..."
docker compose build

echo "Applying update (re-creating container; durable offline buffer volume is preserved)..."
docker compose up -d --remove-orphans

sleep 2
docker compose ps
echo ""
echo "Update applied. Running health check..."
./healthcheck.sh || true
