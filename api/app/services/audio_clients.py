from __future__ import annotations

import io
import math
import logging
import mimetypes
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
    model: str


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

    def estimate_cost_eur(self, duration_seconds: int) -> float:
        seconds = max(0, int(duration_seconds))
        cost_per_minute = max(0.0, float(self.settings.openai_audio_transcription_cost_per_minute_eur))
        return math.ceil(seconds / 60.0) * cost_per_minute

    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str) -> AudioTranscriptionResult:
        configuration = await self.runtime_settings_client.effective_values()
        base_url = configuration.get("openai_base_url", "").strip().rstrip("/")
        api_key = configuration.get("openai_api_key", "").strip()
        model = configuration.get("openai_transcription_model", self.settings.openai_transcription_model).strip()
        timeout_seconds = self._parse_timeout(configuration.get("openai_timeout_seconds"), self.settings.openai_timeout_seconds)

        if base_url == "" or api_key == "" or model == "":
            raise RuntimeError("OpenAI transcription configuration is incomplete.")

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        filename = self._filename_for_content_type(content_type, media_id)
        file_payload = io.BytesIO(audio_bytes)
        files = {"file": (filename, file_payload, content_type or "application/octet-stream")}
        data = {"model": model}
        headers = {"Authorization": f"Bearer {api_key}"}

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout) as client:
                response = await client.post("/audio/transcriptions", data=data, files=files, headers=headers)
                response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            raise RuntimeError(f"OpenAI transcription rejected media {media_id}: {exc.response.status_code}") from exc
        except httpx.HTTPError as exc:
            raise RuntimeError(f"Unable to reach OpenAI transcription API for media {media_id}.") from exc

        text = self._extract_text(response)
        if text == "":
            raise RuntimeError("OpenAI transcription response did not contain text.")

        return AudioTranscriptionResult(text=text, model=model)

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

    def _filename_for_content_type(self, content_type: str | None, media_id: str) -> str:
        if isinstance(content_type, str) and content_type.strip() != "":
            extension = mimetypes.guess_extension(content_type.split(";", 1)[0].strip())
            if isinstance(extension, str) and extension != "":
                return f"{media_id}{extension}"

        return f"{media_id}.audio"
