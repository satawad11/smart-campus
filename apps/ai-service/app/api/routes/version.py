"""Version/metadata endpoint (FND-005)."""

from fastapi import APIRouter

from app.core.config import settings

router = APIRouter()


@router.get("/version")
def version() -> dict:
    return {
        "service": settings.app_name,
        "version": settings.version,
        "ai_mode": settings.ai_mode,
    }
