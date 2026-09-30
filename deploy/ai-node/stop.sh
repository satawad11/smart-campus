#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Stop Script
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

echo "Gracefully stopping AI Node services (allowing in-flight recognitions and streams to close)..."
docker compose stop -t 15

echo "Services stopped cleanly."
