from __future__ import annotations

import json
import os

import httpx
from fastapi.testclient import TestClient

from app.api.agent import get_backend_client
from app.config import get_settings
from app.main import create_app
from app.schemas.llm import LLMResponseResult, LLMUsage, McpRemoteConfig
from app.services.backend_client import (
    BackendAiUsagePolicy,
    BackendAiUsageSnapshot,
    BackendEntryPoint,
    BackendPlaybook,
    BackendProduct,
    BackendSalesRuntime,
    BackendTenant,
    CommercialContext,
)
from app.services.llm_client import LLMClient
from app.services.llm_context_helper import LLMContextHelper


class FakeBackendClient:
    def __init__(self, context: CommercialContext) -> None:
        self.context = context
        self.upsert_payloads: list[dict[str, object]] = []
        self.message_payloads: list[dict[str, object]] = []
        self.ai_usage_event_payloads: list[dict[str, object]] = []

    async def fetch_tenant_context(self, tenant_id: str, *args):
        return self.context

    async def fetch_mcp_config(self, tenant_id: str) -> McpRemoteConfig:
        return McpRemoteConfig(enabled=False)

    async def fetch_ai_usage_policy(self, tenant_id: str):
        return BackendAiUsagePolicy(tenant_id=tenant_id, exists=False, ai_enabled=True)

    async def fetch_ai_usage_snapshot(self, tenant_id: str):
        return BackendAiUsageSnapshot(tenant_id=tenant_id)

    async def upsert_conversation(self, payload):
        self.upsert_payloads.append(payload.model_dump(by_alias=True))
        return {"created": True, "conversation": {"id": "conversation-1"}}

    async def create_conversation_message(self, payload):
        payload_data = payload.model_dump(by_alias=True)
        self.message_payloads.append(payload_data)
        return type(
            "BackendConversationMessageResultStub",
            (),
            {
                "created": True,
                "duplicate": False,
                "message": type("BackendConversationMessageStub", (), {"id": "message-1"})(),
            },
        )()

    async def create_ai_usage_event(self, payload):
        self.ai_usage_event_payloads.append(payload.model_dump(by_alias=True))
        return type(
            "BackendAiUsageEventResultStub",
            (),
            {
                "created": True,
                "event": {"id": "usage-event-1"},
            },
        )()


def build_context() -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto comercial muy largo " + ("largo " * 120),
            "tone": "cercano",
            "salesPolicy": {
                "positioning": "Posicionamiento estable",
                "pricingNotes": "Notas de pricing " + ("muy largas " * 40),
            },
            "isActive": True,
        }
    )
    product = BackendProduct.model_validate(
        {
            "id": "product-1",
            "tenantId": "tenant-1",
            "name": "CRM Automation",
            "slug": "crm-automation",
            "description": "Descripcion comercial " + ("larga " * 120),
            "valueProposition": "Propuesta",
            "salesPolicy": {"handoffRules": "Derivar cuando haga falta"},
            "isActive": True,
        }
    )
    playbook = BackendPlaybook.model_validate(
        {
            "id": "playbook-1",
            "tenantId": "tenant-1",
            "productId": "product-1",
            "name": "Guia Demo",
            "config": {
                "qualificationQuestions": ["¿Qué negocio tienes?"],
                "notes": "Notas del playbook " + ("largas " * 120),
            },
            "isActive": True,
        }
    )
    entry_point = BackendEntryPoint.model_validate(
        {
            "id": "entrypoint-1",
            "code": "demo",
            "name": "Entrada Demo",
            "description": "Entrada comercial",
            "initial_message": "Hola",
            "crm_branch_ref": "branch-1",
            "is_active": True,
        }
    )

    return CommercialContext(
        tenant=tenant,
        products=[],
        product_selection={
            "selection_source": "explicit_product_id",
            "candidate_count": 1,
            "needs_service_clarification": False,
            "fallback_to_mcp_allowed": False,
            "reason": "explicit product requested",
        },
        playbooks=[playbook],
        entry_point=entry_point,
        sales_runtime=BackendSalesRuntime(),
        selected_product=product,
        selected_playbook=playbook,
    )


def configure_environment() -> None:
    os.environ["SALES_AGENT_BEARER_TOKEN"] = "test-internal-token"
    os.environ["BACKEND_BASE_URL"] = ""
    os.environ["NEW_LLM_ORCHESTRATION_ENABLED"] = "false"
    os.environ["NEW_LLM_ORCHESTRATION_CATALOG_EXECUTION_ENABLED"] = "false"
    os.environ["NEW_LLM_ORCHESTRATION_APPOINTMENT_AVAILABILITY_ENABLED"] = "false"
    os.environ["NEW_LLM_ORCHESTRATION_SLOT_SELECTION_ENABLED"] = "false"
    get_settings.cache_clear()


def test_agent_respond_persists_prompt_limit_and_llm_telemetry(monkeypatch):
    configure_environment()

    context = build_context()
    fake_backend = FakeBackendClient(context)

    prompts: list[dict[str, object]] = []
    call_count = {"value": 0}

    async def fake_resolve_configuration(self):
        return {
            "llm_default_profile": "openai",
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        }

    async def fake_generate(self, provider, system_prompt, user_prompt, configuration=None):
        call_count["value"] += 1
        parsed_prompt = json.loads(user_prompt)
        prompts.append(parsed_prompt)

        if call_count["value"] == 1:
            usage = LLMUsage(
                provider="openai",
                model="gpt-4.1-mini",
                input_tokens=120,
                output_tokens=32,
                cached_tokens=40,
                total_tokens=152,
            )
            estimated_cost = 0.000123
            content = {
                "reply": "Tu próxima cita es mañana a las 10:00.",
                "intent": "agenda",
                "score": 0.93,
                "action": "answer_question",
                "needs_human": False,
                "data_to_save": {"topic": "agenda"},
            }
            return LLMResponseResult(
                provider="openai",
                model="gpt-4.1-mini",
                content=json.dumps(content),
                response_id="resp_123",
                usage=usage,
                estimated_cost=estimated_cost,
            )

        content = {
            "reply": "Perfecto, ¿qué tipo de negocio tienes?",
            "intent": "qualification",
            "score": 0.81,
            "action": "ask_question",
            "needs_human": False,
            "data_to_save": {"topic": "pricing"},
        }
        return LLMResponseResult(
            provider="openai",
            model="gpt-4.1-mini",
            content=json.dumps(content),
            response_id="resp_456",
            usage=LLMUsage(provider="openai", model="gpt-4.1-mini"),
            estimated_cost=None,
        )

    monkeypatch.setattr(LLMClient, "resolve_configuration", fake_resolve_configuration)
    monkeypatch.setattr(LLMClient, "generate", fake_generate)
    app = create_app()
    app.dependency_overrides[get_backend_client] = lambda: fake_backend

    client = TestClient(app)

    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Necesito información comercial",
            "contact": {"phone": "+34999999999", "name": "Ana"},
            "conversation": {
                "summary": "Lead ya cualificado en llamada anterior.",
                "last_messages": [f"mensaje {index}" for index in range(10)],
            },
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["data_to_save"]["provider"] == "openai"
    assert body["data_to_save"]["model"] == "gpt-4.1-mini"
    assert body["data_to_save"]["response_id"] == "resp_123"
    assert body["data_to_save"]["input_tokens"] == 120
    assert body["data_to_save"]["output_tokens"] == 32
    assert body["data_to_save"]["cached_tokens"] == 40
    assert body["data_to_save"]["total_tokens"] == 152
    assert body["data_to_save"]["latency_ms"] >= 0
    assert body["data_to_save"]["estimated_cost"] == 0.000123
    assert body["data_to_save"]["llm_usage"]["input_tokens"] == 120
    assert body["data_to_save"]["llm_usage"]["cached_tokens"] == 40

    prompt = prompts[0]
    assert list(prompt.keys())[-1] == "current_message"
    assert prompt["temporal_context"]["current_date"] == "2026-06-15"
    assert "tenant" in prompt
    assert "product_selection" in prompt
    assert prompt["conversation"]["summary"] == "Lead ya cualificado en llamada anterior."
    assert len(prompt["conversation"]["last_messages"]) == LLMContextHelper.MAX_CONVERSATION_MESSAGES
    assert prompt["current_message"] == "Necesito información comercial"

    assert len(fake_backend.upsert_payloads) == 1
    assert len(fake_backend.message_payloads) == 2
    outbound_metadata = fake_backend.message_payloads[1]["metadata"]
    assert outbound_metadata["provider"] == "openai"
    assert outbound_metadata["model"] == "gpt-4.1-mini"
    assert outbound_metadata["response_id"] == "resp_123"
    assert outbound_metadata["input_tokens"] == 120
    assert outbound_metadata["output_tokens"] == 32
    assert outbound_metadata["cached_tokens"] == 40
    assert outbound_metadata["total_tokens"] == 152
    assert outbound_metadata["estimated_cost"] == 0.000123
    assert len(fake_backend.ai_usage_event_payloads) == 1
    assert fake_backend.ai_usage_event_payloads[0]["provider"] == "openai"
    assert fake_backend.ai_usage_event_payloads[0]["response_id"] == "resp_123"
    assert fake_backend.ai_usage_event_payloads[0]["usage_type"] == "llm_chat"

    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Hola",
            "contact": {"phone": "+34999999999"},
            "conversation": {
                "last_messages": ["Hola", "¿Qué tal?"],
            },
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["data_to_save"]["provider"] == "openai"
    assert body["data_to_save"]["model"] == "gpt-4.1-mini"
    assert body["data_to_save"]["response_id"] == "resp_456"
    assert body["data_to_save"]["input_tokens"] is None
    assert body["data_to_save"]["output_tokens"] is None
    assert body["data_to_save"]["cached_tokens"] is None
    assert body["data_to_save"]["total_tokens"] is None
    assert body["data_to_save"]["estimated_cost"] is None
    assert body["data_to_save"]["latency_ms"] >= 0

    prompt = prompts[1]
    assert prompt["conversation"]["summary"] is None
    assert prompt["temporal_context"]["current_date"] == "2026-06-15"
    assert "operational_context" in prompt
    assert "tenant" in prompt
    assert prompt["conversation"]["last_messages"] == ["Hola", "¿Qué tal?"]
    assert prompt["current_message"] == "Hola"

    assert len(fake_backend.message_payloads) == 4
    outbound_metadata = fake_backend.message_payloads[3]["metadata"]
    assert outbound_metadata["provider"] == "openai"
    assert outbound_metadata["model"] == "gpt-4.1-mini"
    assert outbound_metadata["response_id"] == "resp_456"
    assert outbound_metadata["input_tokens"] is None
    assert outbound_metadata["output_tokens"] is None
    assert outbound_metadata["cached_tokens"] is None
    assert outbound_metadata["total_tokens"] is None
    assert outbound_metadata["estimated_cost"] is None
    assert len(fake_backend.ai_usage_event_payloads) == 2
    assert fake_backend.ai_usage_event_payloads[1]["usage_type"] == "llm_chat"
