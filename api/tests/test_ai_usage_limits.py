from __future__ import annotations

import json
import os

import pytest
from fastapi.testclient import TestClient

from app.api.agent import get_agent_runtime, get_backend_client
from app.config import get_settings
from app.main import create_app
from app.schemas.llm import BackendAiUsageWindow, McpRemoteConfig
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
from app.services.decision_engine import DecisionEngine
from app.services.llm_decision_service import LLMDecisionService
from app.services.llm_client import LLMClient
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime


class BlockedBackendClient:
    def __init__(
        self,
        context: CommercialContext,
        *,
        ai_enabled: bool = False,
        daily_cost_limit_eur: float | None = None,
        monthly_cost_limit_eur: float | None = None,
        daily_estimated_cost_eur: float = 0.0,
        monthly_estimated_cost_eur: float = 0.0,
    ) -> None:
        self.context = context
        self.ai_enabled = ai_enabled
        self.daily_cost_limit_eur = daily_cost_limit_eur
        self.monthly_cost_limit_eur = monthly_cost_limit_eur
        self.daily_estimated_cost_eur = daily_estimated_cost_eur
        self.monthly_estimated_cost_eur = monthly_estimated_cost_eur
        self.ai_usage_event_payloads: list[dict[str, object]] = []

    async def fetch_tenant_context(self, tenant_id: str, *args):
        return self.context

    async def fetch_mcp_config(self, tenant_id: str) -> McpRemoteConfig:
        return McpRemoteConfig(enabled=False)

    async def fetch_ai_usage_policy(self, tenant_id: str):
        return BackendAiUsagePolicy(
            tenant_id=tenant_id,
            exists=True,
            ai_enabled=self.ai_enabled,
            daily_cost_limit_eur=self.daily_cost_limit_eur,
            monthly_cost_limit_eur=self.monthly_cost_limit_eur,
        )

    async def fetch_ai_usage_snapshot(self, tenant_id: str):
        return BackendAiUsageSnapshot(
            tenant_id=tenant_id,
            daily=BackendAiUsageWindow(estimated_cost_eur=self.daily_estimated_cost_eur),
            monthly=BackendAiUsageWindow(estimated_cost_eur=self.monthly_estimated_cost_eur),
        )

    async def upsert_conversation(self, payload):
        return {"created": True, "conversation": {"id": "conversation-1"}}

    async def create_conversation_message(self, payload):
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
        return type("BackendAiUsageEventResultStub", (), {"created": True, "event": {"id": "usage-event-1"}})()


class FailingAudioGatewayClient:
    async def download_whatsapp_media(self, media_id: str):
        raise AssertionError("Audio download should not be attempted when AI is blocked")


class FailingAudioTranscriptionClient:
    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str):
        raise AssertionError("Audio transcription should not be attempted when AI is blocked")


def build_context() -> CommercialContext:
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Contexto comercial",
            "tone": "cercano",
            "salesPolicy": {},
            "isActive": True,
        }
    )
    product = BackendProduct.model_validate(
        {
            "id": "product-1",
            "tenantId": "tenant-1",
            "name": "CRM Automation",
            "slug": "crm-automation",
            "description": "Descripcion comercial",
            "valueProposition": "Propuesta",
            "salesPolicy": {},
            "isActive": True,
        }
    )
    playbook = BackendPlaybook.model_validate(
        {
            "id": "playbook-1",
            "tenantId": "tenant-1",
            "productId": "product-1",
            "name": "Guia Demo",
            "config": {},
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
        products=[product],
        playbooks=[playbook],
        entry_point=entry_point,
        sales_runtime=BackendSalesRuntime(),
        selected_product=product,
        selected_playbook=playbook,
    )


def configure_environment() -> None:
    os.environ["SALES_AGENT_BEARER_TOKEN"] = "test-internal-token"
    os.environ["BACKEND_BASE_URL"] = ""
    os.environ["CRM_BASE_URL"] = ""
    get_settings.cache_clear()


def test_agent_respond_blocks_llm_when_tenant_ai_is_disabled(monkeypatch):
    configure_environment()

    backend = BlockedBackendClient(build_context(), ai_enabled=False)

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when AI is disabled")

    monkeypatch.setattr(LLMClient, "resolve_configuration", fail_if_llm_is_called)
    monkeypatch.setattr(LLMClient, "generate", fail_if_llm_is_called)
    monkeypatch.setattr(LLMDecisionService, "propose", fail_if_llm_is_called)

    app = create_app()
    app.dependency_overrides[get_backend_client] = lambda: backend
    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),  # type: ignore[arg-type]
        DecisionEngine(backend),  # type: ignore[arg-type]
        audio_gateway_client=FailingAudioGatewayClient(),  # type: ignore[arg-type]
        audio_transcription_client=FailingAudioTranscriptionClient(),  # type: ignore[arg-type]
    )
    app.dependency_overrides[get_agent_runtime] = lambda: runtime
    client = TestClient(app)

    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Necesito información comercial",
            "contact": {"phone": "+34999999999", "name": "Ana"},
            "conversation": {"last_messages": ["mensaje 1", "mensaje 2"]},
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["action"] == "ai_usage_limit_reached"
    assert body["needs_human"] is True
    assert body["data_to_save"]["ai_usage_limit_reached"] is True
    assert body["data_to_save"]["ai_usage_limit_reason"] == "ai_disabled"
    assert backend.ai_usage_event_payloads == []


@pytest.mark.parametrize(
    ("daily_cost_limit_eur", "monthly_cost_limit_eur", "daily_estimated_cost_eur", "monthly_estimated_cost_eur", "expected_reason", "expected_type"),
    [
        (0.001, None, 0.001, 0.0, "daily_cost_limit_exceeded", "daily"),
        (None, 0.002, 0.0, 0.002, "monthly_cost_limit_exceeded", "monthly"),
    ],
)
def test_agent_respond_blocks_llm_when_tenant_ai_cost_limit_is_exceeded(
    monkeypatch,
    daily_cost_limit_eur,
    monthly_cost_limit_eur,
    daily_estimated_cost_eur,
    monthly_estimated_cost_eur,
    expected_reason,
    expected_type,
):
    configure_environment()

    backend = BlockedBackendClient(
        build_context(),
        ai_enabled=True,
        daily_cost_limit_eur=daily_cost_limit_eur,
        monthly_cost_limit_eur=monthly_cost_limit_eur,
        daily_estimated_cost_eur=daily_estimated_cost_eur,
        monthly_estimated_cost_eur=monthly_estimated_cost_eur,
    )

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when AI cost limit is exceeded")

    monkeypatch.setattr(LLMClient, "resolve_configuration", fail_if_llm_is_called)
    monkeypatch.setattr(LLMClient, "generate", fail_if_llm_is_called)
    monkeypatch.setattr(LLMDecisionService, "propose", fail_if_llm_is_called)

    app = create_app()
    app.dependency_overrides[get_backend_client] = lambda: backend
    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),  # type: ignore[arg-type]
        DecisionEngine(backend),  # type: ignore[arg-type]
        audio_gateway_client=FailingAudioGatewayClient(),  # type: ignore[arg-type]
        audio_transcription_client=FailingAudioTranscriptionClient(),  # type: ignore[arg-type]
    )
    app.dependency_overrides[get_agent_runtime] = lambda: runtime
    client = TestClient(app)

    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": "Necesito información comercial",
            "contact": {"phone": "+34999999999", "name": "Ana"},
            "conversation": {"last_messages": ["mensaje 1", "mensaje 2"]},
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["action"] == "ai_usage_limit_reached"
    assert body["needs_human"] is True
    assert body["data_to_save"]["ai_usage_limit_reached"] is True
    assert body["data_to_save"]["ai_usage_limit_reason"] == expected_reason
    assert body["data_to_save"]["ai_usage_limit_type"] == expected_type
    assert backend.ai_usage_event_payloads == []


@pytest.mark.asyncio
async def test_agent_respond_blocks_audio_before_download_when_tenant_ai_is_disabled(monkeypatch):
    configure_environment()

    backend = BlockedBackendClient(build_context(), ai_enabled=False)

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when AI is disabled")

    monkeypatch.setattr(LLMClient, "resolve_configuration", fail_if_llm_is_called)
    monkeypatch.setattr(LLMClient, "generate", fail_if_llm_is_called)
    monkeypatch.setattr(LLMDecisionService, "propose", fail_if_llm_is_called)

    app = create_app()
    app.dependency_overrides[get_backend_client] = lambda: backend
    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),  # type: ignore[arg-type]
        DecisionEngine(backend),  # type: ignore[arg-type]
        audio_gateway_client=FailingAudioGatewayClient(),  # type: ignore[arg-type]
        audio_transcription_client=FailingAudioTranscriptionClient(),  # type: ignore[arg-type]
    )
    app.dependency_overrides[get_agent_runtime] = lambda: runtime
    client = TestClient(app)

    response = client.post(
        "/agent/respond",
        headers={"Authorization": "Bearer test-internal-token"},
        json={
            "tenant_id": "tenant-1",
            "message": {
                "type": "audio",
                "media": {
                    "provider": "whatsapp_cloud_api",
                    "kind": "audio",
                    "media_id": "media-123",
                    "mime_type": "audio/ogg",
                    "sha256": "abc123",
                    "duration_seconds": 12,
                },
            },
            "contact": {"phone": "+34999999999", "name": "Ana"},
            "conversation": {"last_messages": ["mensaje 1", "mensaje 2"]},
        },
    )

    assert response.status_code == 200
    body = response.json()
    assert body["action"] == "ai_usage_limit_reached"
    assert body["needs_human"] is True
    assert body["data_to_save"]["ai_usage_limit_reached"] is True
    assert body["data_to_save"]["ai_usage_limit_reason"] == "ai_disabled"
    assert backend.ai_usage_event_payloads == []
