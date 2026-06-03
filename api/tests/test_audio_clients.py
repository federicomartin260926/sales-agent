from __future__ import annotations

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
