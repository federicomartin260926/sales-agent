import httpx
import pytest

from app.config import Settings
from app.services.runtime_settings_client import RuntimeSettingsClient


def transport_handler(request: httpx.Request) -> httpx.Response:
    if request.method == "GET" and request.url.path == "/api/internal/runtime-settings":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "values": {
                    "llm_default_profile": "openai",
                    "openai_base_url": "https://api.openai.com/v1",
                    "openai_api_key": "sk-test",
                    "openai_model": "gpt-4o-mini",
                    "openai_transcription_model": "gpt-4o-mini-transcribe",
                    "openai_timeout_seconds": "22",
                    "ollama_base_url": "http://ollama-vpn-bridge:11434",
                    "ollama_model": "llama3.1",
                    "ollama_timeout_seconds": "24",
                    "audio_gateway_base_url": "http://audio-gateway",
                    "audio_gateway_bearer_token": "enc:v1:token",
                    "audio_max_bytes": "4096",
                    "audio_llm_followup_reserve_cost_eur": "0.015",
                    "audio_timeout_seconds": "18",
                }
            },
        )

    return httpx.Response(404, json={"detail": "not found"})


@pytest.mark.asyncio
async def test_runtime_settings_client_fetches_effective_values_from_backend():
    client = RuntimeSettingsClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    values = await client.effective_values()

    assert values["llm_default_profile"] == "openai"
    assert values["openai_api_key"] == "sk-test"
    assert values["openai_timeout_seconds"] == "22"
    assert values["openai_transcription_model"] == "gpt-4o-mini-transcribe"
    assert values["audio_gateway_base_url"] == "http://audio-gateway"
    assert values["audio_gateway_bearer_token"] == "enc:v1:token"
    assert values["audio_max_bytes"] == "4096"
    assert values["audio_llm_followup_reserve_cost_eur"] == "0.015"
    assert values["audio_timeout_seconds"] == "18"
    assert values["ollama_base_url"] == "http://ollama-vpn-bridge:11434"
    assert values["ollama_timeout_seconds"] == "24"


@pytest.mark.asyncio
async def test_runtime_settings_client_falls_back_when_backend_is_unavailable():
    settings = Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token")
    client = RuntimeSettingsClient(
        settings,
        transport=httpx.MockTransport(lambda request: httpx.Response(500, json={"detail": "boom"})),
    )

    values = await client.effective_values()

    assert values["llm_default_profile"] == "openai"
    assert values["openai_model"] == "gpt-4o-mini"
    assert values["openai_transcription_model"] == "gpt-4o-mini-transcribe"
    assert values["openai_timeout_seconds"] == str(settings.openai_timeout_seconds)
    assert values["ollama_timeout_seconds"] == "15"
    assert values["audio_gateway_base_url"] == settings.audio_gateway_base_url
    assert values["audio_gateway_bearer_token"] == settings.audio_gateway_bearer_token
    assert values["audio_max_bytes"] == str(25 * 1024 * 1024)
    assert values["audio_llm_followup_reserve_cost_eur"] == "0.01"
    assert values["audio_timeout_seconds"] == "15"


def test_settings_reads_appointment_availability_feature_flag_from_env(monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("NEW_LLM_ORCHESTRATION_APPOINTMENT_AVAILABILITY_ENABLED", "true")

    settings = Settings()

    assert settings.new_llm_orchestration_appointment_availability_enabled is True


def test_settings_reads_slot_selection_feature_flag_from_env(monkeypatch: pytest.MonkeyPatch):
    monkeypatch.setenv("NEW_LLM_ORCHESTRATION_SLOT_SELECTION_ENABLED", "true")

    settings = Settings()

    assert settings.new_llm_orchestration_slot_selection_enabled is True
