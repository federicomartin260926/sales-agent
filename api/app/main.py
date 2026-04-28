from fastapi import FastAPI

from app.api.agent import router as agent_router
from app.api.health import router as health_router
from app.config import get_settings
from app.utils.logging import configure_logging

configure_logging()

def create_app() -> FastAPI:
    settings = get_settings()
    application = FastAPI(title=settings.app_name)
    application.include_router(health_router)
    application.include_router(agent_router)

    return application


app = create_app()
