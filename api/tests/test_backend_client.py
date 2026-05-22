import json

import httpx
import pytest

from app.config import Settings
from app.services.backend_client import BackendAiUsageEventPayload, BackendClient, BackendConversationUpsertPayload


def transport_handler(request: httpx.Request) -> httpx.Response:
    if request.method == "GET" and request.url.path == "/api/internal/commercial-context":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        assert request.url.params.get("tenant_id") == "tenant-1"
        return httpx.Response(
            200,
            json={
                "tenant": {
                    "id": "tenant-1",
                    "name": "Negocio Demo",
                    "slug": "negocio-demo",
                    "businessContext": "Contexto comercial",
                    "tone": "consultivo",
                    "salesPolicy": {},
                    "isActive": True,
                    "createdAt": "2026-04-28T12:00:00+00:00",
                },
                "product": {
                    "id": "product-1",
                    "tenantId": "tenant-1",
                    "name": "WhatsApp Automation",
                    "slug": "whatsapp-automation",
                    "externalSource": "crm",
                    "externalReference": "pack-starter",
                    "description": "Automatización de conversaciones.",
                    "valueProposition": "Atiende leads 24/7 con reglas comerciales.",
                    "basePriceCents": 150000,
                    "currency": "EUR",
                    "salesPolicy": {},
                    "isActive": True,
                },
                "playbook": {
                    "id": "playbook-1",
                    "tenantId": "tenant-1",
                    "productId": "product-1",
                    "name": "Guía comercial WhatsApp",
                    "config": {},
                    "isActive": True,
                },
            },
        )

    if request.method == "GET" and request.url.path == "/api/tenants/tenant-1":
        return httpx.Response(
            200,
            json={
                "id": "tenant-1",
                "name": "Negocio Demo",
                "slug": "negocio-demo",
                "businessContext": "Contexto comercial",
                "tone": "consultivo",
                "salesPolicy": {},
                "isActive": True,
                "createdAt": "2026-04-28T12:00:00+00:00",
            },
        )

    if request.method == "GET" and request.url.path == "/api/products":
        return httpx.Response(
            200,
            json=[
                {
                    "id": "product-1",
                    "tenantId": "tenant-1",
                    "name": "WhatsApp Automation",
                    "slug": "whatsapp-automation",
                    "externalSource": "crm",
                    "externalReference": "pack-starter",
                    "description": "Automatización de conversaciones.",
                    "valueProposition": "Atiende leads 24/7 con reglas comerciales.",
                    "basePriceCents": 150000,
                    "currency": "EUR",
                    "salesPolicy": {},
                    "isActive": True,
                }
            ],
        )

    if request.method == "GET" and request.url.path == "/api/playbooks":
        return httpx.Response(200, json=[])

    if request.method == "GET" and request.url.path == "/api/internal/routing/entrypoint-ref/abc123":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "WhatsApp Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            },
        )

    if request.method == "GET" and request.url.path == "/api/internal/routing/whatsapp-phone/phone-number-id-1":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(200, json={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"})

    if request.method == "POST" and request.url.path == "/api/internal/conversations/upsert":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "created": True,
                "conversation": {"id": "conversation-1"},
            },
        )

    if request.method == "GET" and request.url.path == "/api/internal/ai-usage/tenant-1/policy":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "tenant_id": "tenant-1",
                "exists": True,
                "ai_enabled": True,
                "monthly_cost_limit_eur": 10.0,
                "daily_cost_limit_eur": 1.0,
                "default_model": "gpt-4.1-mini",
                "fallback_model": "gpt-4.1-nano",
                "limit_action": "handoff_human",
                "created_at": "2026-04-28T12:00:00+00:00",
                "updated_at": "2026-04-28T12:00:00+00:00",
            },
        )

    if request.method == "GET" and request.url.path == "/api/internal/ai-usage/tenant-1/usage":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "tenant_id": "tenant-1",
                "daily": {
                    "estimated_cost_eur": 0.25,
                    "input_tokens": 100,
                    "output_tokens": 50,
                    "cached_tokens": 10,
                    "total_tokens": 160,
                },
                "monthly": {
                    "estimated_cost_eur": 2.5,
                    "input_tokens": 1000,
                    "output_tokens": 500,
                    "cached_tokens": 100,
                    "total_tokens": 1600,
                },
            },
        )

    if request.method == "POST" and request.url.path == "/api/internal/ai-usage/events":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        payload = json.loads(request.content.decode("utf-8"))
        assert payload["tenant_id"] == "tenant-1"
        assert payload["provider"] == "openai"
        return httpx.Response(201, json={"created": True, "event": {"id": "event-1"}})

    if request.method == "GET" and request.url.path == "/api/internal/mcp/tenant-1/config":
        assert request.headers.get("Authorization") == "Bearer test-internal-token"
        return httpx.Response(
            200,
            json={
                "enabled": True,
                "server_label": "tenant_main_mcp",
                "server_url": "https://mcp.example.test",
                "auth_type": "bearer",
                "bearer_token": "mcp-token",
                "allowed_tools": ["search_properties"],
                "require_approval": "never",
                "timeout_seconds": 15,
                "config": {},
            },
        )

    return httpx.Response(404, json={"detail": "not found"})


@pytest.mark.asyncio
async def test_backend_client_loads_tenant_context():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    context = await client.fetch_tenant_context("tenant-1")

    assert context is not None
    assert context.tenant.name == "Negocio Demo"
    assert len(context.products) == 1
    assert context.selected_product is not None
    assert context.selected_product.name == "WhatsApp Automation"
    assert context.selected_product.slug == "whatsapp-automation"
    assert context.selected_product.external_reference == "pack-starter"
    assert context.context_summary() == "Negocio Demo · WhatsApp Automation · Guía comercial WhatsApp · Entrada Demo"


@pytest.mark.asyncio
async def test_backend_client_resolves_entrypoint_ref():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    context = await client.resolve_entrypoint_ref("abc123")

    assert context is not None
    assert context.entry_point_id == "entrypoint-1"
    assert context.entry_point_utm_id == "utm-1"
    assert context.product_id == "product-1"


@pytest.mark.asyncio
async def test_backend_client_resolves_whatsapp_phone():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    context = await client.resolve_whatsapp_phone("phone-number-id-1")

    assert context is not None
    assert context["tenant_id"] == "tenant-1"


@pytest.mark.asyncio
async def test_backend_client_returns_none_for_missing_tenant():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(lambda request: httpx.Response(404, json={"detail": "not found"})),
    )

    context = await client.fetch_tenant_context("missing")

    assert context is None


@pytest.mark.asyncio
async def test_backend_client_upserts_conversation_with_internal_bearer():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    result = await client.upsert_conversation(
        BackendConversationUpsertPayload(
            tenant_id="tenant-1",
            customer_phone="+34999999999",
            first_message="Hola",
        )
    )

    assert result is not None
    assert result["created"] is True


@pytest.mark.asyncio
async def test_backend_client_fetches_mcp_config():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    config = await client.fetch_mcp_config("tenant-1")

    assert config.enabled is True
    assert config.server_label == "tenant_main_mcp"
    assert config.server_url == "https://mcp.example.test"
    assert config.auth_type == "bearer"
    assert config.bearer_token == "mcp-token"
    assert config.allowed_tools == ["search_properties"]


@pytest.mark.asyncio
async def test_backend_client_fetches_ai_usage_policy_usage_and_reports_event():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(transport_handler),
    )

    policy = await client.fetch_ai_usage_policy("tenant-1")
    snapshot = await client.fetch_ai_usage_snapshot("tenant-1")
    result = await client.create_ai_usage_event(
        BackendAiUsageEventPayload(
            tenant_id="tenant-1",
            provider="openai",
            model="gpt-4.1-mini",
            response_id="resp_1",
            input_tokens=120,
            output_tokens=32,
            cached_tokens=40,
            total_tokens=152,
            estimated_cost=0.000123,
            latency_ms=200,
        )
    )

    assert policy is not None
    assert policy.ai_enabled is True
    assert policy.daily_cost_limit_eur == 1.0
    assert snapshot is not None
    assert snapshot.daily.estimated_cost_eur == 0.25
    assert result is not None
    assert result.created is True


@pytest.mark.asyncio
async def test_backend_client_fetches_disabled_mcp_config_when_missing():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx", SALES_AGENT_BEARER_TOKEN="test-internal-token"),
        transport=httpx.MockTransport(lambda request: httpx.Response(404, json={"detail": "not found"})),
    )

    config = await client.fetch_mcp_config("tenant-1")

    assert config.enabled is False
    assert config.error_code == "not_configured"
