"""FastAPI application entry point (FND-005)."""

from fastapi import FastAPI

from app.api.router import api_router
from app.core.config import settings

app = FastAPI(title=settings.app_name, version=settings.version)
app.include_router(api_router)
