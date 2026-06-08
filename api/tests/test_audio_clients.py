from __future__ import annotations

import logging

import httpx
import pytest

from app.config import get_settings
from app.services.audio_clients import AudioTranscriptionClient


class FakeRuntimeSettingsClient:
    def __init__(self, values: dict[str, str]) -> None:
        self.values = values

    async def effective_values(self) -> dict[str, str]:
        return self.values


@pytest.mark.asyncio
async def test_audio_transcription_configuration_uses_audio_specific_runtime_settings(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_PROVIDER", "openai")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_MODEL", "gpt-4o-mini-transcribe")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_COST_UNIT", "second")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_COST_PER_UNIT_EUR", "0.004")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_ENABLED", "1")
    monkeypatch.setenv("OPENAI_API_KEY", "test-key")
    get_settings.cache_clear()
    settings = get_settings()
    client = AudioTranscriptionClient(
        settings,
        runtime_settings_client=FakeRuntimeSettingsClient(
            {
                "audio_transcription_provider": "openai",
                "audio_transcription_model": "gpt-4o-mini-transcribe",
                "audio_transcription_cost_unit": "second",
                "audio_transcription_cost_per_unit_eur": "0.005",
                "audio_transcription_enabled": "1",
                "openai_api_key": "runtime-key",
                "openai_base_url": "https://api.openai.com/v1",
                "openai_timeout_seconds": "12",
            }
        ),
    )

    configuration = await client.resolve_configuration()

    assert configuration.provider == "openai"
    assert configuration.model == "gpt-4o-mini-transcribe"
    assert configuration.cost_unit == "second"
    assert configuration.cost_per_unit_eur == pytest.approx(0.005)
    assert configuration.enabled is True
    assert configuration.api_key == "runtime-key"
    assert configuration.timeout_seconds == 12


@pytest.mark.asyncio
async def test_audio_transcription_estimate_cost_supports_seconds_and_minutes(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_PROVIDER", "openai")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_MODEL", "gpt-4o-mini-transcribe")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_COST_UNIT", "minute")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_COST_PER_UNIT_EUR", "0.02")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_ENABLED", "1")
    monkeypatch.setenv("OPENAI_API_KEY", "test-key")
    get_settings.cache_clear()
    settings = get_settings()
    client = AudioTranscriptionClient(settings, runtime_settings_client=FakeRuntimeSettingsClient({}))

    minute_config = await client.resolve_configuration()
    assert client.estimate_cost_eur(61, minute_config) == pytest.approx(0.04)

    second_config = type(
        "AudioConfigStub",
        (),
        {
            "enabled": True,
            "cost_unit": "second",
            "cost_per_unit_eur": 0.005,
        },
    )()
    assert client.estimate_cost_eur(12, second_config) == pytest.approx(0.06)


@pytest.mark.asyncio
async def test_audio_transcription_uses_whatsapp_filename_and_normalized_content_type(monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_PROVIDER", "openai")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_MODEL", "gpt-4o-mini-transcribe")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_ENABLED", "1")
    monkeypatch.setenv("OPENAI_API_KEY", "test-key")
    get_settings.cache_clear()
    settings = get_settings()

    seen: dict[str, object] = {}

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            seen["base_url"] = kwargs.get("base_url")
            seen["timeout"] = kwargs.get("timeout")

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, data=None, files=None, headers=None):
            seen["url"] = url
            seen["data"] = data
            seen["files"] = files
            seen["headers"] = headers
            request = httpx.Request("POST", f"{seen['base_url']}{url}")
            return httpx.Response(200, request=request, json={"text": "Hola"})

    monkeypatch.setattr("app.services.audio_clients.httpx.AsyncClient", FakeAsyncClient)

    client = AudioTranscriptionClient(
        settings,
        runtime_settings_client=FakeRuntimeSettingsClient(
            {
                "audio_transcription_provider": "openai",
                "audio_transcription_model": "gpt-4o-mini-transcribe",
                "audio_transcription_enabled": "1",
                "openai_api_key": "runtime-key",
                "openai_base_url": "https://api.openai.com/v1",
                "openai_timeout_seconds": "12",
            }
        ),
    )

    result = await client.transcribe(b"ogg-bytes", "audio/ogg; codecs=opus", "media-123")

    assert result.text == "Hola"
    assert seen["url"] == "/audio/transcriptions"
    assert seen["data"] == {"model": "gpt-4o-mini-transcribe"}
    assert seen["headers"] == {"Authorization": "Bearer runtime-key"}
    file_name, file_obj, file_content_type = seen["files"]["file"]  # type: ignore[index]
    assert file_name == "whatsapp-audio.ogg"
    assert file_content_type == "audio/ogg"
    assert file_obj.getvalue() == b"ogg-bytes"


@pytest.mark.asyncio
async def test_audio_transcription_includes_safe_openai_error_body(monkeypatch: pytest.MonkeyPatch, caplog: pytest.LogCaptureFixture) -> None:
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_PROVIDER", "openai")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_MODEL", "gpt-4o-mini-transcribe")
    monkeypatch.setenv("AUDIO_TRANSCRIPTION_ENABLED", "1")
    monkeypatch.setenv("OPENAI_API_KEY", "test-key")
    get_settings.cache_clear()
    settings = get_settings()

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            self.base_url = kwargs.get("base_url")

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, data=None, files=None, headers=None):
            request = httpx.Request("POST", f"{self.base_url}{url}")
            return httpx.Response(
                400,
                request=request,
                json={
                    "error": {
                        "message": "Invalid audio format",
                        "type": "invalid_request_error",
                    }
                },
            )

    monkeypatch.setattr("app.services.audio_clients.httpx.AsyncClient", FakeAsyncClient)

    client = AudioTranscriptionClient(
        settings,
        runtime_settings_client=FakeRuntimeSettingsClient(
            {
                "audio_transcription_provider": "openai",
                "audio_transcription_model": "gpt-4o-mini-transcribe",
                "audio_transcription_enabled": "1",
                "openai_api_key": "runtime-key",
                "openai_base_url": "https://api.openai.com/v1",
                "openai_timeout_seconds": "12",
            }
        ),
    )

    caplog.set_level(logging.WARNING)

    with pytest.raises(RuntimeError) as exc_info:
        await client.transcribe(b"ogg-bytes", "audio/ogg; codecs=opus", "media-123")

    message = str(exc_info.value)
    assert "OpenAI transcription rejected media media-123: 400" in message
    assert "Invalid audio format" in message
    assert "runtime-key" not in message
    assert "Authorization" not in message
    assert "ogg-bytes" not in message
    assert "OpenAI transcription rejected media_id=media-123 status_code=400 body=" in caplog.text
    assert "Invalid audio format" in caplog.text
    assert "runtime-key" not in caplog.text
