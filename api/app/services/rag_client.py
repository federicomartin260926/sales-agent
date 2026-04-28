from app.config import Settings


class RAGClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def search(self, query: str) -> list[dict]:
        raise NotImplementedError("RAG integration is not wired yet")
