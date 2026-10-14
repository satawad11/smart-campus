"""FastAPI application entry point (FACE-003, FND-005)."""

import logging
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
    orchestrator = None
    if settings.ai_node_autorun or (settings.ai_node_uuid and settings.ai_node_token):
        # The node image starts `uvicorn app.main`, which configures only
        # uvicorn's own loggers: without this, LOG_LEVEL was ignored and the
        # node's operational logs (delivery failures, camera errors) were lost.
        logging.basicConfig(
            level=getattr(logging, settings.log_level.upper(), logging.INFO),
            format="%(asctime)s [%(levelname)s] [%(name)s] %(message)s",
            datefmt="%Y-%m-%d %H:%M:%S",
        )
        from app.orchestration.orchestrator import NodeOrchestrator

        orchestrator = NodeOrchestrator()
        orchestrator.start()
        app.state.orchestrator = orchestrator

    yield

    # Shutdown cleanup
    if orchestrator is not None:
        orchestrator.stop(timeout=5.0)
    readiness_checker.is_started = False


app = FastAPI(
    title=settings.app_name,
    version=settings.version,
    lifespan=lifespan,
)
app.include_router(api_router)
