import httpx
import pytest

from app.config import Settings
from app.services.runtime_settings_client import RuntimeSettingsClient


def transport_handler(request: httpx.Request) -> httpx.Response:
    if request.method == "GET" and request.url.path == "/backend/api/internal/runtime-settings":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "values": {
                    "llm_default_profile": "openai",
                    "openai_base_url": "https://api.openai.com/v1",
                    "openai_api_key": "sk-test",
                    "openai_model": "gpt-4o-mini",
                    "ollama_base_url": "http://ollama:11434",
                    "ollama_model": "llama3.1",
                    "audio_mode": "disabled",
                }
            },
        )

    return httpx.Response(404, json={"detail": "not found"})


@pytest.mark.asyncio
async def test_runtime_settings_client_fetches_effective_values_from_backend():
    client = RuntimeSettingsClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx/backend", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    values = await client.effective_values()

    assert values["llm_default_profile"] == "openai"
    assert values["openai_api_key"] == "sk-test"
    assert values["audio_mode"] == "disabled"


@pytest.mark.asyncio
async def test_runtime_settings_client_falls_back_when_backend_is_unavailable():
    client = RuntimeSettingsClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx/backend", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(lambda request: httpx.Response(500, json={"detail": "boom"})),
    )

    values = await client.effective_values()

    assert values["llm_default_profile"] == "openai"
    assert values["openai_model"] == "gpt-4o-mini"
