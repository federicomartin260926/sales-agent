import pytest
import httpx

from app.config import Settings
from app.services.crm_client import CRMClient


def crm_transport_handler(request: httpx.Request) -> httpx.Response:
    if request.method != "GET" or request.url.path != "/api/agent/contact-context":
        return httpx.Response(404, json={"detail": "not found"})

    if request.url.params.get("phone") != "+34999999999":
        return httpx.Response(404, json={"detail": "not found"})

    return httpx.Response(
        200,
        json={
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
        },
    )


@pytest.mark.asyncio
async def test_crm_client_loads_contact_context():
    client = CRMClient(
        Settings(CRM_BASE_URL="http://crm.example"),
        transport=httpx.MockTransport(crm_transport_handler),
    )

    context = await client.fetch_contact_context("+34999999999")

    assert context is not None
    assert context.contact.name == "Ana García"
    assert context.lead is not None
    assert context.lead.stage == "proposal"
    assert context.opportunity is not None
    assert context.opportunity.next_action == "schedule_demo"
    assert context.flags.asked_for_price is True
    assert context.summary == "Lead cualificado y en propuesta."


@pytest.mark.asyncio
async def test_crm_client_returns_none_for_missing_contact():
    client = CRMClient(
        Settings(CRM_BASE_URL="http://crm.example"),
        transport=httpx.MockTransport(lambda request: httpx.Response(404, json={"detail": "not found"})),
    )

    context = await client.fetch_contact_context("+34999999999")

    assert context is None


def test_crm_update_payload_shape():
    client = CRMClient(Settings(CRM_BASE_URL="http://crm.example"))

    payload = client.build_update_payload(
        phone="+34999999999",
        tenant_id="tenant-1",
        intent="qualification",
        score=0.82,
        action="ask_question",
        needs_human=False,
        summary="Lead cualificado y en propuesta.",
        reply="Perfecto, ¿qué tipo de negocio tienes?",
        data_to_save={"topic": "pricing"},
    )

    assert payload.phone == "+34999999999"
    assert payload.tenant_id == "tenant-1"
    assert payload.needs_human is False
    assert payload.data_to_save["topic"] == "pricing"
