#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Start Script
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if [ ! -f .env ]; then
    echo "ERROR: .env file not found. Run ./install.sh first." >&2
    exit 1
fi

# Basic sanity checks on required configuration
source .env 2>/dev/null || true

if [ -z "${AI_NODE_UUID:-}" ] || [ "${AI_NODE_UUID:-}" = "00000000-0000-0000-0000-000000000000" ]; then
    echo "ERROR: AI_NODE_UUID is not enrolled; run ./enroll.sh <ENROLLMENT-CODE>." >&2
    exit 1
fi

if [ -z "${AI_NODE_TOKEN:-}" ] || [ "${AI_NODE_TOKEN:-}" = "sc_node_your_issued_token_here" ]; then
    echo "ERROR: AI_NODE_TOKEN is not enrolled; run ./enroll.sh <ENROLLMENT-CODE>." >&2
    exit 1
fi

echo "Starting AI Node services..."
docker compose up -d

echo "Services started. Checking startup status..."
sleep 2
docker compose ps
echo ""
echo "To view live logs: docker compose logs -f ai-node"
echo "To run diagnostics: ./healthcheck.sh"
