#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Restart Script
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

echo "Gracefully restarting AI Node services..."
docker compose restart -t 15

sleep 2
docker compose ps
