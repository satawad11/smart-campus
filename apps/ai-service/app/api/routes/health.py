"""Liveness and readiness endpoints (FACE-003, FND-005).

Provides /health, /health/live (liveness) and /ready, /health/ready (readiness).
"""

from fastapi import APIRouter, Request, Response, status

from app.core.config import settings
from app.core.health import readiness_checker

router = APIRouter()


@router.get("/health")
@router.get("/health/live")
def liveness() -> dict:
    """Liveness probe: reports if process is alive and responsive."""
    return {"status": "ok", "service": "ai-service"}


@router.get("/ready")
@router.get("/health/ready")
def readiness(response: Response) -> dict:
    """Readiness probe: reports if AI service is ready for workload."""
    is_ready, checks = readiness_checker.evaluate_readiness()

    if not is_ready:
        response.status_code = status.HTTP_503_SERVICE_UNAVAILABLE
        return {
            "status": "not_ready",
            "service": "ai-service",
            "version": settings.version,
            "checks": checks,
        }

    return {
        "status": "ready",
        "service": "ai-service",
        "version": settings.version,
        "checks": checks,
    }


@router.get("/health/node")
def node_status(request: Request) -> dict:
    """Returns AI Node orchestrator operational status if active."""
    orchestrator = getattr(request.app.state, "orchestrator", None)
    if orchestrator is not None:
        return orchestrator.get_status_summary()
    return {
        "status": "STANDBY",
        "ai_mode": settings.ai_mode,
        "node_uuid": settings.ai_node_uuid,
        "message": "AI Node orchestrator not active in this process",
    }

