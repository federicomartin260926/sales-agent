from __future__ import annotations

import io
import math
import logging
import re
from dataclasses import dataclass
from typing import Any
from urllib.parse import quote

import httpx

from app.config import Settings
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


@dataclass(slots=True)
class AudioDownloadResult:
    content: bytes
    content_type: str | None
    media_id: str


@dataclass(slots=True)
class AudioTranscriptionResult:
    text: str
    provider: str
    model: str


@dataclass(slots=True)
class AudioTranscriptionConfiguration:
    provider: str
    model: str
    enabled: bool
    cost_unit: str
    cost_per_unit_eur: float
    currency: str
    notes: str | None
    base_url: str
    api_key: str
    timeout_seconds: int


class AudioGatewayClient:
    def __init__(self, settings: Settings) -> None:
        self.settings = settings

    def _auth_token(self) -> str:
        token = self.settings.audio_gateway_bearer_token.strip()
        if token != "":
            return token

        return self.settings.sales_agent_bearer_token.strip()

    async def download_whatsapp_media(self, media_id: str) -> AudioDownloadResult:
        base_url = self.settings.audio_gateway_base_url.strip().rstrip("/")
        if base_url == "":
            raise RuntimeError("Audio gateway base URL is not configured.")

        token = self._auth_token()
        if token == "":
            raise RuntimeError("Audio gateway bearer token is not configured.")

        timeout = httpx.Timeout(self.settings.audio_timeout_seconds, connect=2.0)
        media_path = quote(media_id.strip(), safe="")
        headers = {"Authorization": f"Bearer {token}"}

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout) as client:
                response = await client.get(f"/internal/media/whatsapp/{media_path}", headers=headers)
                response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            raise RuntimeError(f"Audio gateway rejected media {media_id}: {exc.response.status_code}") from exc
        except httpx.HTTPError as exc:
            raise RuntimeError(f"Unable to reach audio gateway for media {media_id}.") from exc

        content_type = response.headers.get("content-type")
        if not isinstance(content_type, str) or content_type.strip() == "":
            content_type = None

        if len(response.content) > self.settings.audio_max_bytes:
            raise RuntimeError("Audio media exceeds the supported size limit.")

        return AudioDownloadResult(content=response.content, content_type=content_type, media_id=media_id)


class AudioTranscriptionClient:
    def __init__(
        self,
        settings: Settings,
        runtime_settings_client: RuntimeSettingsClient | None = None,
    ) -> None:
        self.settings = settings
        self.runtime_settings_client = runtime_settings_client or RuntimeSettingsClient(settings)

    def estimate_cost_eur(self, duration_seconds: int, configuration: AudioTranscriptionConfiguration | None = None) -> float:
        seconds = max(0, int(duration_seconds))
        config = configuration or self._fallback_configuration()
        if not config.enabled:
            return 0.0

        return self._estimate_cost_from_configuration(seconds, config)

    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str) -> AudioTranscriptionResult:
        configuration = await self.resolve_configuration()
        if not configuration.enabled:
            raise RuntimeError("Audio transcription is disabled.")

        if configuration.provider.lower() != "openai":
            raise RuntimeError(f"Unsupported audio transcription provider: {configuration.provider}.")

        if configuration.base_url == "" or configuration.api_key == "" or configuration.model == "":
            raise RuntimeError("OpenAI transcription configuration is incomplete.")

        timeout = httpx.Timeout(configuration.timeout_seconds, connect=2.0)
        normalized_content_type, filename = self._audio_upload_metadata(content_type)
        file_payload = io.BytesIO(audio_bytes)
        files = {"file": (filename, file_payload, normalized_content_type)}
        data = {"model": configuration.model}
        headers = {"Authorization": f"Bearer {configuration.api_key}"}

        try:
            async with httpx.AsyncClient(base_url=configuration.base_url, timeout=timeout) as client:
                response = await client.post("/audio/transcriptions", data=data, files=files, headers=headers)
                response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            body = self._safe_response_body(exc.response)
            logger.warning(
                "OpenAI transcription rejected media_id=%s status_code=%s body=%s",
                media_id,
                exc.response.status_code,
                body,
            )
            raise RuntimeError(
                f"OpenAI transcription rejected media {media_id}: {exc.response.status_code} body={body}"
            ) from exc
        except httpx.HTTPError as exc:
            raise RuntimeError(f"Unable to reach OpenAI transcription API for media {media_id}.") from exc

        text = self._extract_text(response)
        if text == "":
            raise RuntimeError("OpenAI transcription response did not contain text.")

        return AudioTranscriptionResult(text=text, provider=configuration.provider, model=configuration.model)

    async def resolve_configuration(self) -> AudioTranscriptionConfiguration:
        configuration = await self.runtime_settings_client.effective_values()
        return self._configuration_from_values(configuration)

    def _fallback_configuration(self) -> AudioTranscriptionConfiguration:
        return self._configuration_from_values({})

    def _configuration_from_values(self, values: dict[str, Any]) -> AudioTranscriptionConfiguration:
        provider = str(values.get("audio_transcription_provider", self.settings.audio_transcription_provider)).strip() or "openai"
        model = str(values.get("audio_transcription_model", self.settings.audio_transcription_model or self.settings.openai_transcription_model)).strip()
        base_url = str(values.get("openai_base_url", "https://api.openai.com/v1")).strip().rstrip("/")
        api_key = str(values.get("openai_api_key", self.settings.openai_api_key)).strip()
        timeout_seconds = self._parse_timeout(values.get("openai_timeout_seconds"), self.settings.openai_timeout_seconds)
        enabled = self._parse_bool(values.get("audio_transcription_enabled"), self.settings.audio_transcription_enabled)
        cost_unit = str(values.get("audio_transcription_cost_unit", self.settings.audio_transcription_cost_unit)).strip().lower() or "minute"
        if cost_unit not in {"minute", "second"}:
            cost_unit = "minute"
        cost_per_unit_eur = self._parse_float(
            values.get("audio_transcription_cost_per_unit_eur"),
            self.settings.audio_transcription_cost_per_unit_eur,
        )
        currency = str(values.get("audio_transcription_currency", "EUR")).strip() or "EUR"
        notes = values.get("audio_transcription_notes")
        if not isinstance(notes, str):
            notes = None

        return AudioTranscriptionConfiguration(
            provider=provider,
            model=model,
            enabled=enabled,
            cost_unit=cost_unit,
            cost_per_unit_eur=cost_per_unit_eur,
            currency=currency,
            notes=notes,
            base_url=base_url,
            api_key=api_key,
            timeout_seconds=timeout_seconds,
        )

    def _estimate_cost_from_configuration(self, duration_seconds: int, configuration: AudioTranscriptionConfiguration) -> float:
        duration_seconds = max(0, int(duration_seconds))
        if configuration.cost_per_unit_eur <= 0.0:
            return 0.0

        if configuration.cost_unit == "second":
            return round(duration_seconds * configuration.cost_per_unit_eur, 8)

        return round(math.ceil(duration_seconds / 60.0) * configuration.cost_per_unit_eur, 8)

    def _extract_text(self, response: httpx.Response) -> str:
        try:
            payload = response.json()
        except ValueError:
            return response.text.strip()

        if isinstance(payload, dict):
            text = payload.get("text")
            if isinstance(text, str):
                return text.strip()

        return response.text.strip()

    def _parse_timeout(self, raw_timeout: Any, fallback: int) -> int:
        if isinstance(raw_timeout, str) and raw_timeout.strip().isdigit():
            return max(1, int(raw_timeout.strip()))

        if isinstance(raw_timeout, (int, float)):
            return max(1, int(raw_timeout))

        return fallback

    def _parse_float(self, raw_value: Any, fallback: float) -> float:
        if isinstance(raw_value, str) and raw_value.strip() != "":
            try:
                return max(0.0, float(raw_value.strip().replace(",", ".")))
            except ValueError:
                return max(0.0, fallback)

        if isinstance(raw_value, (int, float)):
            return max(0.0, float(raw_value))

        return max(0.0, fallback)

    def _parse_bool(self, raw_value: Any, fallback: bool) -> bool:
        if isinstance(raw_value, bool):
            return raw_value

        if isinstance(raw_value, str):
            normalized = raw_value.strip().lower()
            if normalized in {"1", "true", "yes", "on", "enabled"}:
                return True
            if normalized in {"0", "false", "no", "off", "disabled"}:
                return False

        return fallback

    def _audio_upload_metadata(self, content_type: str | None) -> tuple[str, str]:
        normalized_content_type = self._normalized_audio_content_type(content_type)
        extension = self._audio_extension_for_content_type(normalized_content_type)
        return normalized_content_type, f"whatsapp-audio{extension}"

    def _normalized_audio_content_type(self, content_type: str | None) -> str:
        if not isinstance(content_type, str):
            return "audio/ogg"

        normalized = content_type.split(";", 1)[0].strip().lower()
        if normalized in {"audio/ogg", "audio/mpeg", "audio/mp4", "audio/m4a"}:
            return normalized

        return "audio/ogg"

    def _audio_extension_for_content_type(self, content_type: str) -> str:
        if content_type == "audio/mpeg":
            return ".mp3"
        if content_type in {"audio/mp4", "audio/m4a"}:
            return ".m4a"
        return ".ogg"

    def _safe_response_body(self, response: httpx.Response) -> str:
        try:
            body = response.text
        except Exception:
            try:
                body = response.content.decode("utf-8", errors="replace")
            except Exception:
                return "<unavailable>"

        if not isinstance(body, str):
            return "<unavailable>"

        body = body.strip()
        if body == "":
            return "<empty>"

        body = body.replace("\r", " ").replace("\n", " ")
        body = re.sub(r"(Bearer\s+)[A-Za-z0-9._~-]+", r"\1[REDACTED]", body, flags=re.IGNORECASE)
        if len(body) > 1000:
            body = body[:1000] + "..."

        return body
