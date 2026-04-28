from app.config import Settings


class BackendClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def fetch_tenant(self, tenant_id: str) -> dict:
        raise NotImplementedError("Backend integration is not wired yet")
