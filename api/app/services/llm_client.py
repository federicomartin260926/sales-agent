from __future__ import annotations

import logging
from dataclasses import dataclass
from typing import Any

import httpx

from app.config import Settings
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


@dataclass(slots=True)
class LLMGenerationResult:
    provider: str
    content: str


class LLMClient:
    def __init__(self, settings: Settings, runtime_settings_client: RuntimeSettingsClient | None = None, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.runtime_settings_client = runtime_settings_client or RuntimeSettingsClient(settings)
        self.transport = transport

    async def resolve_configuration(self) -> dict[str, str]:
        return await self.runtime_settings_client.effective_values()

    async def generate(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        configuration: dict[str, str] | None = None,
    ) -> LLMGenerationResult:
        config = configuration if configuration is not None else await self.resolve_configuration()
        normalized_provider = provider.strip().lower()
        if normalized_provider == "openai":
            return await self._generate_openai(system_prompt, user_prompt, config)
        if normalized_provider == "ollama":
            return await self._generate_ollama(system_prompt, user_prompt, config)

        raise ValueError(f"Unsupported LLM provider '{provider}'")

    async def _generate_openai(self, system_prompt: str, user_prompt: str, configuration: dict[str, str]) -> LLMGenerationResult:
        base_url = configuration.get("openai_base_url", "").strip().rstrip("/")
        model = configuration.get("openai_model", "").strip()
        api_key = configuration.get("openai_api_key", "").strip()
        timeout_seconds = self._parse_timeout(configuration.get("openai_timeout_seconds"), self.settings.openai_timeout_seconds)

        if base_url == "" or model == "" or api_key == "":
            raise ValueError("OpenAI configuration is incomplete")

        payload = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
        }

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        }

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post("/chat/completions", json=payload, headers=headers)
                response.raise_for_status()
                payload_json = response.json()
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError(f"OpenAI request failed: {exc}") from exc

        content = self._extract_openai_content(payload_json)
        if content is None:
            raise ValueError("OpenAI response did not include message content")

        logger.debug("LLM openai generation completed model=%s", model)
        return LLMGenerationResult(provider="openai", content=content)

    async def _generate_ollama(self, system_prompt: str, user_prompt: str, configuration: dict[str, str]) -> LLMGenerationResult:
        base_url = configuration.get("ollama_base_url", "").strip().rstrip("/")
        model = configuration.get("ollama_model", "").strip()
        timeout_seconds = self._parse_timeout(configuration.get("ollama_timeout_seconds"), self.settings.ollama_timeout_seconds)

        if base_url == "" or model == "":
            raise ValueError("Ollama configuration is incomplete")

        payload = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "stream": False,
            "format": "json",
            "options": {"temperature": 0.2},
        }

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post("/api/chat", json=payload)
                response.raise_for_status()
                payload_json = response.json()
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError(f"Ollama request failed: {exc}") from exc

        content = self._extract_ollama_content(payload_json)
        if content is None:
            raise ValueError("Ollama response did not include message content")

        logger.debug("LLM ollama generation completed model=%s", model)
        return LLMGenerationResult(provider="ollama", content=content)

    def _extract_openai_content(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        choices = payload.get("choices")
        if not isinstance(choices, list) or not choices:
            return None

        first_choice = choices[0]
        if not isinstance(first_choice, dict):
            return None

        message = first_choice.get("message")
        if not isinstance(message, dict):
            return None

        content = message.get("content")
        if isinstance(content, str) and content.strip() != "":
            return content.strip()

        return None

    def _extract_ollama_content(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        message = payload.get("message")
        if isinstance(message, dict):
            content = message.get("content")
            if isinstance(content, str) and content.strip() != "":
                return content.strip()

        response_content = payload.get("response")
        if isinstance(response_content, str) and response_content.strip() != "":
            return response_content.strip()

        return None

    def _parse_timeout(self, value: str | None, fallback: int) -> int:
        if value is None:
            return fallback

        try:
            parsed = int(value)
        except ValueError:
            return fallback

        return parsed if parsed > 0 else fallback
