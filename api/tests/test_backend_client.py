import pytest
import httpx

from app.config import Settings
from app.services.backend_client import BackendClient


def transport_handler(request: httpx.Request) -> httpx.Response:
    if request.method == "GET" and request.url.path == "/backend/api/tenants/tenant-1":
        return httpx.Response(
            200,
            json={
                "id": "tenant-1",
                "name": "Negocio Demo",
                "slug": "negocio-demo",
                "businessContext": "Contexto comercial",
                "tone": "consultivo",
                "salesPolicy": {
                    "positioning": "Responder con claridad y foco comercial.",
                    "qualificationFocus": "Identificar volumen, canal y urgencia.",
                    "handoffRules": "Derivar a humano si piden seguimiento manual.",
                },
                "isActive": True,
                "createdAt": "2026-04-28T12:00:00+00:00",
            },
        )

    if request.method == "GET" and request.url.path == "/backend/api/products":
        return httpx.Response(
            200,
            json=[
                {
                    "id": "product-1",
                    "tenantId": "tenant-1",
                    "name": "WhatsApp Automation",
                    "description": "Automatización de conversaciones.",
                    "valueProposition": "Atiende leads 24/7 con reglas comerciales.",
                    "salesPolicy": {
                        "positioning": "Automatización comercial.",
                    },
                    "isActive": True,
                },
                {
                    "id": "product-2",
                    "tenantId": "tenant-1",
                    "name": "Inactive",
                    "description": "",
                    "valueProposition": "",
                    "salesPolicy": {},
                    "isActive": False,
                },
            ],
        )

    if request.method == "GET" and request.url.path == "/backend/api/playbooks":
        return httpx.Response(
            200,
            json=[
                {
                    "id": "playbook-1",
                    "tenantId": "tenant-1",
                    "productId": "product-1",
                    "name": "Guía comercial WhatsApp",
                    "config": {
                        "objective": "Calificar leads entrantes.",
                        "qualificationQuestions": ["¿Qué negocio tienes?"],
                        "scoring": {
                            "maxScore": 10,
                            "handoffThreshold": 7,
                            "positiveSignals": ["Tiene volumen"],
                            "negativeSignals": ["No decide"],
                        },
                        "handoffRules": ["Derivar si piden humano."],
                        "allowedActions": ["askQuestion", "handoffToHuman"],
                    },
                    "isActive": True,
                }
            ],
        )

    return httpx.Response(404, json={"detail": "not found"})


@pytest.mark.asyncio
async def test_backend_client_loads_tenant_context():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx/backend"),
        transport=httpx.MockTransport(transport_handler),
    )

    context = await client.fetch_tenant_context("tenant-1")

    assert context is not None
    assert context.tenant.name == "Negocio Demo"
    assert len(context.products) == 1
    assert context.selected_product is not None
    assert context.selected_product.name == "WhatsApp Automation"
    assert context.selected_playbook is not None
    assert context.selected_playbook.name == "Guía comercial WhatsApp"


@pytest.mark.asyncio
async def test_backend_client_returns_none_for_missing_tenant():
    client = BackendClient(
        Settings(BACKEND_BASE_URL="http://sales-agent-nginx/backend"),
        transport=httpx.MockTransport(lambda request: httpx.Response(404, json={"detail": "not found"})),
    )

    context = await client.fetch_tenant_context("missing")

    assert context is None
