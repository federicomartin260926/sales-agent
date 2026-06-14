import json

import pytest

from app.config import Settings
from app.schemas.agent import AgentRequest, AgentResponse, Contact
from app.schemas.llm import McpRemoteConfig
from app.services.agent_orchestration.debug.orchestration_trace import OrchestrationTrace
from app.services.agent_orchestration.shadow.shadow_planning_service import ShadowPlanningService
from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendTenant, CommercialContext
from app.services.contact_context_resolver import ContactContextResolver
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext
from app.services.runtime import AgentRuntime


class FakeLLMClient:
    def __init__(self, planning_content: str) -> None:
        self.planning_content = planning_content
        self.resolve_configuration_calls = 0
        self.generate_calls: list[tuple[str, str, str, dict[str, str]]] = []

    async def resolve_configuration(self) -> dict[str, str]:
        self.resolve_configuration_calls += 1
        return {
            "llm_default_profile": "openai",
            "openai_base_url": "https://api.openai.test/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "test-key",
        }

    async def generate(self, provider: str, system_prompt: str, user_prompt: str, configuration: dict[str, str] | None = None):
        self.generate_calls.append((provider, system_prompt, user_prompt, configuration or {}))
        return type("LLMResponseResultStub", (), {"content": self.planning_content})()


def build_payload() -> AgentRequest:
    return AgentRequest(
        tenant_id="tenant-1",
        message="Prefiero el de las 16:45 con María Gutiérrez.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "external_id": "conversation-1",
            "summary": "Resumen previo",
            "last_messages": ["Hola", "Me interesa reservar"],
        },
    )


def build_routing() -> RoutingContext:
    return RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo")


@pytest.mark.asyncio
async def test_shadow_planning_service_is_disabled_when_flag_is_off():
    fake_llm = FakeLLMClient(planning_content="{}")
    settings = Settings()
    settings.new_llm_orchestration_enabled = False
    service = ShadowPlanningService(settings=settings, llm_client=fake_llm)  # type: ignore[arg-type]

    trace = await service.execute(build_payload(), build_routing(), backend_context=None, contact_context=None)

    assert trace.steps[0].step_type == "shadow_planning_disabled"
    assert trace.steps[0].output == {"enabled": False}
    assert fake_llm.resolve_configuration_calls == 0
    assert fake_llm.generate_calls == []


@pytest.mark.asyncio
async def test_shadow_planning_service_records_planning_and_policy_when_enabled():
    planning_result = json.dumps(
        {
            "schema_version": "1.0",
            "domain": "appointment",
            "intent": "select_offered_slot",
            "action_candidate": "prepare_booking_confirmation",
            "confidence": 0.93,
            "entities": {
                "service_name": "Láser cuerpo entero",
                "owner_name": "María Gutiérrez",
                "date": "2026-06-16",
                "time": "16:45",
            },
            "context_request": {
                "include_appointment_context": True,
                "include_offered_slots": True,
            },
            "tool_request": {
                "lookup_tools": ["appointment_availability"],
                "write_tools": ["appointment_confirm"],
            },
            "risk_flags": {
                "ambiguous_reference": False,
                "missing_required_data": False,
                "low_confidence": False,
            },
            "clarification": {
                "needed": False,
                "missing_fields": [],
            },
            "reason": "exact slot selection",
        },
        ensure_ascii=False,
    )
    fake_llm = FakeLLMClient(planning_content=planning_result)
    settings = Settings()
    settings.new_llm_orchestration_enabled = True
    service = ShadowPlanningService(settings=settings, llm_client=fake_llm)  # type: ignore[arg-type]

    trace = await service.execute(build_payload(), build_routing(), backend_context=None, contact_context=None)

    assert fake_llm.resolve_configuration_calls == 1
    assert fake_llm.generate_calls and fake_llm.generate_calls[0][0] == "openai"
    assert [step.step_type for step in trace.steps] == [
        "llm_intent_planning_input",
        "llm_intent_planning",
        "sa_context_policy",
    ]
    assert trace.steps[1].output["intent"] == "select_offered_slot"
    assert trace.steps[2].output["context_plan"]["include_offered_slots"] is True
    assert trace.steps[2].output["tool_policy"]["write_tools_enabled"] == ["appointment_confirm"]


@pytest.mark.asyncio
async def test_runtime_appends_shadow_trace_without_changing_response(monkeypatch: pytest.MonkeyPatch):
    backend = type("BackendStub", (), {})()
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.calls = []

    async def fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
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
        return CommercialContext(
            tenant=tenant,
            products=[],
            playbooks=[],
            selected_product=None,
            selected_playbook=None,
        )

    async def resolve_entrypoint_ref(*args, **kwargs):
        return None

    async def resolve_whatsapp_phone(*args, **kwargs):
        return {"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"}

    async def fetch_mcp_config(*args, **kwargs):
        return McpRemoteConfig(enabled=False)

    async def fetch_ai_usage_policy(*args, **kwargs):
        return BackendAiUsagePolicy(tenant_id="tenant-1", exists=False, ai_enabled=True)

    async def fetch_ai_usage_snapshot(*args, **kwargs):
        return BackendAiUsageSnapshot(tenant_id="tenant-1")

    async def upsert_conversation(*args, **kwargs):
        return {"created": True, "conversation": {"id": "conversation-1", "status": "active"}}

    async def create_conversation_message(*args, **kwargs):
        return type("ConversationMessageStub", (), {"created": True, "duplicate": False, "message": type("MessageStub", (), {"id": "msg-1"})()})()

    async def create_ai_usage_event(*args, **kwargs):
        return type("UsageEventStub", (), {"created": True, "event": {"id": "usage-1"}})()

    backend.fetch_tenant_context = fetch_tenant_context
    backend.resolve_entrypoint_ref = resolve_entrypoint_ref
    backend.resolve_whatsapp_phone = resolve_whatsapp_phone
    backend.fetch_mcp_config = fetch_mcp_config
    backend.fetch_ai_usage_policy = fetch_ai_usage_policy
    backend.fetch_ai_usage_snapshot = fetch_ai_usage_snapshot
    backend.upsert_conversation = upsert_conversation
    backend.create_conversation_message = create_conversation_message
    backend.create_ai_usage_event = create_ai_usage_event

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="He registrado tu solicitud.",
            intent="greet",
            score=0.92,
            action="answer_question",
            needs_human=False,
            data_to_save={"existing_key": "existing_value"},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=21,
        )

    async def fake_execute(self, payload, routing, backend_context, contact_context):
        trace = OrchestrationTrace(
            tenant_id=routing.tenant_id,
            conversation_id="conversation-1",
            external_conversation_id=payload.conversation.external_id,
            inbound_message=payload.message.text,
        )
        trace.add_step(
            step_type="shadow_test",
            input_context_keys=["current_message"],
            enabled_tools=[],
            output={"seen": True},
        )
        return trace

    async def fake_contact_context_resolve(self, payload, backend_context, mcp_config, recent_contact_context=None):
        return None

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_execute)
    monkeypatch.setattr(ContactContextResolver, "resolve", fake_contact_context_resolve)

    class ResolverStub:
        async def resolve(self, payload):
            return build_routing()

    runtime = AgentRuntime(backend, ResolverStub(), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = build_payload()

    response = await runtime.respond(payload)

    assert response.reply == "He registrado tu solicitud."
    assert response.data_to_save["existing_key"] == "existing_value"
    assert response.data_to_save["new_llm_orchestration_shadow_enabled"] is True
    assert response.data_to_save["new_llm_orchestration_trace"]["steps"][0]["step_type"] == "shadow_test"
