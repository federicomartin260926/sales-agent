import pytest

from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendPlaybook, BackendProduct, BackendTenant, CommercialContext
from app.services.decision_engine import DecisionEngine
from app.services.crm_client import CRMContact, CRMContactContext, CRMInteractionFlags, CRMLead


class FakeBackendClient:
    def __init__(self, context: CommercialContext | None) -> None:
        self.context = context

    async def fetch_tenant_context(self, tenant_id: str) -> CommercialContext | None:
        return self.context


class FakeCRMClient:
    def __init__(self, context: CRMContactContext | None) -> None:
        self.context = context

    async def fetch_contact_context(self, phone: str) -> CRMContactContext | None:
        return self.context


def build_backend_context() -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {
                "positioning": "Responder con claridad y foco comercial.",
                "qualificationFocus": "Identificar volumen, canal y urgencia.",
                "handoffRules": "Derivar a humano si piden seguimiento manual.",
            },
            "isActive": True,
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    product = BackendProduct.model_validate(
        {
            "id": "product-1",
            "tenantId": "tenant-1",
            "name": "WhatsApp Automation",
            "description": "Automatización de conversaciones.",
            "valueProposition": "Atiende leads 24/7 con reglas comerciales.",
            "salesPolicy": {
                "positioning": "Automatización comercial.",
                "pricingNotes": "Plan mensual.",
            },
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
                "objective": "Calificar leads entrantes.",
                "qualificationQuestions": [
                    "¿Qué negocio tienes?",
                    "¿Cuántos leads gestionas al mes?",
                ],
                "scoring": {
                    "maxScore": 10,
                    "handoffThreshold": 7,
                    "positiveSignals": ["Tiene volumen"],
                    "negativeSignals": ["No decide"],
                },
                "agendaRules": ["Proponer agenda si hay interés."],
                "handoffRules": ["Derivar si piden humano."],
                "allowedActions": ["askQuestion", "handoffToHuman"],
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
async def test_decision_engine_pricing_uses_playbook_question():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Necesito precio y presupuestos",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context()), FakeCRMClient(None)).decide(payload)

    assert response.intent == "qualification"
    assert response.action == "ask_question"
    assert response.data_to_save["topic"] == "pricing"
    assert response.data_to_save["tenant_id"] == "tenant-1"
    assert "WhatsApp Automation" in response.reply
    assert "¿Qué negocio tienes?" in response.reply


@pytest.mark.asyncio
async def test_decision_engine_uses_crm_contact_name_and_stage():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Necesito presupuesto",
        contact=Contact(phone="+34999999999"),
    )

    response = await DecisionEngine(FakeBackendClient(build_backend_context()), FakeCRMClient(build_crm_context())).decide(payload)

    assert response.intent == "qualification"
    assert response.action == "ask_question"
    assert "Ana García" in response.reply
    assert response.data_to_save["crm_lead_stage"] == "proposal"
    assert response.data_to_save["crm_opportunity_stage"] == "proposal"


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
