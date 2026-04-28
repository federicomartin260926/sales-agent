from app.config import Settings


class LLMClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    async def generate(self, prompt: str) -> str:
        raise NotImplementedError(f"LLM provider '{self.settings.llm_provider}' is not wired yet")
