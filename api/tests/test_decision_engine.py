import pytest

from app.config import Settings
from app.schemas.agent import AgentRequest, AgentResponse, Contact
from app.services.backend_client import BackendPlaybook, BackendProduct, BackendTenant, CommercialContext
from app.schemas.llm import McpRemoteConfig
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext
from app.services.llm_client import LLMClient
from app.services.llm_decision_service import LLMDecisionService


class FakeBackendClient:
    def __init__(self, context: CommercialContext | None) -> None:
        self.context = context
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def fetch_tenant_context(
        self,
        tenant_id: str,
        selected_product_id: str | None = None,
        selected_playbook_id: str | None = None,
        *args: object,
    ) -> CommercialContext | None:
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id, *args)))
        return self.context

    async def fetch_mcp_config(self, tenant_id: str) -> McpRemoteConfig:
        self.calls.append(("fetch_mcp_config", (tenant_id,)))
        return McpRemoteConfig(enabled=False)


@pytest.fixture(autouse=True)
def force_heuristic_llm(monkeypatch: pytest.MonkeyPatch) -> None:
    async def _skip_llm(*args, **kwargs):
        return None

    monkeypatch.setattr(
        LLMDecisionService,
        "propose",
        _skip_llm,
    )


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
    product = BackendProduct.model_validate(
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
    )
    playbook = BackendPlaybook.model_validate(
        {
            "id": "playbook-1",
            "tenantId": "tenant-1",
            "productId": "product-1",
            "name": "Guía comercial WhatsApp",
            "config": {
                "qualificationQuestions": ["¿Qué negocio tienes?"],
            },
            "isActive": True,
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[product],
        playbooks=[playbook],
        selected_product=product,
        selected_playbook=playbook,
    )


def build_multi_product_context(selected_product: BackendProduct | None = None) -> CommercialContext:
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
    product_a = BackendProduct.model_validate(
        {
            "id": "product-a",
            "tenantId": "tenant-1",
            "name": "WhatsApp Automation",
            "slug": "whatsapp-automation",
            "externalSource": "crm",
            "externalReference": "pack-starter",
            "description": "Automatización de WhatsApp.",
            "valueProposition": "Atiende leads 24/7 con reglas comerciales.",
            "basePriceCents": 150000,
            "currency": "EUR",
            "salesPolicy": {},
            "isActive": True,
        }
    )
    product_b = BackendProduct.model_validate(
        {
            "id": "product-b",
            "tenantId": "tenant-1",
            "name": "Website Widgets",
            "slug": "website-widgets",
            "description": "Widgets de captación.",
            "valueProposition": "Captura tráfico web.",
            "salesPolicy": {},
            "isActive": True,
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[product_a, product_b],
        playbooks=[],
        selected_product=selected_product,
        selected_playbook=None,
    )


def build_mcp_fallback_context() -> CommercialContext:
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
    product = BackendProduct.model_validate(
        {
            "id": "product-1",
            "tenantId": "tenant-1",
            "name": "Integración de APIs y sistemas",
            "slug": "integracion-api-sistemas",
            "externalSource": "crm",
            "externalReference": "integration-basic",
            "description": "Integraciones genéricas para distintos ERP.",
            "valueProposition": "Conecta sistemas de forma flexible.",
            "basePriceCents": 150000,
            "currency": "EUR",
            "salesPolicy": {},
            "isActive": True,
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[product],
        playbooks=[],
        product_selection={
            "selection_source": "sa_search",
            "search_query_used": "holded factura scripts",
            "candidate_count": 1,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": True,
            "reason": "single weak local product candidate; MCP fallback available",
        },
        selected_product=None,
        selected_playbook=None,
    )


@pytest.mark.asyncio
async def test_decision_engine_greeting_uses_context():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola, quiero automatizar WhatsApp",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context())).decide(payload)

    assert response.intent == "greeting"
    assert response.action == "greet"
    assert response.needs_human is False
    assert "Negocio Demo" in response.reply


@pytest.mark.asyncio
async def test_decision_engine_uses_routing_attribution():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Necesito presupuesto",
        contact=Contact(phone="+34999999999"),
    )
    routing = RoutingContext(
        tenant_id="tenant-1",
        tenant_slug="negocio-demo",
        product_id="product-1",
        product_name="WhatsApp Automation",
        playbook_id="playbook-1",
        entry_point_id="entrypoint-1",
        entry_point_code="crm-demo",
        entry_point_utm_id="utm-1",
        entrypoint_ref="abc123",
        crm_branch_ref="branch-42",
        utm_source="google",
        utm_medium="cpc",
        utm_campaign="crm_pymes",
        utm_term="crm",
        utm_content="ad_01",
        gclid="gclid-1",
        fbclid="fbclid-1",
        conversation_id="conversation-1",
        status="matched",
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context())).decide(
        payload,
        routing=routing,
        backend_context=build_backend_context(),
    )

    assert response.data_to_save["tenant_id"] == "tenant-1"
    assert response.data_to_save["entry_point_id"] == "entrypoint-1"
    assert response.data_to_save["entry_point_code"] == "crm-demo"
    assert response.data_to_save["entry_point_utm_id"] == "utm-1"
    assert response.data_to_save["crm_branch_ref"] == "branch-42"
    assert response.data_to_save["utm_source"] == "google"
    assert response.data_to_save["gclid"] == "gclid-1"
    assert response.data_to_save["conversation_id"] == "conversation-1"
    assert response.data_to_save["product_slug"] == "whatsapp-automation"
    assert response.data_to_save["product_external_source"] == "crm"
    assert response.data_to_save["product_external_reference"] == "pack-starter"
    assert response.data_to_save["product_base_price_cents"] == 150000
    assert response.data_to_save["product_currency"] == "EUR"


@pytest.mark.asyncio
async def test_decision_engine_confirms_inferred_product_without_selected_product():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero automatizar WhatsApp",
        contact=Contact(phone="+34999999999"),
    )

    context = build_multi_product_context()
    response = await DecisionEngine(FakeBackendClient(context)).decide(
        payload,
        backend_context=context,
    )

    assert response.intent == "qualification"
    assert response.action == "ask_confirmation"
    assert "WhatsApp Automation" in response.reply


@pytest.mark.asyncio
async def test_decision_engine_does_not_pick_first_active_product_blindly():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    context = build_multi_product_context()
    response = await DecisionEngine(FakeBackendClient(context)).decide(
        payload,
        backend_context=context,
    )

    assert response.intent == "greeting"
    assert "Website Widgets" not in response.reply


@pytest.mark.asyncio
async def test_decision_engine_does_not_infer_generic_product_when_mcp_fallback_is_available():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Estoy interesado en un servicio de integración con Holded o FacturaScripts.",
        contact=Contact(phone="+34999999999"),
    )

    context = build_mcp_fallback_context()
    response = await DecisionEngine(FakeBackendClient(context)).decide(
        payload,
        backend_context=context,
        mcp_config=McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", allowed_tools=["services_search"]),
    )

    assert response.action != "ask_confirmation"
    assert "Integración de APIs y sistemas" not in response.reply
    assert response.data_to_save["product_selection_fallback_to_mcp_allowed"] is True
    assert response.data_to_save["product_selection_fallback_reason"] == "single weak local product candidate; MCP fallback available"
    assert response.data_to_save["mcp_services_search_available"] is True
    assert response.data_to_save["local_response_short_circuited"] is False


@pytest.mark.asyncio
async def test_decision_engine_forces_llm_when_catalog_is_empty_and_mcp_search_is_available(monkeypatch: pytest.MonkeyPatch):
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero ver opciones de servicio",
        contact=Contact(phone="+34999999999"),
    )

    context = CommercialContext(
        tenant=BackendTenant.model_validate(
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
        ),
        products=[],
        playbooks=[],
        product_selection={
            "selection_source": "none",
            "search_query_used": None,
            "candidate_count": 0,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": True,
            "reason": "no local catalog; MCP fallback available",
        },
        selected_product=None,
        selected_playbook=None,
    )

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, *args, **kwargs):
        seen["force_llm"] = kwargs.get("force_llm")
        seen["mcp_enabled"] = kwargs.get("mcp_config").enabled if kwargs.get("mcp_config") is not None else None
        return AgentResponse(
            reply="Respuesta desde LLM con MCP.",
            intent="open_question",
            score=0.9,
            action="answer_question",
            needs_human=False,
            data_to_save={"local_response_short_circuited": False, "mcp_services_search_available": True},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=123,
        )

    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)

    response = await DecisionEngine(FakeBackendClient(context)).decide(
        payload,
        backend_context=context,
        mcp_config=McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", allowed_tools=["services_search"]),
    )

    assert seen["force_llm"] is True
    assert seen["mcp_enabled"] is True
    assert response.provider == "openai"
    assert response.model == "gpt-4.1-mini"
    assert response.data_to_save["local_response_short_circuited"] is False
    assert response.data_to_save["mcp_services_search_available"] is True


@pytest.mark.asyncio
async def test_decision_engine_handoff_uses_context():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero hablar con una persona",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context())).decide(payload)

    assert response.intent == "handoff"
    assert response.action == "handoff_to_human"
    assert response.needs_human is True
    assert response.data_to_save["playbook_id"] == "playbook-1"
