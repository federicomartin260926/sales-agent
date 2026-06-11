from datetime import datetime, timedelta, timezone

import httpx
import pytest

from app.config import Settings
from app.schemas.agent import AgentRequest, Contact
from app.schemas.llm import LLMResponseResult, LLMToolTrace, LLMUsage, McpRemoteConfig
from app.services.backend_client import BackendContactContextCache, BackendExternalTool, BackendTenant, CommercialContext
from app.services.contact_context_resolver import ContactContextResolver
from app.services.external_tool_client import ExternalToolClient


def build_backend_context() -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )


def build_contact_context_payload(timezone_name: str = "Atlantic/Canary", timezone_source: str = "crm_tenant") -> dict[str, object]:
    return {
        "available": True,
        "configured": True,
        "tool_type": "contact_context",
        "provider": "n8n_webhook",
        "ok": True,
        "found": True,
        "latency_ms": 22,
        "error_code": None,
        "data": {
            "source": "contact_context",
            "summary": "Contexto externo resuelto",
            "timezone": timezone_name,
            "timezone_source": timezone_source,
            "needs_branch_selection": False,
            "business_context": {
                "timezone": timezone_name,
                "timezone_source": timezone_source,
                "needs_branch_selection": False,
            },
            "contact": {
                "name": "Ana García",
                "phone": "+34999999999",
                "email": "ana@example.com",
            },
            "flags": {
                "needs_human": False,
                "do_not_contact": False,
                "existing_customer": True,
            },
        },
}


def build_external_tool() -> BackendExternalTool:
    return BackendExternalTool.model_validate(
        {
            "id": "tool-1",
            "tenant_id": "tenant-1",
            "name": "Contact Context Mary",
            "type": "contact_context",
            "provider": "n8n_webhook",
            "webhook_url": "https://n8n.example.test/webhook/contact-context",
            "auth_type": "bearer",
            "bearer_token": "webhook-token",
            "downstream_authorization_token": "downstream-token",
            "downstream_authorization_configured": True,
            "timeout_seconds": 5,
            "is_active": True,
            "config": {},
        }
    )


class RecordingBackendClient:
    def __init__(self, cache: BackendContactContextCache | None = None, external_tool: object | None = None) -> None:
        self.cache = cache
        self.external_tool = external_tool
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def get_contact_context_cache(self, tenant_id: str, contact_key: str, provider: str = "contact_context"):
        self.calls.append(("get_contact_context_cache", (tenant_id, contact_key, provider)))
        return self.cache

    async def save_contact_context_cache(self, payload: dict[str, object]):
        self.calls.append(("save_contact_context_cache", (payload,)))
        if self.cache is not None:
            return self.cache

        now = datetime.now(timezone.utc)
        return BackendContactContextCache.model_validate(
            {
                "id": "cache-1",
                "tenant_id": payload["tenant_id"],
                "channel": payload.get("channel"),
                "external_channel_id": payload.get("external_channel_id"),
                "external_conversation_id": payload.get("external_conversation_id"),
                "contact_phone": payload.get("contact_phone"),
                "contact_email": payload.get("contact_email"),
                "contact_key": payload["contact_key"],
                "provider": payload["provider"],
                "source": payload["source"],
                "status": payload["status"],
                "context_json": payload["context_json"],
                "fetched_at": payload["fetched_at"],
                "expires_at": payload["expires_at"],
                "created_at": now.isoformat(),
                "updated_at": now.isoformat(),
            }
        )

    async def invalidate_contact_context_cache(self, tenant_id: str, contact_key: str, provider: str = "contact_context"):
        self.calls.append(("invalidate_contact_context_cache", (tenant_id, contact_key, provider)))
        return None

    async def get_external_tool(self, tenant_id: str, tool_type: str):
        self.calls.append(("get_external_tool", (tenant_id, tool_type)))
        if tool_type == "contact_context":
            return self.external_tool

        return None


class RecordingLLMClient:
    def __init__(self, result: LLMResponseResult) -> None:
        self.result = result
        self.calls: list[dict[str, object]] = []

    async def resolve_configuration(self):
        return {
            "openai_base_url": "https://openai.example.test",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "test-key",
        }

    async def generate_with_mcp(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        mcp_config: McpRemoteConfig,
        configuration: dict[str, str] | None = None,
        previous_response_id: str | None = None,
        tool_choice=None,
        parallel_tool_calls=None,
    ) -> LLMResponseResult:
        self.calls.append(
            {
                "provider": provider,
                "system_prompt": system_prompt,
                "user_prompt": user_prompt,
                "mcp_config": mcp_config,
                "configuration": configuration,
                "previous_response_id": previous_response_id,
                "tool_choice": tool_choice,
                "parallel_tool_calls": parallel_tool_calls,
            }
        )
        return self.result


class RecordingExternalToolClient:
    def __init__(self, result: dict[str, object]) -> None:
        self.result = result
        self.calls: list[dict[str, object]] = []

    async def fetch_contact_context(
        self,
        tenant_id: str,
        tenant_slug: str | None,
        channel: str | None,
        external_channel_id: str | None,
        contact,
        conversation_id: str | None,
        last_messages: list[dict[str, object]] | None,
        message_text: str,
        external_message_id: str | None,
    ) -> dict[str, object]:
        self.calls.append(
            {
                "tenant_id": tenant_id,
                "tenant_slug": tenant_slug,
                "channel": channel,
                "external_channel_id": external_channel_id,
                "contact": contact,
                "conversation_id": conversation_id,
                "last_messages": last_messages,
                "message_text": message_text,
                "external_message_id": external_message_id,
            }
        )
        return self.result


@pytest.mark.asyncio
async def test_contact_context_resolver_uses_backend_n8n_service_and_skips_mcp_when_available():
    backend = RecordingBackendClient(cache=None, external_tool=build_external_tool())

    def transport_handler(request: httpx.Request) -> httpx.Response:
        assert request.method == "POST"
        assert request.url == httpx.URL("https://n8n.example.test/webhook/contact-context")
        assert request.headers.get("Authorization") == "Bearer webhook-token"
        assert request.headers.get("X-Downstream-Authorization") == "Bearer downstream-token"

        return httpx.Response(
            200,
            json=build_contact_context_payload(),
        )

    external_tool_client = ExternalToolClient(Settings(), backend, transport=httpx.MockTransport(transport_handler))
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(), external_tool_client)
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )
    recent_contact_context = {
        "available": True,
        "configured": True,
        "provider": "mcp_remote",
        "ok": True,
        "found": True,
        "error_code": None,
        "data": {
            "source": "mcp",
            "timezone": "Europe/Madrid",
            "timezone_source": "mcp",
            "business_context": {
                "timezone": "Europe/Madrid",
                "timezone_source": "mcp",
            },
        },
    }

    result = await resolver.resolve(payload, backend_context, mcp_config, recent_contact_context=recent_contact_context)

    assert result is not None
    assert result["source"] == "external_tool:n8n"
    assert result["available"] is True
    assert result["external_tool_available"] is True
    assert result["external_tool_called"] is True
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert result["data"]["business_context"]["timezone_source"] == "crm_tenant"
    assert backend.calls == [
        ("get_contact_context_cache", ("tenant-1", "phone:+34999999999", "contact_context")),
        ("get_external_tool", ("tenant-1", "contact_context")),
    ]
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_uses_cache_without_refresh():
    now = datetime.now(timezone.utc)
    cache = BackendContactContextCache.model_validate(
        {
            "id": "cache-1",
            "tenant_id": "tenant-1",
            "channel": "whatsapp",
            "external_channel_id": "external-channel-1",
            "external_conversation_id": "external-conversation-1",
            "contact_phone": "+34999999999",
            "contact_email": "ana@example.com",
            "contact_key": "phone:+34999999999",
            "provider": "contact_context",
            "source": "mcp",
            "status": "success",
            "context_json": build_contact_context_payload(),
            "fetched_at": now.isoformat(),
            "expires_at": (now + timedelta(minutes=30)).isoformat(),
            "created_at": now.isoformat(),
            "updated_at": now.isoformat(),
        }
    )
    backend = RecordingBackendClient(cache=cache)
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings())
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["source"] == "cache"
    assert result["cache_source"] == "cache"
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert result["data"]["business_context"]["timezone_source"] == "crm_tenant"
    assert backend.calls == [("get_contact_context_cache", ("tenant-1", "phone:+34999999999", "contact_context"))]
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_prefers_recent_context_before_cache():
    now = datetime.now(timezone.utc)
    cache = BackendContactContextCache.model_validate(
        {
            "id": "cache-1",
            "tenant_id": "tenant-1",
            "channel": "whatsapp",
            "external_channel_id": "external-channel-1",
            "external_conversation_id": "external-conversation-1",
            "contact_phone": "+34999999999",
            "contact_email": "ana@example.com",
            "contact_key": "phone:+34999999999",
            "provider": "contact_context",
            "source": "cache",
            "status": "success",
            "context_json": build_contact_context_payload("Europe/Madrid"),
            "fetched_at": now.isoformat(),
            "expires_at": (now + timedelta(minutes=30)).isoformat(),
            "created_at": now.isoformat(),
            "updated_at": now.isoformat(),
        }
    )
    backend = RecordingBackendClient(cache=cache)
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings())
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )
    recent_contact_context = build_contact_context_payload("Atlantic/Canary")

    result = await resolver.resolve(payload, backend_context, mcp_config, recent_contact_context=recent_contact_context)

    assert result is not None
    assert result["source"] == "context_messages"
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert backend.calls == []
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_uses_recent_context_when_not_found_but_timezone_is_present():
    backend = RecordingBackendClient(cache=None)
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings())
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )
    recent_contact_context = {
        "available": False,
        "configured": True,
        "provider": "n8n_webhook",
        "ok": False,
        "found": False,
        "error_code": "not_found",
        "error_message": "Contact not found, but timezone resolved.",
        "data": {
            "source": "contact_context",
            "summary": "Contexto externo resuelto",
            "timezone": "Atlantic/Canary",
            "timezone_source": "crm_tenant",
            "business_context": {
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "needs_branch_selection": False,
            },
        },
    }

    result = await resolver.resolve(payload, backend_context, mcp_config, recent_contact_context=recent_contact_context)

    assert result is not None
    assert result["source"] == "context_messages"
    assert result["available"] is True
    assert result["error_code"] == "not_found"
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert backend.calls == []
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_does_not_use_recent_context_when_validation_error_has_timezone():
    now = datetime.now(timezone.utc)
    cache = BackendContactContextCache.model_validate(
        {
            "id": "cache-1",
            "tenant_id": "tenant-1",
            "channel": "whatsapp",
            "external_channel_id": "external-channel-1",
            "external_conversation_id": "external-conversation-1",
            "contact_phone": "+34999999999",
            "contact_email": "ana@example.com",
            "contact_key": "phone:+34999999999",
            "provider": "contact_context",
            "source": "cache",
            "status": "success",
            "context_json": build_contact_context_payload("Europe/Madrid"),
            "fetched_at": now.isoformat(),
            "expires_at": (now + timedelta(minutes=30)).isoformat(),
            "created_at": now.isoformat(),
            "updated_at": now.isoformat(),
        }
    )
    backend = RecordingBackendClient(cache=cache)
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings())
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )
    recent_contact_context = {
        "available": False,
        "configured": True,
        "provider": "n8n_webhook",
        "ok": False,
        "found": False,
        "error_code": "validation_error",
        "error_message": "Payload invalid.",
        "data": {
            "source": "contact_context",
            "summary": "Contexto externo resuelto",
            "timezone": "Atlantic/Canary",
            "timezone_source": "crm_tenant",
            "business_context": {
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "needs_branch_selection": False,
            },
        },
    }

    result = await resolver.resolve(payload, backend_context, mcp_config, recent_contact_context=recent_contact_context)

    assert result is not None
    assert result["source"] == "cache"
    assert result["available"] is True
    assert result["data"]["timezone"] == "Europe/Madrid"
    assert backend.calls == [("get_contact_context_cache", ("tenant-1", "phone:+34999999999", "contact_context"))]
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_uses_external_tool_directly_when_cache_misses():
    backend = RecordingBackendClient(cache=None)
    external_tool_result = build_contact_context_payload("Atlantic/Canary", "crm_tenant")
    external_tool_client = RecordingExternalToolClient(
        {
            **external_tool_result,
            "source": "external_tool:n8n",
            "external_tool_available": True,
            "external_tool_called": True,
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(), external_tool_client)
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["source"] == "external_tool:n8n"
    assert result["available"] is True
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert result["data"]["business_context"]["timezone"] == "Atlantic/Canary"
    assert result["external_tool_available"] is True
    assert result["external_tool_called"] is True
    assert backend.calls == [("get_contact_context_cache", ("tenant-1", "phone:+34999999999", "contact_context"))]
    assert len(external_tool_client.calls) == 1
    assert llm_client.calls == []


@pytest.mark.asyncio
async def test_contact_context_resolver_keeps_timezone_when_external_tool_reports_not_found():
    backend = RecordingBackendClient(cache=None)
    external_tool_client = RecordingExternalToolClient(
        {
            "available": False,
            "configured": True,
            "tool_type": "contact_context",
            "provider": "n8n_webhook",
            "ok": False,
            "found": False,
            "error_code": "not_found",
            "error_message": "No contact found, but business timezone resolved.",
            "data": {
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "business_context": {
                    "timezone": "Atlantic/Canary",
                    "timezone_source": "crm_tenant",
                },
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(), external_tool_client)
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is True
    assert result["source"] == "external_tool:n8n"
    assert result["error_code"] == "not_found"
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert result["data"]["business_context"]["timezone"] == "Atlantic/Canary"
    assert len(external_tool_client.calls) == 1


@pytest.mark.asyncio
async def test_contact_context_resolver_blocks_when_external_tool_reports_validation_error_even_with_timezone():
    backend = RecordingBackendClient(cache=None)
    external_tool_client = RecordingExternalToolClient(
        {
            "available": False,
            "configured": True,
            "tool_type": "contact_context",
            "provider": "n8n_webhook",
            "ok": False,
            "found": False,
            "error_code": "validation_error",
            "error_message": "Payload invalid.",
            "data": {
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[],
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(), external_tool_client)
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is False
    assert result["source"] == "external_tool:n8n"
    assert result["error_code"] == "validation_error"
    assert result["error_message"] == "Payload invalid."
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert len(external_tool_client.calls) == 1


@pytest.mark.asyncio
async def test_contact_context_resolver_refreshes_via_mcp_and_persists_cache():
    backend = RecordingBackendClient(cache=None)
    tool_output = build_contact_context_payload()
    tool_trace = LLMToolTrace.model_validate(
        {
            "type": "mcp_tool_call",
            "tool_name": "contact_context",
            "output": tool_output,
            "raw": {
                "type": "mcp_tool_call",
                "tool_name": "contact_context",
                "output": tool_output,
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[tool_trace],
        usage=LLMUsage(provider="openai", model="gpt-4.1-mini", input_tokens=10, output_tokens=2, total_tokens=12),
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(CONTACT_CONTEXT_CACHE_TTL_MINUTES=120))
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["source"] == "mcp_refresh"
    assert result["data"]["timezone"] == "Atlantic/Canary"
    assert result["data"]["business_context"]["timezone_source"] == "crm_tenant"
    assert len(llm_client.calls) == 1
    assert llm_client.calls[0]["tool_choice"] == "required"
    assert llm_client.calls[0]["parallel_tool_calls"] is False
    assert llm_client.calls[0]["mcp_config"].allowed_tools == ["contact_context"]

    save_calls = [call for call in backend.calls if call[0] == "save_contact_context_cache"]
    assert len(save_calls) == 1
    cache_payload = save_calls[0][1][0]
    assert cache_payload["provider"] == "contact_context"
    assert cache_payload["source"] == "mcp"
    assert cache_payload["status"] == "success"
    assert cache_payload["ttl_minutes"] == 120
    assert cache_payload["context_json"]["data"]["timezone"] == "Atlantic/Canary"


@pytest.mark.asyncio
async def test_contact_context_resolver_preserves_timezone_even_when_mcp_reports_not_found():
    backend = RecordingBackendClient(cache=None)
    tool_output = {
        "available": False,
        "configured": True,
        "tool_type": "contact_context",
        "provider": "n8n_webhook",
        "ok": False,
        "found": False,
        "error_code": "not_found",
        "message": "No contact found, but business timezone resolved.",
        "business_context": {
            "timezone": "Atlantic/Canary",
            "timezone_source": "crm_tenant",
            "needs_branch_selection": False,
        },
        "data": {
            "business_context": {
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "needs_branch_selection": False,
            }
        },
    }
    tool_trace = LLMToolTrace.model_validate(
        {
            "type": "mcp_tool_call",
            "tool_name": "contact_context",
            "output": tool_output,
            "raw": {
                "type": "mcp_tool_call",
                "tool_name": "contact_context",
                "output": tool_output,
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[tool_trace],
        usage=LLMUsage(provider="openai", model="gpt-4.1-mini", input_tokens=10, output_tokens=2, total_tokens=12),
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(CONTACT_CONTEXT_CACHE_TTL_MINUTES=120))
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is False
    assert result["source"] == "mcp_refresh"
    assert result["business_context"]["timezone"] == "Atlantic/Canary"
    assert result["business_context"]["timezone_source"] == "crm_tenant"
    assert result["error_code"] == "not_found"
    assert result["error_message"] == "No contact found, but business timezone resolved."
    assert len(llm_client.calls) == 1


@pytest.mark.asyncio
async def test_contact_context_resolver_marks_tool_not_called_when_mcp_returns_other_tools():
    backend = RecordingBackendClient(cache=None)
    tool_trace = LLMToolTrace.model_validate(
        {
            "type": "mcp_list_tools",
            "server_label": "tenant_main_mcp",
            "output": {
                "tools": ["contact_context"],
            },
            "raw": {
                "type": "mcp_list_tools",
                "server_label": "tenant_main_mcp",
                "output": {
                    "tools": ["contact_context"],
                },
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[tool_trace],
        usage=LLMUsage(provider="openai", model="gpt-4.1-mini", input_tokens=10, output_tokens=2, total_tokens=12),
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(CONTACT_CONTEXT_CACHE_TTL_MINUTES=120))
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is False
    assert result["source"] == "none"
    assert result["error_code"] == "tool_not_called"
    assert result["error_message"] == "contact_context tool was not called."
    assert result["mcp_called"] is True
    assert result["tool_called"] is False
    assert len(llm_client.calls) == 1


@pytest.mark.asyncio
async def test_contact_context_resolver_marks_empty_tool_output_when_contact_context_returns_no_payload():
    backend = RecordingBackendClient(cache=None)
    tool_trace = LLMToolTrace.model_validate(
        {
            "type": "mcp_call",
            "server_label": "tenant_main_mcp",
            "tool_name": "contact_context",
            "output": {},
            "raw": {
                "type": "mcp_call",
                "server_label": "tenant_main_mcp",
                "tool_name": "contact_context",
                "output": {},
            },
        }
    )
    llm_result = LLMResponseResult(
        provider="openai",
        model="gpt-4.1-mini",
        content="{}",
        tool_traces=[tool_trace],
        usage=LLMUsage(provider="openai", model="gpt-4.1-mini", input_tokens=10, output_tokens=2, total_tokens=12),
    )
    llm_client = RecordingLLMClient(llm_result)
    resolver = ContactContextResolver(backend, llm_client, Settings(CONTACT_CONTEXT_CACHE_TTL_MINUTES=120))
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is False
    assert result["source"] == "mcp"
    assert result["error_code"] == "empty_tool_output"
    assert result["error_message"] == "contact_context tool returned no usable payload."
    assert result["mcp_called"] is True
    assert result["tool_called"] is True
    assert len(llm_client.calls) == 1


@pytest.mark.asyncio
async def test_contact_context_resolver_marks_mcp_error_on_exception():
    backend = RecordingBackendClient(cache=None)

    class RaisingLLMClient(RecordingLLMClient):
        async def generate_with_mcp(self, *args, **kwargs):  # type: ignore[override]
            raise RuntimeError("boom")

    llm_client = RaisingLLMClient(
        LLMResponseResult(
            provider="openai",
            model="gpt-4.1-mini",
            content="{}",
            tool_traces=[],
        )
    )
    resolver = ContactContextResolver(backend, llm_client, Settings(CONTACT_CONTEXT_CACHE_TTL_MINUTES=120))
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999", email="ana@example.com"),
        conversation={"external_id": "external-conversation-1"},
    )
    backend_context = build_backend_context()
    mcp_config = McpRemoteConfig(
        enabled=True,
        server_label="tenant_main_mcp",
        server_url="https://mcp.example.test",
        allowed_tools=["appointment_availability"],
        require_approval="never",
    )

    result = await resolver.resolve(payload, backend_context, mcp_config)

    assert result is not None
    assert result["available"] is False
    assert result["source"] == "mcp_error"
    assert result["error_code"] == "mcp_error"
    assert result["error_message"] == "MCP refresh failed: RuntimeError"
    assert result["mcp_called"] is True
    assert result["tool_called"] is False
