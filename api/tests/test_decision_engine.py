import pytest

from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendPlaybook, BackendProduct, BackendTenant, CommercialContext
from app.services.decision_engine import DecisionEngine
from app.services.crm_client import CRMContact, CRMContactContext, CRMInteractionFlags, CRMLead
from app.services.routing_resolver import RoutingContext


class FakeBackendClient:
    def __init__(self, context: CommercialContext | None) -> None:
        self.context = context
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def fetch_tenant_context(
        self,
        tenant_id: str,
        selected_product_id: str | None = None,
        selected_playbook_id: str | None = None,
    ) -> CommercialContext | None:
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id)))
        return self.context


class FakeCRMClient:
    def __init__(self, context: CRMContactContext | None) -> None:
        self.context = context
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def fetch_contact_context(self, phone: str) -> CRMContactContext | None:
        self.calls.append(("fetch_contact_context", (phone,)))
        return self.context


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


def build_crm_context() -> CRMContactContext:
    return CRMContactContext.model_validate(
        {
            "contact": {
                "phone": "+34999999999",
                "name": "Ana García",
                "email": "ana@example.com",
            },
            "lead": {
                "id": "lead-1",
                "status": "qualified",
                "stage": "proposal",
                "ownerName": "Carlos",
                "score": 82,
                "source": "whatsapp",
                "isQualified": True,
                "lastInteractionAt": "2026-04-28T11:30:00+00:00",
                "lastTouchSummary": "Pidió información de precios.",
            },
            "opportunity": {
                "id": "opp-1",
                "pipeline": "default",
                "stage": "proposal",
                "nextAction": "schedule_demo",
                "amount": 1200,
            },
            "flags": {
                "alreadyContacted": True,
                "askedForPrice": True,
                "askedForDemo": False,
                "needsHuman": False,
            },
            "recentNotes": ["Le interesa automatizar WhatsApp."],
            "lastActivityAt": "2026-04-28T11:30:00+00:00",
            "summary": "Lead cualificado y en propuesta.",
        }
    )


@pytest.mark.asyncio
async def test_decision_engine_greeting_uses_context():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola, quiero automatizar WhatsApp",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context()), FakeCRMClient(None)).decide(payload)

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

    response = await DecisionEngine(FakeBackendClient(build_backend_context()), FakeCRMClient(build_crm_context())).decide(
        payload,
        routing=routing,
        backend_context=build_backend_context(),
        crm_context=build_crm_context(),
    )

    assert response.data_to_save["tenant_id"] == "tenant-1"
    assert response.data_to_save["entry_point_id"] == "entrypoint-1"
    assert response.data_to_save["entry_point_code"] == "crm-demo"
    assert response.data_to_save["entry_point_utm_id"] == "utm-1"
    assert response.data_to_save["crm_branch_ref"] == "branch-42"
    assert response.data_to_save["utm_source"] == "google"
    assert response.data_to_save["gclid"] == "gclid-1"
    assert response.data_to_save["conversation_id"] == "conversation-1"
    assert response.data_to_save["crm_contact_phone"] == "+34999999999"
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
    response = await DecisionEngine(FakeBackendClient(context), FakeCRMClient(None)).decide(
        payload,
        backend_context=context,
        crm_context=None,
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
    response = await DecisionEngine(FakeBackendClient(context), FakeCRMClient(None)).decide(
        payload,
        backend_context=context,
        crm_context=None,
    )

    assert response.intent == "greeting"
    assert "Website Widgets" not in response.reply


@pytest.mark.asyncio
async def test_decision_engine_handoff_uses_context():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero hablar con una persona",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context()), FakeCRMClient(build_crm_context())).decide(payload)

    assert response.intent == "handoff"
    assert response.action == "handoff_to_human"
    assert response.needs_human is True
    assert response.data_to_save["playbook_id"] == "playbook-1"
