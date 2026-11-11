#!/usr/bin/env bash
# =============================================================================
# AI Smart Campus — AI Node Health Check & Installation Verification
# =============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${SCRIPT_DIR}"

if docker compose ps --filter "status=running" | grep -q "ai-node"; then
    # Run comprehensive 11-point diagnostic inside container
    docker compose exec -T ai-node python -m app.health_check
else
    echo "AI Node container is not running. Attempting offline preflight check..."
    if command -v python3 >/dev/null 2>&1; then
        if [ -f .env ]; then
            set -a
            source .env
            set +a
        fi
        PYTHONPATH="${SCRIPT_DIR}/../../apps/ai-service" python3 -m app.health_check || true
    else
        echo "FAIL: AI Node container is stopped and python3 is not found on host."
        echo "Start services with ./start.sh then re-run ./healthcheck.sh."
        exit 1
    fi
fi
