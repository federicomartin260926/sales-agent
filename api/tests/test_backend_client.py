import httpx
import pytest

from app.config import Settings
from app.services.backend_client import BackendClient, BackendConversationUpsertPayload


def transport_handler(request: httpx.Request) -> httpx.Response:
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

    return httpx.Response(404, json={"detail": "not found"})


@pytest.mark.asyncio
async def test_backend_client_loads_tenant_context():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx"),
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


@pytest.mark.asyncio
async def test_backend_client_resolves_entrypoint_ref():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx"),
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
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx"),
        transport=httpx.MockTransport(transport_handler),
    )

    context = await client.resolve_whatsapp_phone("phone-number-id-1")

    assert context is not None
    assert context["tenant_id"] == "tenant-1"


@pytest.mark.asyncio
async def test_backend_client_returns_none_for_missing_tenant():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx"),
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
