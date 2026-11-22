"""FastAPI application entry point (FACE-003, FND-005)."""

import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI

from app.api.router import api_router
from app.core.config import settings
from app.core.health import readiness_checker

NIL_NODE_UUID = "00000000-0000-0000-0000-000000000000"
PLACEHOLDER_NODE_TOKEN = "sc_node_your_issued_token_here"


def node_credentials_configured(current_settings=settings) -> bool:
    """Return true only for non-placeholder edge-node credentials."""
    node_uuid = (current_settings.ai_node_uuid or "").strip().lower()
    node_token = (current_settings.ai_node_token or "").strip()
    return bool(
        node_uuid
        and node_uuid != NIL_NODE_UUID
        and node_token
        and node_token != PLACEHOLDER_NODE_TOKEN
    )


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Lifespan context manager for startup and shutdown lifecycle."""
    # Startup initialization
    readiness_checker.is_started = True
    orchestrator = None
    credentials_ready = node_credentials_configured()
    if settings.ai_node_autorun and not credentials_ready:
        logging.getLogger("ai_node.orchestrator").error(
            "AI Node autorun refused: enroll this node before startup"
        )
    elif credentials_ready:
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
