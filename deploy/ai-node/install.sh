#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Pilot Installer
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

echo "============================================================"
echo " AI Smart Campus — AI Node Pilot Installation"
echo "============================================================"

# 1. Verify Docker prerequisites
if ! command -v docker >/dev/null 2>&1; then
    echo "ERROR: 'docker' command not found. Please install Docker Engine." >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "ERROR: 'docker compose' (Compose V2) not found. Please install docker-compose-plugin." >&2
    exit 1
fi

# 2. Create required directories
mkdir -p models

# 3. Initialize .env if missing
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
    chmod 600 .env
    echo "WARNING: Created default .env with restricted permissions (chmod 600)."
    echo "         You MUST edit .env and configure BACKEND_BASE_URL, AI_NODE_UUID,"
    echo "         and AI_NODE_TOKEN before starting services."
else
    echo "Found existing .env file. Preserving current configuration."
    chmod 600 .env
fi

# 4. Build AI Node container image
echo "Building AI Node Docker image (smart-campus/ai-node:pilot)..."
docker compose build

echo "============================================================"
echo " Installation complete!"
echo ""
echo " Next steps:"
echo " 1. Configure credentials in .env:"
echo "      AI_NODE_UUID=<node-uuid>"
echo "      AI_NODE_TOKEN=<token>"
echo "      BACKEND_BASE_URL=<https://...>"
echo " 2. Start services:"
echo "      ./start.sh"
echo " 3. Verify health:"
echo "      ./healthcheck.sh"
echo "============================================================"
