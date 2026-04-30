from app.config import Settings
from app.services.runtime_settings_client import RuntimeSettingsClient


class LLMClient:
    def __init__(self, settings: Settings, runtime_settings_client: RuntimeSettingsClient | None = None) -> None:
        self.settings = settings
        self.runtime_settings_client = runtime_settings_client or RuntimeSettingsClient(settings)

    async def resolve_configuration(self) -> dict[str, str]:
        return await self.runtime_settings_client.effective_values()

    async def generate(self, prompt: str) -> str:
        raise NotImplementedError(f"LLM provider '{self.settings.llm_provider}' is not wired yet")
