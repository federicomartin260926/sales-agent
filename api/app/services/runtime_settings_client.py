from __future__ import annotations

from typing import Any

import httpx

from app.config import Settings


class RuntimeSettingsClient:
    def __init__(self, settings: Settings, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.transport = transport

    async def fetch(self) -> dict[str, Any] | None:
        base_url = self.settings.backend_base_url.strip().rstrip("/")
        bearer_token = self.settings.sales_agent_bearer_token.strip()
        if base_url == "" or bearer_token == "":
            return None

        timeout = httpx.Timeout(5.0, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.get(
                    "/api/internal/runtime-settings",
                    headers={"Authorization": f"Bearer {bearer_token}"},
                )
                if response.status_code == httpx.codes.UNAUTHORIZED:
                    return None

                response.raise_for_status()
                payload = response.json()
        except (httpx.HTTPError, ValueError):
            return None

        return payload if isinstance(payload, dict) else None

    async def effective_values(self) -> dict[str, str]:
        payload = await self.fetch()
        if payload is None:
            return self._fallback_values()

        values = payload.get("values")
        if not isinstance(values, dict):
            return self._fallback_values()

        merged = self._fallback_values()
        for key, value in values.items():
            if isinstance(value, str):
                merged[key] = value

        return merged

    def _fallback_values(self) -> dict[str, str]:
        return {
            "llm_default_profile": self.settings.llm_provider,
            "openai_api_key": self.settings.openai_api_key,
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4o-mini",
            "ollama_base_url": self.settings.ollama_base_url,
            "ollama_model": "llama3.1",
            "audio_mode": "disabled",
            "audio_gateway_base_url": "",
            "audio_gateway_token": "",
        }
