"""FastAPI application entry point (FACE-003, FND-005)."""

from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.api.router import api_router
from app.core.config import settings
from app.core.health import readiness_checker


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Lifespan context manager for startup and shutdown lifecycle."""
    # Startup initialization
    readiness_checker.is_started = True
    yield
    # Shutdown cleanup
    readiness_checker.is_started = False


app = FastAPI(
    title=settings.app_name,
    version=settings.version,
    lifespan=lifespan,
)
app.include_router(api_router)
