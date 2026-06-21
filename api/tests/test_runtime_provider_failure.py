from __future__ import annotations

import pytest

from app.schemas.agent import AgentRequest
from app.services.agent_orchestration.schemas import (
    AvailableToolsContext,
    BackendContext,
    BackendContactContext,
    BackendEntrypointContext,
    BackendPoliciesContext,
    BackendTenantContext,
)
from app.services.routing_resolver import RoutingContext
from app.services.runtime import AgentRuntime
from app.services.llm_provider_resilience import LlmProviderUnavailable


@pytest.mark.asyncio
async def test_build_llm_provider_failure_response_records_safe_technical_metadata() -> None:
    runtime = AgentRuntime.__new__(AgentRuntime)
    payload = AgentRequest.model_validate(
        {
            "tenant_id": "tenant-1",
            "channel_type": "whatsapp",
            "message": {"id": "msg-1", "text": "Hola"},
            "contact": {"phone": "+34600000000"},
            "conversation": {},
        }
    )
    routing = RoutingContext(tenant_id="tenant-1", source="test", status="ok")
    backend_context = BackendContext(
        tenant=BackendTenantContext(id="tenant-1", handoff={"enabled": False, "strategy": "disabled"}),
        contact=BackendContactContext(phone="+34600000000"),
        entrypoint=BackendEntrypointContext(ref="entry-1", channel="whatsapp"),
        available_tools=AvailableToolsContext(),
        policies=BackendPoliciesContext(),
    )

    response = runtime._build_llm_provider_failure_response(
        payload=payload,
        routing=routing,
        backend_context=backend_context,
        started_at=0.0,
        audio_result=None,
        provider_failure=LlmProviderUnavailable(kind="timeout", status_code=None, attempts=2, retryable=True),
        intent_plan=None,
    )

    assert response.reply == "Ahora mismo no puedo completar la consulta. Inténtalo de nuevo en unos minutos."
    assert response.intent == "unknown"
    assert response.action == "answer_directly"
    assert response.needs_human is False
    technical_metadata = response.data_to_save["technical_metadata"]
    assert technical_metadata["llm_provider_failure"] is True
    assert technical_metadata["failure_kind"] == "timeout"
    assert technical_metadata["attempts"] == 2
    assert "status_code" not in technical_metadata
