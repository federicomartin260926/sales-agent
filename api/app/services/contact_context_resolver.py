from __future__ import annotations

import json
from datetime import datetime, timedelta, timezone
from typing import Any

from app.config import Settings, get_settings
from app.schemas.agent import AgentRequest
from app.schemas.llm import McpRemoteConfig
from app.services.backend_client import BackendClient, BackendContactContextCache, CommercialContext
from app.services.external_tool_client import ExternalToolClient
from app.services.llm_client import LLMClient


class ContactContextResolver:
    def __init__(
        self,
        backend_client: BackendClient,
        llm_client: LLMClient | None = None,
        settings: Settings | None = None,
        external_tool_client: ExternalToolClient | None = None,
    ) -> None:
        self.backend_client = backend_client
        self.settings = settings or get_settings()
        self.llm_client = llm_client or LLMClient(self.settings)
        self.external_tool_client = external_tool_client or ExternalToolClient(self.settings, backend_client)

    async def resolve(
        self,
        payload: AgentRequest,
        backend_context: CommercialContext | None,
        mcp_config: McpRemoteConfig | None,
        recent_contact_context: dict[str, Any] | None = None,
    ) -> dict[str, Any] | None:
        tenant_id = self._tenant_id(backend_context, payload)
        if tenant_id is None:
            return self._build_diagnostic_context(
                recent_contact_context,
                available=False,
                source="none",
                error_code="missing_tenant_id",
                error_message="Tenant not available for contact context resolution.",
                cache_lookup=False,
                cache_hit=False,
                mcp_available=False,
                mcp_called=False,
                tool_called=False,
            )

        contact_key = self._contact_key(payload)
        if contact_key is None:
            return self._build_diagnostic_context(
                recent_contact_context,
                available=False,
                source="none",
                error_code="missing_contact_key",
                error_message="Contact key not available for contact context resolution.",
                cache_lookup=False,
                cache_hit=False,
                mcp_available=False,
                mcp_called=False,
                tool_called=False,
            )

        cache_lookup = True

        if self._is_recent_context_valid(recent_contact_context) and self._recent_context_is_trusted(recent_contact_context):
            return self._build_diagnostic_context(
                recent_contact_context,
                available=self._context_is_usable(recent_contact_context),
                source=self._context_source(recent_contact_context, default="context_messages"),
                error_code=self._context_error_code(recent_contact_context),
                error_message=self._context_error_message(recent_contact_context),
                cache_lookup=cache_lookup,
                cache_hit=False,
                mcp_available=self._mcp_available(mcp_config),
                mcp_called=False,
                tool_called=self._tool_called_in_context(recent_contact_context),
            )

        if self._is_recent_context_valid(recent_contact_context) and not self._recent_context_is_trusted(recent_contact_context):
            recent_contact_context = None

        cached_context = await self._load_cached_context(tenant_id, contact_key)
        if cached_context is not None:
            return self._build_diagnostic_context(
                cached_context,
                available=self._is_context_available(cached_context),
                source=self._context_source(cached_context, default="cache"),
                error_code=self._context_error_code(cached_context),
                error_message=self._context_error_message(cached_context),
                cache_lookup=cache_lookup,
                cache_hit=True,
                mcp_available=self._mcp_available(mcp_config),
                mcp_called=False,
                tool_called=self._tool_called_in_context(cached_context),
            )

        external_tool_context, external_tool_error_code, external_tool_error_message, external_tool_called = await self._resolve_external_tool_context(
            payload,
            backend_context,
        )
        if external_tool_context is not None:
            return self._build_diagnostic_context(
                external_tool_context,
                available=self._external_tool_context_usable(external_tool_context),
                source=self._context_source(external_tool_context, default="external_tool:n8n"),
                error_code=self._context_error_code(external_tool_context),
                error_message=self._context_error_message(external_tool_context),
                cache_lookup=cache_lookup,
                cache_hit=False,
                mcp_available=self._mcp_available(mcp_config),
                mcp_called=False,
                tool_called=self._tool_called_in_context(external_tool_context),
                external_tool_available=True,
                external_tool_called=external_tool_called,
            )

        mcp_available = self._mcp_available(mcp_config)
        refreshed_result = await self._refresh_context(payload, backend_context, mcp_config, contact_key, tenant_id)
        if refreshed_result is not None:
            refreshed_context, tool_called, error_code, error_message = refreshed_result
            return self._build_diagnostic_context(
                refreshed_context,
                available=self._is_context_available(refreshed_context),
                source=self._context_source(refreshed_context, default="mcp"),
                error_code=error_code,
                error_message=error_message,
                cache_lookup=cache_lookup,
                cache_hit=False,
                mcp_available=mcp_available,
                mcp_called=True,
                tool_called=tool_called,
            )

        if external_tool_error_code is not None or external_tool_error_message is not None:
            return self._build_diagnostic_context(
                recent_contact_context,
                available=False,
                source="external_tool:n8n",
                error_code=external_tool_error_code,
                error_message=external_tool_error_message,
                cache_lookup=cache_lookup,
                cache_hit=False,
                mcp_available=mcp_available,
                mcp_called=mcp_available,
                tool_called=False,
                external_tool_available=False,
                external_tool_called=external_tool_called,
            )

        return self._build_diagnostic_context(
            recent_contact_context,
            available=False,
            source="none",
            error_code="mcp_not_configured" if not mcp_available else "tool_not_called",
            error_message="Contact context resolution did not return a usable timezone.",
            cache_lookup=cache_lookup,
            cache_hit=False,
            mcp_available=mcp_available,
            mcp_called=mcp_available,
            tool_called=False,
        )

    async def _resolve_external_tool_context(
        self,
        payload: AgentRequest,
        backend_context: CommercialContext | None,
    ) -> tuple[dict[str, Any] | None, str | None, str | None, bool]:
        if self.external_tool_client is None:
            return None, None, None, False

        if not hasattr(self.external_tool_client, "fetch_contact_context"):
            return None, None, None, False

        if backend_context is None or backend_context.tenant.id.strip() == "":
            return None, None, None, False

        try:
            result = await self.external_tool_client.fetch_contact_context(
                backend_context.tenant.id,
                backend_context.tenant.slug,
                payload.conversation.channel or payload.channel_type,
                payload.external_channel_id,
                {
                    "phone": payload.contact.phone,
                    "name": payload.contact.name,
                    "email": payload.contact.email,
                },
                payload.conversation.external_id,
                payload.conversation.context_messages if isinstance(payload.conversation.context_messages, list) else [],
                payload.message.text or "",
                payload.message.id,
            )
        except Exception as exc:
            error_message = f"External contact_context failed: {exc.__class__.__name__}"
            return None, "n8n_error", error_message, True

        if not isinstance(result, dict):
            return None, "invalid_response", "External contact_context returned an invalid payload.", True

        if not bool(result.get("configured", False)):
            return None, "external_tool_not_configured", "External contact_context tool is not configured.", False

        data = result.get("data")
        if not isinstance(data, dict):
            return None, self._context_error_code(result) or "invalid_response", self._context_error_message(result) or "External contact_context returned no usable data.", True

        data = self._normalize_external_tool_context_data(data)
        context = dict(result)
        context["data"] = data
        context["source"] = "external_tool:n8n"
        context["external_tool_available"] = True
        context["external_tool_called"] = True

        if self._has_timezone_payload(context):
            context["available"] = self._external_tool_context_usable(context)
            return context, None, None, True

        return None, self._context_error_code(result) or "no_timezone", self._context_error_message(result) or "External contact_context did not return a timezone.", True

    def _external_tool_context_usable(self, context: dict[str, Any] | None) -> bool:
        if not isinstance(context, dict):
            return False

        if not self._has_timezone_payload(context):
            return False

        error_code = self._normalize_string(context.get("error_code"))
        if error_code is None:
            return bool(context.get("ok", False))

        if bool(context.get("ok", False)):
            return True

        return error_code in {"not_found"}

    def _is_recent_context_valid(self, recent_contact_context: dict[str, Any] | None) -> bool:
        if not isinstance(recent_contact_context, dict):
            return False

        return self._context_is_usable(recent_contact_context)

    async def invalidate(self, payload: AgentRequest, backend_context: CommercialContext | None) -> None:
        tenant_id = self._tenant_id(backend_context, payload)
        contact_key = self._contact_key(payload)
        if tenant_id is None or contact_key is None:
            return

        await self.backend_client.invalidate_contact_context_cache(tenant_id, contact_key)

    def _tenant_id(self, backend_context: CommercialContext | None, payload: AgentRequest) -> str | None:
        if backend_context is not None and backend_context.tenant.id.strip() != "":
            return backend_context.tenant.id.strip()

        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            return payload.tenant_id.strip()

        return None

    def _contact_key(self, payload: AgentRequest) -> str | None:
        phone = self._normalize_string(payload.contact.phone)
        if phone is not None:
            return f"phone:{phone}"

        email = self._normalize_string(payload.contact.email)
        if email is not None:
            return f"email:{email.lower()}"

        external_conversation_id = self._normalize_string(payload.conversation.external_id)
        if external_conversation_id is not None:
            return f"conversation:{external_conversation_id}"

        return None

    async def _load_cached_context(self, tenant_id: str, contact_key: str) -> dict[str, Any] | None:
        cache = await self.backend_client.get_contact_context_cache(tenant_id, contact_key)
        if not self._is_cache_valid(cache):
            return None

        return self._cache_to_context_payload(cache, source="cache")

    async def _refresh_context(
        self,
        payload: AgentRequest,
        backend_context: CommercialContext | None,
        mcp_config: McpRemoteConfig | None,
        contact_key: str,
        tenant_id: str,
    ) -> tuple[dict[str, Any], bool, str | None, str | None] | None:
        if mcp_config is None or not mcp_config.enabled:
            return None

        if mcp_config.server_label is None or mcp_config.server_url is None:
            return None

        refresh_config = mcp_config.model_copy(update={"allowed_tools": ["contact_context"]})
        system_prompt = (
            "Call contact_context for this tenant/contact/channel. "
            "Do not answer the user. Do not call any other tool. Return after the tool result."
        )
        user_payload = {
            "tenant_id": tenant_id,
            "tenant_slug": backend_context.tenant.slug if backend_context is not None else None,
            "channel": payload.conversation.channel or payload.channel_type,
            "external_channel_id": payload.external_channel_id,
            "external_conversation_id": payload.conversation.external_id,
            "contact": {
                "phone": payload.contact.phone,
                "name": payload.contact.name,
                "email": payload.contact.email,
            },
            "message": payload.message.text or "",
        }

        try:
            result = await self.llm_client.generate_with_mcp(
                "openai",
                system_prompt,
                json.dumps(user_payload, ensure_ascii=False, indent=2),
                refresh_config,
                await self.llm_client.resolve_configuration(),
                tool_choice="required",
                parallel_tool_calls=False,
            )
        except Exception as exc:
            error_message = f"MCP refresh failed: {exc.__class__.__name__}"
            return (
                self._empty_mcp_context(source="mcp_error", error_code="mcp_error", error_message=error_message),
                False,
                "mcp_error",
                error_message,
            )

        contact_context_trace = self._find_contact_context_trace(result.tool_traces)
        if contact_context_trace is None:
            return (
                self._empty_mcp_context(source="none", error_code="tool_not_called", error_message="contact_context tool was not called."),
                False,
                "tool_not_called",
                "contact_context tool was not called.",
            )

        contact_context = self._extract_contact_context_from_trace(contact_context_trace)
        if contact_context is None:
            return (
                self._empty_mcp_context(source="mcp", error_code="empty_tool_output", error_message="contact_context tool returned no usable payload."),
                True,
                "empty_tool_output",
                "contact_context tool returned no usable payload.",
            )

        error_code = self._context_error_code(contact_context)
        error_message = self._context_error_message(contact_context)

        cache_payload = self._context_to_cache_payload(
            tenant_id,
            contact_key,
            payload,
            contact_context,
            source="mcp",
            status="success",
        )
        saved_cache = await self.backend_client.save_contact_context_cache(cache_payload)
        if saved_cache is not None:
            return self._cache_to_context_payload(saved_cache, source="mcp_refresh"), True, error_code, error_message

        return self._cache_payload_to_context(contact_context, source="mcp_refresh"), True, error_code, error_message

    def _find_contact_context_trace(self, tool_traces: list[Any]) -> Any | None:
        for trace in reversed(tool_traces):
            tool_name = getattr(trace, "tool_name", None)
            if isinstance(tool_name, str) and tool_name.strip() == "contact_context":
                return trace

            raw = getattr(trace, "raw", None)
            if isinstance(raw, dict):
                raw_name = raw.get("tool_name") or raw.get("toolName") or raw.get("name")
                if isinstance(raw_name, str) and raw_name.strip() == "contact_context":
                    return trace

            output = getattr(trace, "output", None)
            if isinstance(output, dict):
                tool_name = output.get("tool_name") or output.get("toolName") or output.get("name")
                if isinstance(tool_name, str) and tool_name.strip() == "contact_context":
                    return trace

        return None

    def _extract_contact_context_from_trace(self, trace: Any) -> dict[str, Any] | None:
        output = getattr(trace, "output", None)
        if not isinstance(output, dict):
            raw = getattr(trace, "raw", None)
            if isinstance(raw, dict):
                raw_output = raw.get("output")
                if isinstance(raw_output, dict):
                    output = raw_output

        if not isinstance(output, dict):
            return None

        if not output:
            return None

        if not self._has_timezone_payload(output) and not bool(output.get("ok", False)):
            return None

        return output

    def _is_cache_valid(self, cache: BackendContactContextCache | None) -> bool:
        if cache is None:
            return False

        if cache.status != "success":
            return False

        if not isinstance(cache.context_json, dict):
            return False

        if not bool(cache.context_json.get("ok", False)):
            return False

        return self._parse_datetime(cache.expires_at) > datetime.now(timezone.utc)

    def _cache_to_context_payload(self, cache: BackendContactContextCache, source: str) -> dict[str, Any]:
        context = self._cache_payload_to_context(cache.context_json or {}, source=source)
        context["fetched_at"] = cache.fetched_at
        context["expires_at"] = cache.expires_at
        context["status"] = cache.status
        context["cache_source"] = source
        return context

    def _cache_payload_to_context(self, context_json: dict[str, Any], source: str) -> dict[str, Any]:
        payload = dict(context_json)
        payload["source"] = source
        return payload

    def _context_to_cache_payload(
        self,
        tenant_id: str,
        contact_key: str,
        payload: AgentRequest,
        contact_context: dict[str, Any],
        *,
        source: str,
        status: str,
    ) -> dict[str, Any]:
        fetched_at = datetime.now(timezone.utc)
        ttl_minutes = max(1, int(self.settings.contact_context_cache_ttl_minutes))
        expires_at = fetched_at + timedelta(minutes=ttl_minutes)
        return {
            "tenant_id": tenant_id,
            "contact_key": contact_key,
            "provider": "contact_context",
            "source": source,
            "status": status,
            "channel": payload.channel_type,
            "external_channel_id": payload.external_channel_id,
            "external_conversation_id": payload.conversation.external_id,
            "contact_phone": payload.contact.phone,
            "contact_email": payload.contact.email,
            "context_json": contact_context,
            "fetched_at": fetched_at.isoformat(),
            "expires_at": expires_at.isoformat(),
            "ttl_minutes": ttl_minutes,
        }

    def _parse_datetime(self, value: str) -> datetime:
        try:
            return datetime.fromisoformat(value)
        except Exception:
            return datetime.min.replace(tzinfo=timezone.utc)

    def _is_context_available(self, context: dict[str, Any] | None) -> bool:
        if not isinstance(context, dict):
            return False

        return bool(context.get("available", False) or context.get("ok", False) or context.get("found", False))

    def _tool_called_in_context(self, context: dict[str, Any] | None) -> bool:
        if not isinstance(context, dict):
            return False

        tool_called = context.get("tool_called")
        if isinstance(tool_called, bool):
            return tool_called

        source = self._context_source(context, default="")
        return source in {"cache", "context_messages", "contact_context", "mcp", "mcp_refresh"}

    def _mcp_available(self, mcp_config: McpRemoteConfig | None) -> bool:
        if mcp_config is None or not mcp_config.enabled:
            return False

        return mcp_config.server_label is not None and mcp_config.server_label.strip() != "" and mcp_config.server_url is not None and mcp_config.server_url.strip() != ""

    def _context_source(self, context: dict[str, Any] | None, default: str) -> str:
        if not isinstance(context, dict):
            return default

        source = context.get("source")
        if isinstance(source, str) and source.strip() != "":
            return source.strip()

        cache_source = context.get("cache_source")
        if isinstance(cache_source, str) and cache_source.strip() != "":
            return cache_source.strip()

        return default

    def _context_error_code(self, context: dict[str, Any] | None) -> str | None:
        if not isinstance(context, dict):
            return None

        error_code = context.get("error_code")
        if isinstance(error_code, str) and error_code.strip() != "":
            return error_code.strip()

        return None

    def _context_error_message(self, context: dict[str, Any] | None) -> str | None:
        if not isinstance(context, dict):
            return None

        error_message = context.get("error_message")
        if isinstance(error_message, str) and error_message.strip() != "":
            return error_message.strip()

        message = context.get("message")
        if isinstance(message, str) and message.strip() != "":
            return message.strip()

        return None

    def _context_is_usable(self, context: dict[str, Any] | None) -> bool:
        if not isinstance(context, dict):
            return False

        if not self._has_timezone_payload(context):
            return False

        if bool(context.get("ok", False)):
            return True

        error_code = self._normalize_string(context.get("error_code"))
        return error_code in {"not_found"}

    def _recent_context_is_trusted(self, context: dict[str, Any] | None) -> bool:
        if not isinstance(context, dict):
            return False

        source = self._context_source(context, default="")
        provider = self._normalize_string(context.get("provider"))

        if source in {"cache", "external_tool:n8n"}:
            return True

        if source in {"", "context_messages"}:
            return provider is None or provider == "n8n_webhook"

        return False

    def _build_diagnostic_context(
        self,
        context: dict[str, Any] | None,
        *,
        available: bool,
        source: str,
        error_code: str | None,
        error_message: str | None,
        cache_lookup: bool,
        cache_hit: bool,
        mcp_available: bool,
        mcp_called: bool,
        tool_called: bool,
        external_tool_available: bool | None = None,
        external_tool_called: bool | None = None,
    ) -> dict[str, Any]:
        payload: dict[str, Any] = dict(context) if isinstance(context, dict) else {}
        payload["available"] = available
        payload["configured"] = bool(payload.get("configured", mcp_available))
        payload["ok"] = bool(payload.get("ok", available))
        payload["found"] = bool(payload.get("found", available))
        payload["source"] = source
        payload["error_code"] = error_code
        payload["error_message"] = error_message
        payload["cache_lookup"] = cache_lookup
        payload["cache_hit"] = cache_hit
        payload["mcp_available"] = mcp_available
        payload["mcp_called"] = mcp_called
        payload["tool_called"] = tool_called
        if external_tool_available is not None:
            payload["external_tool_available"] = external_tool_available
        if external_tool_called is not None:
            payload["external_tool_called"] = external_tool_called
        return payload

    def _normalize_external_tool_context_data(self, data: dict[str, Any]) -> dict[str, Any]:
        normalized = dict(data)
        nested_data = normalized.get("data")
        if isinstance(nested_data, dict) and self._has_timezone_payload(nested_data):
            normalized = dict(nested_data)
            if "source" not in normalized and isinstance(data.get("source"), str):
                normalized["source"] = data.get("source")
            if "summary" not in normalized and isinstance(data.get("summary"), str):
                normalized["summary"] = data.get("summary")

        return normalized

    def _empty_mcp_context(self, *, source: str, error_code: str | None, error_message: str | None) -> dict[str, Any]:
        return {
            "available": False,
            "configured": True,
            "provider": "n8n_webhook",
            "ok": False,
            "found": False,
            "error_code": error_code,
            "error_message": error_message,
            "source": source,
            "data": {},
        }

    def _normalize_string(self, value: Any) -> str | None:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None

    def _has_timezone_payload(self, output: dict[str, Any]) -> bool:
        timezone = self._normalize_string(output.get("timezone"))
        if timezone is not None:
            return True

        business_context = output.get("business_context")
        if isinstance(business_context, dict):
            return self._normalize_string(business_context.get("timezone")) is not None

        data = output.get("data")
        if isinstance(data, dict):
            timezone = self._normalize_string(data.get("timezone"))
            if timezone is not None:
                return True

            business_context = data.get("business_context")
            if isinstance(business_context, dict):
                return self._normalize_string(business_context.get("timezone")) is not None

        return False
