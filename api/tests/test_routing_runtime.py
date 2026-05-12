import pytest

from app.config import Settings
from app.schemas.agent import AgentResponse
from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendRoutingEntryPointUtmContext
from app.schemas.llm import McpRemoteConfig
from app.services.decision_engine import DecisionEngine
from app.services.llm_decision_service import LLMDecisionService
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime


class RecordingBackendClient:
    def __init__(
        self,
        ref_context: BackendRoutingEntryPointUtmContext | None = None,
        phone_context: dict[str, str] | None = None,
        mcp_config: McpRemoteConfig | None = None,
    ) -> None:
        self.ref_context = ref_context
        self.phone_context = phone_context
        self.mcp_config = mcp_config
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def resolve_entrypoint_ref(self, ref: str) -> BackendRoutingEntryPointUtmContext | None:
        self.calls.append(("resolve_entrypoint_ref", (ref,)))
        return self.ref_context

    async def resolve_whatsapp_phone(self, phone_number_id: str):
        self.calls.append(("resolve_whatsapp_phone", (phone_number_id,)))
        return self.phone_context

    async def fetch_tenant_context(self, tenant_id: str, selected_product_id: str | None = None, selected_playbook_id: str | None = None, *args):
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id, *args)))
        return None

    async def fetch_mcp_config(self, tenant_id: str) -> McpRemoteConfig:
        self.calls.append(("fetch_mcp_config", (tenant_id,)))
        return self.mcp_config or McpRemoteConfig(enabled=False)

    async def fetch_ai_usage_policy(self, tenant_id: str):
        self.calls.append(("fetch_ai_usage_policy", (tenant_id,)))
        return BackendAiUsagePolicy(tenant_id=tenant_id, exists=False, ai_enabled=True)

    async def fetch_ai_usage_snapshot(self, tenant_id: str):
        self.calls.append(("fetch_ai_usage_snapshot", (tenant_id,)))
        return BackendAiUsageSnapshot(tenant_id=tenant_id)

    async def upsert_conversation(self, payload):
        self.calls.append(("upsert_conversation", (payload.model_dump(by_alias=True),)))
        return {"created": True, "conversation": {"id": "conversation-1"}}

    async def create_conversation_message(self, payload):
        self.calls.append(("create_conversation_message", (payload.model_dump(by_alias=True),)))
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
        self.calls.append(("create_ai_usage_event", (payload.model_dump(by_alias=True),)))
        return type(
            "BackendAiUsageEventResultStub",
            (),
            {
                "created": True,
                "event": {"id": "usage-event-1"},
            },
        )()

@pytest.fixture(autouse=True)
def force_heuristic_llm(monkeypatch: pytest.MonkeyPatch) -> None:
    async def _skip_llm(*args, **kwargs):
        return None

    monkeypatch.setattr(
        LLMDecisionService,
        "propose",
        _skip_llm,
    )


@pytest.mark.asyncio
async def test_runtime_resolves_entrypoint_ref_before_tenant_or_phone():
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        )
    )
    resolver = RuntimeRoutingResolver(backend)  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        external_channel_id="123",
        message="Hola Ref: abc123",
        contact=Contact(phone="+34999999999"),
    )

    routing = await resolver.resolve(payload)

    assert routing is not None
    assert routing.source == "entrypoint_ref"
    assert routing.tenant_id == "tenant-1"
    assert routing.entrypoint_ref == "abc123"
    assert backend.calls == [("resolve_entrypoint_ref", ("abc123",))]


@pytest.mark.asyncio
async def test_runtime_resolves_whatsapp_phone_when_ref_is_missing():
    backend = RecordingBackendClient(phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"})
    resolver = RuntimeRoutingResolver(backend)  # type: ignore[arg-type]
    payload = AgentRequest(
        entrypoint_ref="missing-ref",
        external_channel_id="phone-number-id-1",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    routing = await resolver.resolve(payload)

    assert routing is not None
    assert routing.source == "whatsapp_phone_number_id"
    assert routing.tenant_id == "tenant-1"
    assert backend.calls == [("resolve_entrypoint_ref", ("missing-ref",)), ("resolve_whatsapp_phone", ("phone-number-id-1",))]


@pytest.mark.asyncio
async def test_runtime_uses_entrypoint_ref_context_in_agent_response():
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        )
    )
    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.action == "greet"
    assert response.needs_human is False
    assert response.data_to_save["tenant_id"] == "tenant-1"
    assert response.data_to_save["tenant_slug"] == "negocio-demo"
    assert response.data_to_save["product_id"] == "product-1"
    assert response.data_to_save["product_name"] == "CRM Automation"
    assert response.data_to_save["playbook_id"] == "playbook-1"
    assert response.data_to_save["entry_point_id"] == "entrypoint-1"
    assert response.data_to_save["entry_point_code"] == "crm-demo"
    assert response.data_to_save["entry_point_utm_id"] == "utm-1"
    assert response.data_to_save["entrypoint_ref"] == "abc123"
    assert response.data_to_save["crm_branch_ref"] == "branch-1"
    assert response.data_to_save["utm_source"] == "google"
    assert response.data_to_save["utm_medium"] == "cpc"
    assert response.data_to_save["utm_campaign"] == "crm_pymes"
    assert backend.calls[0] == ("resolve_entrypoint_ref", ("abc123",))


@pytest.mark.asyncio
async def test_runtime_missing_routing_context_returns_human_handoff():
    backend = RecordingBackendClient()
    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is True
    assert response.action == "missing_routing_context"
    assert response.intent == "routing"
    assert backend.calls == []


@pytest.mark.asyncio
async def test_runtime_persists_mcp_metadata_for_openai_response(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        ),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tech_investments_mcp",
            server_url="https://mcp.tech-investments.net/mcp",
            allowed_tools=["echo", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
        assert mcp_config is not None and mcp_config.enabled is True
        return AgentResponse(
            reply="Tu próxima cita es mañana a las 10:00.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "contact_context_mock",
                        "arguments": {"phone": "+34600000000"},
                        "output": {"found": True},
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=87,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Cuéntame sobre el servicio disponible",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    create_calls = [call for call in backend.calls if call[0] == "create_conversation_message"]
    assert len(create_calls) == 2
    outbound_payload = create_calls[-1][1][0]
    assert outbound_payload["metadata"]["mcp_enabled"] is True
    assert outbound_payload["metadata"]["mcp_server_label"] == "tech_investments_mcp"
    assert outbound_payload["metadata"]["mcp_response_id"] == "resp_123"
    assert outbound_payload["metadata"]["mcp_tool_traces"][0]["tool_name"] == "contact_context_mock"


@pytest.mark.asyncio
async def test_runtime_does_not_short_circuit_agenda_lookup_messages(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        ),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tech_investments_mcp",
            server_url="https://mcp.tech-investments.net/mcp",
            allowed_tools=["appointment_events", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
        assert mcp_config is not None and mcp_config.enabled is True
        return AgentResponse(
            reply="Sí, tienes citas programadas en mayo.",
            intent="agenda",
            score=0.94,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_lookup_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_events",
                        "arguments": {"phone": "+34600000000"},
                        "output": {"found": True},
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=91,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Tengo citas programadas para mayo? Consulta mi agenda.",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.model == "gpt-4.1-mini"
    assert response.action == "answer_question"
    assert response.data_to_save["mcp_tool_traces"][0]["tool_name"] == "appointment_events"
    create_calls = [call for call in backend.calls if call[0] == "create_conversation_message"]
    assert len(create_calls) == 2
    outbound_payload = create_calls[-1][1][0]
    assert outbound_payload["metadata"]["mcp_enabled"] is True
    assert outbound_payload["metadata"]["mcp_server_label"] == "tech_investments_mcp"
    assert outbound_payload["metadata"]["mcp_tool_traces"][0]["tool_name"] == "appointment_events"


@pytest.mark.asyncio
async def test_runtime_skips_mcp_for_ollama_and_records_reason(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        ref_context=BackendRoutingEntryPointUtmContext.model_validate(
            {
                "entry_point_utm_id": "utm-1",
                "ref": "abc123",
                "entry_point_id": "entrypoint-1",
                "entry_point_code": "crm-demo",
                "tenant_id": "tenant-1",
                "tenant_slug": "negocio-demo",
                "product_id": "product-1",
                "product_name": "CRM Automation",
                "playbook_id": "playbook-1",
                "crm_branch_ref": "branch-1",
                "utm_source": "google",
                "utm_medium": "cpc",
                "utm_campaign": "crm_pymes",
                "status": "matched",
            }
        ),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tech_investments_mcp",
            server_url="https://mcp.tech-investments.net/mcp",
            allowed_tools=["echo", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
        return AgentResponse(
            reply="No puedo usar MCP con Ollama.",
            intent="open_question",
            score=0.5,
            action="ask_question",
            needs_human=False,
            data_to_save={},
            provider="ollama",
            model="llama3.1",
            latency_ms=42,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "ollama"
    create_calls = [call for call in backend.calls if call[0] == "create_conversation_message"]
    assert len(create_calls) == 2
    outbound_payload = create_calls[-1][1][0]
    assert outbound_payload["metadata"]["mcp_skipped_reason"] == "provider_not_supported"
