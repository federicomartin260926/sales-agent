from app.config import Settings


class CRMClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def fetch_contact_context(self, phone: str) -> dict:
        raise NotImplementedError("CRM integration is not wired yet")
