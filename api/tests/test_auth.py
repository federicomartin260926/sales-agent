import os

from fastapi.testclient import TestClient

from app.config import get_settings
from app.main import create_app


os.environ["SALES_AGENT_BEARER_TOKEN"] = "test-internal-token"
get_settings.cache_clear()

client = TestClient(create_app())


def test_agent_endpoint_requires_bearer_token():
    response = client.post(
        "/agent/respond",
        json={
            "tenant_id": "tenant-1",
            "message": "Hola",
            "contact": {"phone": "+34999999999"},
        },
    )

    assert response.status_code == 401
    assert response.json()["detail"] == "Missing bearer token"


def test_agent_endpoint_rejects_invalid_bearer_token():
    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer wrong-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Hola",
            "contact": {"phone": "+34999999999"},
        },
    )

    assert response.status_code == 403
    assert response.json()["detail"] == "Invalid bearer token"


def test_agent_endpoint_accepts_valid_bearer_token():
    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Hola",
            "contact": {"phone": "+34999999999"},
        },
    )

    assert response.status_code == 200
    assert response.json()["intent"] == "greeting"


def test_agent_endpoint_accepts_wa_gateway_payload_shape():
    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "default",
            "channel": "whatsapp",
            "contact": {
                "wa_id": "34600000000",
                "from": "34600000000",
                "name": "Cliente Demo",
            },
            "message": {
                "id": "wamid.test",
                "type": "text",
                "text": "Hola, quiero información",
                "timestamp": "1710000000",
            },
            "conversation": {"last_messages": []},
            "raw_event": {"foo": "bar"},
        },
    )

    assert response.status_code == 200
    assert response.json()["intent"] == "greeting"


def test_agent_endpoint_accepts_string_message_payload():
    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Necesito presupuesto",
            "contact": {"phone": "+34999999999"},
        },
    )

    assert response.status_code == 200
    assert response.json()["intent"] == "qualification"


def test_agent_endpoint_rejects_missing_contact_phone():
    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Hola",
            "contact": {"name": "Cliente Demo"},
        },
    )

    assert response.status_code == 422
