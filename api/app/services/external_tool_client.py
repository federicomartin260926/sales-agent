from __future__ import annotations

import logging
import time
from typing import Any

import httpx

from app.config import Settings
from app.services.backend_client import BackendClient, BackendExternalTool


logger = logging.getLogger(__name__)


class ExternalToolClient:
    def __init__(
        self,
        settings: Settings,
        backend_client: BackendClient,
        transport: httpx.AsyncBaseTransport | None = None,
    ) -> None:
        self.settings = settings
        self.backend_client = backend_client
        self.transport = transport

    async def fetch_contact_context(
        self,
        tenant_id: str,
        tenant_slug: str | None,
        channel: str | None,
        external_channel_id: str | None,
        contact: Any,
        conversation_id: str | None,
        last_messages: list[dict[str, Any]] | None,
        message_text: str,
        external_message_id: str | None,
    ) -> dict[str, Any]:
        tool_type = "contact_context"
        normalized_contact = self._normalize_contact(contact)
        base_response = self._base_response(tool_type)

        if tenant_id.strip() == "":
            return {
                **base_response,
                "configured": False,
                "provider": None,
                "error_code": "not_configured",
            }

        tool = await self.backend_client.get_external_tool(tenant_id, tool_type)
        if tool is None:
            return {
                **base_response,
                "configured": False,
                "provider": None,
                "error_code": "not_configured",
            }

        provider = tool.provider.strip().lower()
        if provider != "n8n_webhook":
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "error_code": "unsupported_provider",
            }

        webhook_url = (tool.webhook_url or "").strip()
        if webhook_url == "":
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "error_code": "invalid_config",
            }

        payload = {
            "tool_type": tool_type,
            "tenant_id": tenant_id,
            "tenant_slug": tenant_slug,
            "channel": channel or "whatsapp",
            "external_channel_id": external_channel_id,
            "contact": normalized_contact,
            "conversation": {
                "id": conversation_id,
                "last_messages": last_messages or [],
            },
            "message": {
                "text": message_text,
                "external_message_id": external_message_id,
            },
        }

        timeout_seconds = tool.timeout_seconds if tool.timeout_seconds > 0 else 5
        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        headers = self._tool_headers(tool)
        started_at = time.perf_counter()

        try:
            async with httpx.AsyncClient(timeout=timeout, transport=self.transport) as client:
                response = await client.post(webhook_url, json=payload, headers=headers)
                response.raise_for_status()
                payload_data = response.json()
        except httpx.TimeoutException:
            latency_ms = self._latency_ms(started_at)
            logger.warning("External tool contact_context timeout tenant_id=%s", tenant_id)
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": "timeout",
            }
        except httpx.HTTPStatusError as exc:
            latency_ms = self._latency_ms(started_at)
            logger.warning(
                "External tool contact_context http error tenant_id=%s status=%s",
                tenant_id,
                exc.response.status_code,
            )
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": "http_error",
            }
        except (httpx.HTTPError, ValueError):
            latency_ms = self._latency_ms(started_at)
            logger.warning("External tool contact_context invalid response tenant_id=%s", tenant_id)
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": "invalid_response",
            }
        except Exception:
            latency_ms = self._latency_ms(started_at)
            logger.warning("External tool contact_context unexpected error tenant_id=%s", tenant_id)
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": "unexpected_error",
            }

        latency_ms = self._latency_ms(started_at)
        if not isinstance(payload_data, dict):
            logger.warning("External tool contact_context invalid response tenant_id=%s", tenant_id)
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": "invalid_response",
            }

        if not bool(payload_data.get("ok", False)):
            return {
                **base_response,
                "configured": True,
                "provider": tool.provider,
                "latency_ms": latency_ms,
                "error_code": self._string_or_default(payload_data.get("error_code"), "tool_error"),
                "data": payload_data,
            }

        return {
            "available": True,
            "configured": True,
            "tool_type": tool_type,
            "provider": tool.provider,
            "ok": True,
            "found": bool(payload_data.get("found", False)),
            "latency_ms": latency_ms,
            "error_code": None,
            "data": payload_data,
        }

    def _base_response(self, tool_type: str) -> dict[str, Any]:
        return {
            "available": False,
            "configured": True,
            "tool_type": tool_type,
            "provider": None,
            "ok": False,
            "found": False,
            "latency_ms": 0,
            "error_code": "unexpected_error",
            "data": None,
        }

    def _normalize_contact(self, contact: Any) -> dict[str, Any]:
        phone = self._contact_value(contact, "phone")
        name = self._contact_value(contact, "name")
        wa_id = self._contact_value(contact, "wa_id", fallback=phone)

        return {
            "wa_id": wa_id,
            "phone": phone,
            "name": name,
        }

    def _contact_value(self, contact: Any, *names: str, fallback: Any | None = None) -> Any | None:
        if isinstance(contact, dict):
            for name in names:
                value = contact.get(name)
                if value is not None and str(value).strip() != "":
                    return str(value).strip()
            return fallback

        for name in names:
            value = getattr(contact, name, None)
            if value is not None and str(value).strip() != "":
                return str(value).strip()

        return fallback

    def _tool_headers(self, tool: BackendExternalTool) -> dict[str, str]:
        headers = {"Accept": "application/json"}
        auth_type = (tool.auth_type or "").strip().lower()
        if auth_type == "bearer" and (tool.bearer_token or "").strip() != "":
            headers["Authorization"] = f"Bearer {tool.bearer_token.strip()}"

        return headers

    def _latency_ms(self, started_at: float) -> int:
        return int(round((time.perf_counter() - started_at) * 1000))

    def _string_or_default(self, value: Any, default: str) -> str:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return default