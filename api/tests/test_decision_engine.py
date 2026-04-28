from app.schemas.agent import AgentRequest, Contact
from app.services.decision_engine import DecisionEngine


def test_decision_engine_greeting():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Hola, quiero automatizar WhatsApp",
        contact=Contact(phone="+34999999999"),
    )

    response = DecisionEngine().decide(payload)

    assert response.intent == "greeting"
    assert response.action == "greet"
    assert response.needs_human is False


def test_decision_engine_pricing():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Necesito precio y presupuestos",
        contact=Contact(phone="+34999999999"),
    )

    response = DecisionEngine().decide(payload)

    assert response.intent == "qualification"
    assert response.action == "ask_question"
    assert response.data_to_save["topic"] == "pricing"


def test_decision_engine_handoff():
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Quiero hablar con una persona",
        contact=Contact(phone="+34999999999"),
    )

    response = DecisionEngine().decide(payload)

    assert response.intent == "handoff"
    assert response.action == "handoff_to_human"
    assert response.needs_human is True
