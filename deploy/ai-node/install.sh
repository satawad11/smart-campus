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
mkdir -p models certs

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

# 4. Obtain the AI Node image: bundled in the kit, or built from the repository
if [ -f ai-node-image.tar.gz ]; then
    case "$(uname -m)" in
        x86_64|amd64) HOST_ARCH=amd64 ;;
        aarch64|arm64) HOST_ARCH=arm64 ;;
        *) HOST_ARCH="$(uname -m)" ;;
    esac
    if [ -f ARCH ] && [ "$(cat ARCH)" != "${HOST_ARCH}" ]; then
        echo "ERROR: this kit was built for $(cat ARCH) but this host is ${HOST_ARCH}." >&2
        echo "       Build a matching kit: AI_NODE_PLATFORM=linux/${HOST_ARCH} scripts/package-ai-node.sh" >&2
        exit 1
    fi
    if [ -f SHA256SUMS ] && command -v sha256sum >/dev/null 2>&1; then
        echo "Verifying kit checksums..."
        sha256sum -c --quiet SHA256SUMS
    fi
    echo "Loading AI Node image from the kit (no source code or internet needed)..."
    gunzip -c ai-node-image.tar.gz | docker load
elif [ -d ../../apps/ai-service ]; then
    echo "Building AI Node Docker image from the repository (smart-campus/ai-node:pilot)..."
    docker build -t smart-campus/ai-node:pilot ../../apps/ai-service
else
    echo "ERROR: no ai-node-image.tar.gz in this kit and no repository to build from." >&2
    echo "       Use a kit made with scripts/package-ai-node.sh." >&2
    exit 1
fi

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
