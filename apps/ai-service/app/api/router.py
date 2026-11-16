"""Aggregate API router (FND-005)."""

from fastapi import APIRouter

from app.api.routes import enrollment, health, liveness, quality, recognition, version

api_router = APIRouter()
api_router.include_router(health.router, tags=["health"])
api_router.include_router(version.router, tags=["meta"])
api_router.include_router(recognition.router, tags=["recognition"])
api_router.include_router(enrollment.router, prefix="/internal/v1/face", tags=["enrollment"])
api_router.include_router(liveness.router, prefix="/internal/v1/face", tags=["liveness"])
api_router.include_router(quality.router, prefix="/internal/v1/face", tags=["quality"])
