from datetime import datetime, timedelta, timezone
import json

import httpx
import pytest

from app.config import Settings
from app.schemas.agent import AgentResponse
from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendConversationMessagePayload, BackendExternalTool, BackendRoutingEntryPointUtmContext, BackendTenant, CommercialContext
from app.services.agent_orchestration.debug.orchestration_trace import OrchestrationTrace
from app.services.agent_orchestration.execution.appointment_availability_execution_service import (
    AppointmentAvailabilityExecutionOutcome,
    AppointmentAvailabilityExecutionService,
)
from app.services.agent_orchestration.execution.slot_selection_execution_service import (
    SlotSelectionExecutionOutcome,
    SlotSelectionExecutionService,
)
from app.services.agent_orchestration.execution.catalog_execution_service import CatalogExecutionService
from app.services.agent_orchestration.execution.catalog_execution_service import CatalogExecutionOutcome
from app.services.agent_orchestration.shadow.shadow_planning_service import ShadowPlanningService
from app.services.contact_context_resolver import ContactContextResolver
from app.schemas.llm import LLMUsage, McpRemoteConfig
from app.services.decision_engine import DecisionEngine
from app.services.llm_decision_service import LLMDecisionService
from app.services.llm_prompt_builder import LLMPromptBuilder
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver
from app.services.runtime import AgentRuntime


async def assert_slice_not_called(*args, **kwargs):
    raise AssertionError("non-applicable slices should not run")


async def fail_if_decide_called(*args, **kwargs):
    raise AssertionError("legacy decision engine should not be called when a new slice succeeds")


@pytest.fixture(autouse=True)
def noop_contact_context_refresh(monkeypatch: pytest.MonkeyPatch) -> None:
    async def _noop_resolve(self, payload, backend_context, mcp_config, recent_contact_context=None):
        return recent_contact_context

    monkeypatch.setattr(ContactContextResolver, "resolve", _noop_resolve)


def build_contact_context_payload(
    timezone_name: str = "Atlantic/Canary",
    timezone_source: str = "crm_tenant",
) -> dict[str, object]:
    return {
        "available": True,
        "configured": True,
        "provider": "n8n_webhook",
        "ok": True,
        "found": True,
        "error_code": None,
        "data": {
            "source": "contact_context",
            "timezone": timezone_name,
            "timezone_source": timezone_source,
            "needs_branch_selection": False,
            "business_context": {
                "timezone": timezone_name,
                "timezone_source": timezone_source,
                "needs_branch_selection": False,
            },
        },
    }


async def resolve_contact_context_payload(*args, **kwargs) -> dict[str, object]:
    return build_contact_context_payload()


class RecordingBackendClient:
    def __init__(
        self,
        ref_context: BackendRoutingEntryPointUtmContext | None = None,
        phone_context: dict[str, str] | None = None,
        mcp_config: McpRemoteConfig | None = None,
        handoff_tool: BackendExternalTool | None = None,
        tenant_context: CommercialContext | None = None,
        conversation_result: dict[str, object] | None = None,
        summary_context: object | None = None,
    ) -> None:
        self.ref_context = ref_context
        self.phone_context = phone_context
        self.mcp_config = mcp_config
        self.handoff_tool = handoff_tool
        self.tenant_context = tenant_context
        self.conversation_result = conversation_result
        self.summary_context = summary_context
        self._stored_conversation_messages: dict[str, list[dict[str, object]]] = {}
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def resolve_entrypoint_ref(self, ref: str) -> BackendRoutingEntryPointUtmContext | None:
        self.calls.append(("resolve_entrypoint_ref", (ref,)))
        return self.ref_context

    async def resolve_whatsapp_phone(self, phone_number_id: str):
        self.calls.append(("resolve_whatsapp_phone", (phone_number_id,)))
        return self.phone_context

    async def fetch_tenant_context(self, tenant_id: str, selected_product_id: str | None = None, selected_playbook_id: str | None = None, *args):
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id, *args)))
        return self.tenant_context

    async def fetch_mcp_config(self, tenant_id: str) -> McpRemoteConfig:
        self.calls.append(("fetch_mcp_config", (tenant_id,)))
        return self.mcp_config or McpRemoteConfig(enabled=False)

    async def fetch_ai_usage_policy(self, tenant_id: str):
        self.calls.append(("fetch_ai_usage_policy", (tenant_id,)))
        return BackendAiUsagePolicy(tenant_id=tenant_id, exists=False, ai_enabled=True)

    async def get_external_tool(self, tenant_id: str, tool_type: str):
        self.calls.append(("get_external_tool", (tenant_id, tool_type)))
        if tool_type == "handoff_webhook":
            return self.handoff_tool

        return None

    async def fetch_ai_usage_snapshot(self, tenant_id: str):
        self.calls.append(("fetch_ai_usage_snapshot", (tenant_id,)))
        return BackendAiUsageSnapshot(tenant_id=tenant_id)

    async def upsert_conversation(self, payload):
        self.calls.append(("upsert_conversation", (payload.model_dump(by_alias=True),)))
        return self.conversation_result or {"created": True, "conversation": {"id": "conversation-1", "status": "active"}}

    async def create_conversation_message(self, payload):
        payload_dict = payload.model_dump(by_alias=True)
        self.calls.append(("create_conversation_message", (payload_dict,)))
        conversation_id = payload_dict.get("conversation_id")
        if isinstance(conversation_id, str) and conversation_id.strip() != "":
            self._stored_conversation_messages.setdefault(conversation_id, []).append(payload_dict)
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

    async def get_conversation_summary_context(
        self,
        conversation_id: str,
        limit: int = 20,
        tenant_id: str | None = None,
        external_conversation_id: str | None = None,
        customer_phone: str | None = None,
        channel_type: str | None = None,
    ):
        self.calls.append(("get_conversation_summary_context", (conversation_id, limit, tenant_id, external_conversation_id, customer_phone, channel_type)))
        if self.summary_context is not None:
            return self.summary_context

        messages = self._stored_conversation_messages.get(conversation_id, [])
        if messages == [] and external_conversation_id is not None:
            messages = self._stored_conversation_messages.get(external_conversation_id, [])
        if messages == []:
            return None

        class SummaryMessageStub:
            def __init__(self, payload: dict[str, object]) -> None:
                self.payload = payload

            def model_dump(self):
                return self.payload

        return type(
            "SummaryContextStub",
            (),
            {
                "messages": [SummaryMessageStub(message) for message in messages[-limit:]],
            },
        )()

    async def get_contact_context_cache(self, tenant_id: str, contact_key: str, provider: str = "contact_context"):
        self.calls.append(("get_contact_context_cache", (tenant_id, contact_key, provider)))
        return None

    async def save_contact_context_cache(self, payload):
        self.calls.append(("save_contact_context_cache", (payload,)))
        return None

    async def invalidate_contact_context_cache(self, tenant_id: str, contact_key: str, provider: str = "contact_context"):
        self.calls.append(("invalidate_contact_context_cache", (tenant_id, contact_key, provider)))
        return None


class RecordingAudioGatewayClient:
    def __init__(self) -> None:
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def download_whatsapp_media(self, media_id: str):
        self.calls.append(("download_whatsapp_media", (media_id,)))
        return type(
            "AudioDownloadResultStub",
            (),
            {
                "content": b"fake-ogg-bytes",
                "content_type": "audio/ogg",
                "media_id": media_id,
            },
        )()


class RecordingAudioTranscriptionClient:
    def __init__(self) -> None:
        self.calls: list[tuple[str, tuple[object, ...]]] = []

    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str, duration_seconds: int | None = None):
        self.calls.append(("transcribe", (audio_bytes, content_type, media_id)))
        return type(
            "AudioTranscriptionResultStub",
            (),
            {
                "text": "Hola, quiero información",
                "model": "gpt-4o-mini-transcribe",
                "provider": "openai",
                "usage": LLMUsage(
                    provider="openai",
                    model="gpt-4o-mini-transcribe",
                    input_tokens=73,
                    output_tokens=24,
                    cached_tokens=0,
                    audio_tokens=73,
                    total_tokens=97,
                ),
                "duration_seconds": duration_seconds,
                "audio_bytes": len(audio_bytes),
                "latency_ms": 42,
            },
        )()


def build_context() -> CommercialContext:
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


class FailingAudioTranscriptionClient:
    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str, duration_seconds: int | None = None):
        raise RuntimeError("OpenAI transcription rejected media media-123: 400 body=Invalid audio format")


class RecordingConversationSummaryService:
    def __init__(self, summary: str = "Resumen compacto para humano.") -> None:
        self.summary = summary
        self.calls: list[tuple[str, str, int]] = []
        self.raise_on_call = False

    async def generate_and_persist(self, conversation_id: str, reason: str, limit: int = 20) -> str | None:
        self.calls.append((conversation_id, reason, limit))
        if self.raise_on_call:
            raise RuntimeError("summary service unavailable")

        return self.summary


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
    assert backend.calls == [("resolve_entrypoint_ref", ("abc123",)), ("resolve_whatsapp_phone", ("123",))]


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
async def test_runtime_rejects_mismatched_entrypoint_and_whatsapp_phone():
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
        phone_context={"tenant_id": "tenant-2", "tenant_slug": "otro-negocio"},
    )
    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        entrypoint_ref="abc123",
        external_channel_id="phone-number-id-1",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.action == "misconfigured_routing"
    assert response.intent == "routing"
    assert response.needs_human is True
    assert "inconsistente" in response.reply
    assert backend.calls == [("resolve_entrypoint_ref", ("abc123",)), ("resolve_whatsapp_phone", ("phone-number-id-1",))]


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
async def test_runtime_transcribes_audio_before_continuing_flow():
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
    )
    audio_gateway = RecordingAudioGatewayClient()
    audio_transcription = RecordingAudioTranscriptionClient()
    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        audio_gateway_client=audio_gateway,  # type: ignore[arg-type]
        audio_transcription_client=audio_transcription,  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message={
            "type": "audio",
                "media": {
                    "provider": "whatsapp_cloud_api",
                    "kind": "audio",
                    "media_id": "media-123",
                    "mime_type": "audio/ogg",
                    "sha256": "abc123",
                    "duration_seconds": 30,
                },
            },
            contact=Contact(phone="+34999999999"),
        )

    response = await runtime.respond(payload)

    assert audio_gateway.calls == [("download_whatsapp_media", ("media-123",))]
    assert audio_transcription.calls == [("transcribe", (b"fake-ogg-bytes", "audio/ogg", "media-123"))]
    inbound_calls = [
        call for call in backend.calls if call[0] == "create_conversation_message" and call[1][0]["direction"] == "inbound"
    ]
    assert inbound_calls
    inbound_payload = inbound_calls[0][1][0]
    assert inbound_payload["message_type"] == "audio"
    assert inbound_payload["body"] == "Hola, quiero información"
    assert inbound_payload["metadata"]["message_original_type"] == "audio"
    assert inbound_payload["metadata"]["message_media"]["transcript"] == "Hola, quiero información"
    usage_calls = [call for call in backend.calls if call[0] == "create_ai_usage_event"]
    assert usage_calls
    audio_usage = usage_calls[0][1][0]
    assert audio_usage["usage_type"] == "audio_transcription"
    assert audio_usage["provider"] == "openai"
    assert audio_usage["model"] == "gpt-4o-mini-transcribe"
    assert audio_usage["input_tokens"] == 73
    assert audio_usage["output_tokens"] == 24
    assert audio_usage["cached_tokens"] == 0
    assert audio_usage["total_tokens"] == 97
    assert audio_usage["estimated_cost"] == pytest.approx(0.01)
    assert audio_usage["latency_ms"] == 42
    if len(usage_calls) > 1:
        assert usage_calls[1][1][0]["usage_type"] == "llm_chat"
    assert response.action in {"greet", "ask_question", "propose_meeting", "none"}


@pytest.mark.asyncio
async def test_runtime_audio_transcription_failure_without_handoff_requests_text(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return None

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when audio transcription fails without handoff")

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fail_if_llm_is_called)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        audio_gateway_client=RecordingAudioGatewayClient(),  # type: ignore[arg-type]
        audio_transcription_client=FailingAudioTranscriptionClient(),  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message={
            "type": "audio",
            "media": {
                "provider": "whatsapp_cloud_api",
                "kind": "audio",
                "media_id": "media-123",
                "mime_type": "audio/ogg; codecs=opus",
                "sha256": "abc123",
                "duration_seconds": 12,
            },
        },
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.action == "audio_transcription_failed"
    assert response.intent == "audio"
    assert response.needs_human is False
    assert "por escrito" in response.reply
    assert "te paso con una persona" not in response.reply.lower()


@pytest.mark.asyncio
async def test_runtime_audio_transcription_failure_triggers_handoff_when_configured(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "manual_wa_link_and_n8n",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when audio transcription fails and handoff is configured")

    class FakeResponse:
        def raise_for_status(self) -> None:
            return None

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            self.post_calls = []

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            self.post_calls.append((url, json, headers))
            return FakeResponse()

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fail_if_llm_is_called)
    monkeypatch.setattr("app.services.runtime.httpx.AsyncClient", FakeAsyncClient)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        audio_gateway_client=RecordingAudioGatewayClient(),  # type: ignore[arg-type]
        audio_transcription_client=FailingAudioTranscriptionClient(),  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message={
            "type": "audio",
            "media": {
                "provider": "whatsapp_cloud_api",
                "kind": "audio",
                "media_id": "media-123",
                "mime_type": "audio/ogg; codecs=opus",
                "sha256": "abc123",
                "duration_seconds": 12,
            },
        },
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.action == "handoff_to_human"
    assert response.intent == "handoff"
    assert response.needs_human is True
    assert "https://wa.me/34612345678" in response.reply
    assert "te paso con una persona" in response.reply.lower()
    assert ("get_external_tool", ("tenant-1", "handoff_webhook")) in backend.calls


@pytest.mark.asyncio
async def test_runtime_generates_summary_on_handoff(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "manual_wa_link_and_n8n",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="¿Qué servicio necesitas?",
            intent="open_question",
            score=0.95,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=42,
        )

    class FakeResponse:
        def raise_for_status(self) -> None:
            return None

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            self.post_calls = []

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            self.post_calls.append((url, json, headers))
            return FakeResponse()

    summary_service = RecordingConversationSummaryService()

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr("app.services.runtime.httpx.AsyncClient", FakeAsyncClient)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        conversation_summary_service=summary_service,  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is True
    assert response.action == "handoff_to_human"
    assert summary_service.calls == [("conversation-1", "handoff_to_human", 20)]


@pytest.mark.asyncio
async def test_runtime_does_not_generate_summary_on_normal_turn(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend_context = build_context()

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Claro, te explico.",
            intent="open_question",
            score=0.95,
            action="answer_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=42,
        )

    summary_service = RecordingConversationSummaryService()

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        conversation_summary_service=summary_service,  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        external_channel_id="phone-number-id-1",
        message="Hola, ¿qué horario tenéis?",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is False
    assert summary_service.calls == []


@pytest.mark.asyncio
async def test_runtime_keeps_handoff_response_when_summary_generation_fails(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "manual_wa_link_and_n8n",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="¿Qué servicio necesitas?",
            intent="open_question",
            score=0.95,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=42,
        )

    class FakeResponse:
        def raise_for_status(self) -> None:
            return None

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            self.post_calls = []

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            self.post_calls.append((url, json, headers))
            return FakeResponse()

    summary_service = RecordingConversationSummaryService()
    summary_service.raise_on_call = True

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr("app.services.runtime.httpx.AsyncClient", FakeAsyncClient)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        conversation_summary_service=summary_service,  # type: ignore[arg-type]
    )
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is True
    assert response.action == "handoff_to_human"
    assert summary_service.calls == [("conversation-1", "handoff_to_human", 20)]


@pytest.mark.asyncio
async def test_runtime_passes_recent_openai_cursor_to_llm(monkeypatch: pytest.MonkeyPatch):
    previous_response_id = "resp_123"
    last_response_at = (datetime.now(timezone.utc) - timedelta(minutes=30)).isoformat()
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="mary_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["appointment_availability"],
            require_approval="never",
        ),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": previous_response_id,
                "lastOpenAiResponseAt": last_response_at,
            },
        },
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        seen["previous_response_id"] = previous_response_id
        return AgentResponse(
            reply="Respuesta con continuidad.",
            intent="open_question",
            score=0.9,
            action="ask_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=12,
        )

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message="Hola, seguimos hablando",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "open_question"
    assert seen["previous_response_id"] == previous_response_id


@pytest.mark.asyncio
async def test_runtime_keeps_cursor_for_slot_selection_followup(monkeypatch: pytest.MonkeyPatch):
    previous_response_id = "resp_123"
    last_response_at = (datetime.now(timezone.utc) - timedelta(minutes=30)).isoformat()

    availability_trace = {
        "type": "mcp_call",
        "server_label": "mary_main_mcp",
        "tool_name": "appointment_availability",
        "arguments": {
            "service_id": "service-uuid",
            "timezone": "Europe/Madrid",
        },
        "output": {
            "available": True,
            "slots": [
                {
                    "start": "2026-06-11T17:35:00+02:00",
                    "end": "2026-06-11T19:05:00+02:00",
                    "service_id": "service-uuid",
                    "owner_id": "owner-uuid",
                    "owner_ref": "owner-ref-1",
                    "timezone": "Europe/Madrid",
                }
            ],
        },
        "status": "completed",
    }

    class SummaryMessageStub:
        def __init__(self, payload: dict[str, object]) -> None:
            self.payload = payload

        def model_dump(self):
            return self.payload

    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="mary_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["appointment_availability"],
            require_approval="never",
        ),
        summary_context=type(
            "SummaryContextStub",
            (),
            {
                "messages": [
                    SummaryMessageStub(
                        {
                            "id": "message-availability-1",
                            "direction": "outbound",
                            "role": "assistant",
                            "message_type": "text",
                            "body": "Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
                            "intent": "agenda",
                            "action": "answer_question",
                            "needs_human": False,
                            "metadata": {
                                "mcp_tool_traces": [availability_trace],
                                "mcp_response_id": "resp_availability_123",
                            },
                        }
                    )
                ]
            },
        )(),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": previous_response_id,
                "lastOpenAiResponseAt": last_response_at,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = True

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message="Elijo las 17:35. ¿Me puedes confirmar la cita?",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "select_offered_slot"
    assert "me dices tu nombre" in response.reply
    assert response.data_to_save["required_next_action"] == "collect_customer_name"
    assert response.data_to_save["new_llm_orchestration_selected_slot"]["owner_id"] == "owner-uuid"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner_id"] == "owner-uuid"


@pytest.mark.asyncio
async def test_runtime_preloads_contact_context_for_prefiero_slot_selection_followup(monkeypatch: pytest.MonkeyPatch):
    previous_response_id = "resp_123"
    last_response_at = (datetime.now(timezone.utc) - timedelta(minutes=30)).isoformat()

    availability_trace = {
        "type": "mcp_call",
        "server_label": "mary_main_mcp",
        "tool_name": "appointment_availability",
        "arguments": {
            "service_id": "service-uuid",
            "timezone": "Europe/Madrid",
        },
        "output": {
            "available": True,
            "slots": [
                {
                    "start": "2026-06-15T16:00:00+01:00",
                    "end": "2026-06-15T17:30:00+01:00",
                    "service_id": "service-uuid",
                    "owner": {
                        "id": "owner-claudia-uuid",
                        "name": "Claudia Estética",
                    },
                    "timezone": "Europe/Madrid",
                },
                {
                    "start": "2026-06-15T17:35:00+01:00",
                    "end": "2026-06-15T19:05:00+01:00",
                    "service_id": "service-uuid",
                    "owner": {
                        "id": "owner-claudia-uuid",
                        "name": "Claudia Estética",
                    },
                    "timezone": "Europe/Madrid",
                },
                {
                    "start": "2026-06-15T16:00:00+01:00",
                    "end": "2026-06-15T17:30:00+01:00",
                    "service_id": "service-uuid",
                    "owner": {
                        "id": "owner-maria-uuid",
                        "name": "María Gutiérrez",
                    },
                    "timezone": "Europe/Madrid",
                },
                {
                    "start": "2026-06-15T17:35:00+01:00",
                    "end": "2026-06-15T19:05:00+01:00",
                    "service_id": "service-uuid",
                    "owner": {
                        "id": "owner-maria-uuid",
                        "name": "María Gutiérrez",
                    },
                    "timezone": "Europe/Madrid",
                },
                {
                    "start": "2026-06-15T19:10:00+01:00",
                    "end": "2026-06-15T20:40:00+01:00",
                    "service_id": "service-uuid",
                    "owner": {
                        "id": "owner-maria-uuid",
                        "name": "María Gutiérrez",
                    },
                    "timezone": "Europe/Madrid",
                }
            ],
        },
        "status": "completed",
    }

    class SummaryMessageStub:
        def __init__(self, payload: dict[str, object]) -> None:
            self.payload = payload

        def model_dump(self):
            return self.payload

    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="mary_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["appointment_availability"],
            require_approval="never",
        ),
        summary_context=type(
            "SummaryContextStub",
            (),
            {
                "messages": [
                    SummaryMessageStub(
                        {
                            "id": "message-availability-1",
                            "direction": "outbound",
                            "role": "assistant",
                            "message_type": "text",
                            "body": "Para mañana por la tarde hay disponibilidad a las 16:00, 17:35 y 19:10.",
                            "intent": "agenda",
                            "action": "answer_question",
                            "needs_human": False,
                            "metadata": {
                                "mcp_tool_traces": [availability_trace],
                                "mcp_response_id": "resp_availability_123",
                            },
                        }
                    )
                ]
            },
        )(),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": previous_response_id,
                "lastOpenAiResponseAt": last_response_at,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = False
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = False

    resolver_calls: dict[str, object] = {"count": 0}

    async def fake_resolve(self, payload, backend_context, mcp_config, recent_contact_context=None):
        resolver_calls["count"] = int(resolver_calls["count"]) + 1
        return {
            "available": True,
            "configured": True,
            "tool_type": "contact_context",
            "provider": "n8n_webhook",
            "ok": True,
            "found": True,
            "source": "external_tool:n8n",
            "error_code": None,
            "data": {
                "source": "external_tool:n8n",
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "needs_branch_selection": False,
                "business_context": {
                    "timezone": "Atlantic/Canary",
                    "timezone_source": "crm_tenant",
                    "needs_branch_selection": False,
                },
            },
        }

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        assert contact_context["data"]["business_context"]["timezone"] == "Atlantic/Canary"
        assert contact_context["data"]["business_context"]["timezone_source"] == "crm_tenant"
        _system_prompt, user_prompt = LLMPromptBuilder().build(payload, routing, backend_context, contact_context, mcp_config)
        parsed_prompt = json.loads(user_prompt)
        assert parsed_prompt["conversation"]["appointment_context"]["selected_slot"]["start"] == "2026-06-15T19:10:00+01:00"
        assert parsed_prompt["conversation"]["appointment_context"]["selected_slot"]["owner_id"] == "owner-maria-uuid"
        assert parsed_prompt["conversation"]["appointment_context"]["selected_slot"]["owner_name"] == "María Gutiérrez"
        return AgentResponse(
            reply="Perfecto, voy a revisar ese horario.",
            intent="agenda",
            score=0.94,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "mary_main_mcp",
                "mcp_tool_traces": [],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=10,
        )

    monkeypatch.setattr(ContactContextResolver, "resolve", fake_resolve)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Prefiero el de las 19:10 con María Gutiérrez.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "channel": "whatsapp",
            "last_messages": [
                "SA: Para el lunes 15 de junio por la tarde hay disponibilidad a las 16:00, 17:35 y 19:10.",
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert resolver_calls["count"] == 1
    assert response.data_to_save["contact_context_resolver_called"] is True
    assert response.data_to_save["contact_context_available"] is True
    assert response.data_to_save["contact_context_source"] == "external_tool:n8n"
    assert response.data_to_save["effective_timezone"] == "Atlantic/Canary"
    assert response.data_to_save["effective_timezone_source"] == "crm_tenant"
    assert response.data_to_save["operational_context"]["effective_timezone"] == "Atlantic/Canary"
    assert response.data_to_save["operational_context"]["appointment_tool_timezone"] == "Atlantic/Canary"
    assert response.data_to_save["operational_context"]["channel"] == "whatsapp"
    assert response.data_to_save["timezone_guardrail_blocked"] is False
    assert response.data_to_save["timezone_mismatch_detected"] is False


@pytest.mark.asyncio
async def test_runtime_keeps_technical_fallback_timezone_separate_from_operational_context(monkeypatch: pytest.MonkeyPatch):
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
            enabled=False,
            server_label=None,
            server_url=None,
            allowed_tools=[],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": None,
                "lastOpenAiResponseAt": None,
            },
        },
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Hola, ¿en qué puedo ayudarte?",
            intent="open_question",
            score=0.82,
            action="answer_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=14,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola, ¿puedes ayudarme?",
        contact=Contact(phone="+34999999999"),
        conversation={
            "channel": "whatsapp",
            "last_messages": [],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "open_question"
    assert response.data_to_save["effective_timezone"] is None
    assert response.data_to_save["effective_timezone_source"] == "settings.default_business_timezone"
    assert response.data_to_save["technical_fallback_timezone"] == "Europe/Madrid"
    assert response.data_to_save["technical_fallback_timezone_source"] == "settings.default_business_timezone"
    assert response.data_to_save["operational_context"]["effective_timezone"] is None
    assert response.data_to_save["operational_context"]["appointment_tool_timezone"] is None
    assert response.data_to_save["operational_context"]["channel"] == "whatsapp"


@pytest.mark.asyncio
async def test_runtime_omits_cursor_for_fresh_availability_request(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="mary_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["appointment_availability"],
            require_approval="never",
        ),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": "resp_123",
                "lastOpenAiResponseAt": (datetime.now(timezone.utc) - timedelta(minutes=30)).isoformat(),
            },
        },
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        seen["previous_response_id"] = previous_response_id
        return AgentResponse(
            reply="Respuesta con disponibilidad fresca.",
            intent="agenda",
            score=0.9,
            action="answer_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=12,
        )

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert seen["previous_response_id"] is None


@pytest.mark.asyncio
async def test_runtime_does_not_pass_cursor_when_conversation_is_pending_human(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "pending_human",
                "lastOpenAiResponseId": "resp_123",
                "lastOpenAiResponseAt": (datetime.now(timezone.utc) - timedelta(minutes=30)).isoformat(),
            },
        },
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        seen["previous_response_id"] = previous_response_id
        return AgentResponse(
            reply="Respuesta sin cursor.",
            intent="open_question",
            score=0.9,
            action="ask_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=12,
        )

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message="Hola, seguimos hablando",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "open_question"
    assert seen["previous_response_id"] is None


@pytest.mark.asyncio
async def test_runtime_does_not_pass_cursor_when_conversation_cursor_is_expired(monkeypatch: pytest.MonkeyPatch):
    backend = RecordingBackendClient(
        phone_context={"tenant_id": "tenant-1", "tenant_slug": "negocio-demo"},
        tenant_context=build_context(),
        conversation_result={
            "created": False,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "lastOpenAiResponseId": "resp_123",
                "lastOpenAiResponseAt": (datetime.now(timezone.utc) - timedelta(hours=25)).isoformat(),
            },
        },
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        seen["previous_response_id"] = previous_response_id
        return AgentResponse(
            reply="Respuesta sin cursor.",
            intent="open_question",
            score=0.9,
            action="ask_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=12,
        )

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
    )
    payload = AgentRequest(
        external_channel_id="phone-number-id-1",
        message="Hola, seguimos hablando",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "open_question"
    assert seen["previous_response_id"] is None


@pytest.mark.asyncio
async def test_runtime_short_circuits_explicit_handoff_for_manual_wa_link(monkeypatch: pytest.MonkeyPatch):
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
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "manual_wa_link",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        raise AssertionError("LLM should not be called for explicit handoff requests")

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero hablar con una persona",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.needs_human is True
    assert response.intent == "handoff"
    assert response.action == "handoff_to_human"
    assert response.data_to_save["local_response_short_circuited"] is True
    assert "https://wa.me/34612345678" in response.reply
    assert "qué servicio" not in response.reply.lower()
    assert ("get_external_tool", ("tenant-1", "handoff_webhook")) not in backend.calls


@pytest.mark.asyncio
async def test_runtime_appends_handoff_wa_link_and_attempts_webhook(monkeypatch: pytest.MonkeyPatch):
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
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "manual_wa_link_and_n8n",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )
    decide_called = False

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        nonlocal decide_called
        decide_called = True
        return AgentResponse(
            reply="¿Qué servicio necesitas?",
            intent="open_question",
            score=0.95,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=42,
        )

    class FakeResponse:
        def raise_for_status(self) -> None:
            return None

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            self.post_calls = []

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            self.post_calls.append((url, json, headers))
            return FakeResponse()

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr("app.services.runtime.httpx.AsyncClient", FakeAsyncClient)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Hola",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert decide_called is True
    assert response.needs_human is True
    assert "qué servicio" in response.reply.lower()
    assert "https://wa.me/34612345678" in response.reply
    assert ("get_external_tool", ("tenant-1", "handoff_webhook")) in backend.calls


@pytest.mark.asyncio
async def test_runtime_webhook_failure_does_not_break_response(monkeypatch: pytest.MonkeyPatch):
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
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "n8n_webhook",
                "whatsapp_public": None,
                "message": None,
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )
    decide_called = False

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        nonlocal decide_called
        decide_called = True
        return AgentResponse(
            reply="Perfecto, te paso con una persona del equipo.",
            intent="handoff",
            score=0.95,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=42,
        )

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            pass

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            raise httpx.ConnectError("n8n down", request=httpx.Request("POST", url))

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr("app.services.runtime.httpx.AsyncClient", FakeAsyncClient)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero hablar con una persona",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert decide_called is True
    assert response.needs_human is True
    assert "https://wa.me/" not in response.reply


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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
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
async def test_runtime_blocks_agenda_when_effective_timezone_is_missing(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability"],
            timeout_seconds=15,
        ),
    )

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when effective timezone is missing")

    async def fake_resolve_contact_context(*args, **kwargs):
        return None

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_llm_is_called)
    monkeypatch.setattr(ContactContextResolver, "resolve", fake_resolve_contact_context)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Dime disponibilidad de Claudia para láser cuerpo entero para mañana por la tarde.",
        contact=Contact(phone="+34999999999"),
        conversation={"last_messages": []},
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "ask_question"
    assert response.needs_human is False
    assert "configuración de agenda" in response.reply.lower()
    assert response.data_to_save["timezone_guardrail_blocked"] is True
    assert response.data_to_save["timezone_guardrail_reason"] == "missing_effective_timezone"
    assert response.data_to_save["mismatched_tool"] == "appointment_availability"
    assert response.data_to_save["contact_context_resolver_called"] is True
    assert response.data_to_save["contact_context_available"] is False
    assert response.data_to_save["contact_context_source"] == "none"
    assert response.data_to_save["contact_context_error_code"] == "unknown"
    assert "effective_timezone" not in response.data_to_save
    assert response.data_to_save["technical_fallback_timezone"] == "Europe/Madrid"
    assert response.data_to_save["technical_fallback_timezone_source"] == "settings.default_business_timezone"


@pytest.mark.asyncio
async def test_runtime_preserves_contact_context_error_code_from_resolver(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability"],
            timeout_seconds=15,
        ),
    )

    async def fake_resolve_contact_context(*args, **kwargs):
        return {
            "available": False,
            "configured": True,
            "ok": False,
            "found": False,
            "source": "none",
            "error_code": "tool_not_called",
            "error_message": "contact_context tool was not called.",
            "cache_lookup": True,
            "cache_hit": False,
            "mcp_available": True,
            "mcp_called": True,
            "tool_called": False,
        }

    async def fail_if_llm_is_called(*args, **kwargs):
        raise AssertionError("LLM should not be called when effective timezone is missing")

    monkeypatch.setattr(ContactContextResolver, "resolve", fake_resolve_contact_context)
    monkeypatch.setattr(DecisionEngine, "decide", fail_if_llm_is_called)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Dime disponibilidad de Claudia para láser cuerpo entero para mañana por la tarde.",
        contact=Contact(phone="+34999999999"),
        conversation={"last_messages": []},
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "ask_question"
    assert response.data_to_save["timezone_guardrail_blocked"] is True
    assert response.data_to_save["contact_context_resolver_called"] is True
    assert response.data_to_save["contact_context_available"] is False
    assert response.data_to_save["contact_context_source"] == "none"
    assert response.data_to_save["contact_context_error_code"] == "tool_not_called"
    assert response.data_to_save["contact_context_error_message"] == "contact_context tool was not called."


@pytest.mark.asyncio
async def test_runtime_blocks_owner_textual_without_canonical_owner_id(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Sí, hay huecos.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_availability",
                        "arguments": {
                            "tenant_id": "019e4a9a-c85f-72d4-8748-b756073c324c",
                            "date_from": "2026-06-12T15:00:00+02:00",
                            "date_to": "2026-06-12T20:00:00+02:00",
                            "timezone": "Atlantic/Canary",
                            "duration_minutes": 90,
                            "service_id": "019eb05e-5f79-7630-ba89-38e0ec1493a0",
                            "owner_ref": "claudia",
                        },
                        "output": {
                            "ok": False,
                            "available": False,
                            "timezone": "Atlantic/Canary",
                            "slots": [],
                            "error_code": "crm_error",
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=52,
        )

    async def fake_resolve_contact_context(*args, **kwargs):
        return {
            "available": True,
            "configured": True,
            "provider": "n8n_webhook",
            "ok": True,
            "found": True,
            "error_code": None,
            "data": {
                "source": "contact_context",
                "timezone": "Atlantic/Canary",
                "timezone_source": "crm_tenant",
                "business_context": {
                    "timezone": "Atlantic/Canary",
                    "timezone_source": "crm_tenant",
                    "needs_branch_selection": False,
                },
            },
        }

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", fake_resolve_contact_context)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Dime disponibilidad de Claudia para láser cuerpo entero para mañana por la tarde.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [
                "SA: Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
            ],
            "context_messages": [
                {
                    "id": "message-contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto resuelto.",
                    "metadata": {
                        "data_to_save": {
                            "external_context_available": True,
                            "external_context_configured": True,
                            "external_context_timezone": "Atlantic/Canary",
                            "external_context_timezone_source": "contact_context",
                            "external_business_context_timezone": "Atlantic/Canary",
                            "external_business_context_timezone_source": "crm_tenant",
                            "external_business_context_needs_branch_selection": False,
                        }
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "ask_question"
    assert response.needs_human is False
    assert "identificar correctamente a claudia" in response.reply.lower()
    assert response.data_to_save["owner_guardrail_blocked"] is True
    assert response.data_to_save["owner_guardrail_reason"] == "unresolved_owner_reference"
    assert response.data_to_save["mismatched_tool"] == "appointment_availability"


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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
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
async def test_runtime_does_not_short_circuit_agenda_booking_when_appointment_availability_is_available(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert mcp_config is not None and mcp_config.enabled is True
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        return AgentResponse(
            reply="Sí, veo huecos disponibles esta semana.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_availability_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_availability",
                        "arguments": {
                            "service_ref": "maria-laser-axilas",
                            "duration_minutes": 15,
                            "timezone": "Atlantic/Canary",
                        },
                        "output": {"available": True},
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=88,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar láser axilas esta semana. ¿Qué huecos tienes?",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.model == "gpt-4.1-mini"
    assert response.action == "answer_question"
    assert response.data_to_save["mcp_tool_traces"][0]["tool_name"] == "appointment_availability"
    create_calls = [call for call in backend.calls if call[0] == "create_conversation_message"]
    assert len(create_calls) == 2
    outbound_payload = create_calls[-1][1][0]
    assert outbound_payload["metadata"]["mcp_enabled"] is True
    assert outbound_payload["metadata"]["mcp_server_label"] == "tech_investments_mcp"
    assert outbound_payload["metadata"]["mcp_tool_traces"][0]["tool_name"] == "appointment_availability"


@pytest.mark.asyncio
async def test_runtime_resolves_contact_context_even_when_allowed_tools_do_not_list_it(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tech_investments_mcp",
            server_url="https://mcp.tech-investments.net/mcp",
            allowed_tools=["appointment_availability"],
            timeout_seconds=15,
        ),
    )

    class RecordingContactContextResolver:
        def __init__(self) -> None:
            self.calls: list[dict[str, object]] = []

        async def resolve(self, payload, backend_context, mcp_config, recent_contact_context=None):
            self.calls.append(
                {
                    "tenant_id": backend_context.tenant.id if backend_context is not None else None,
                    "tenant_slug": backend_context.tenant.slug if backend_context is not None else None,
                    "contact_phone": getattr(payload.contact, "phone", None),
                    "message_text": payload.message.text,
                    "recent_contact_context": recent_contact_context,
                    "allowed_tools": list(mcp_config.allowed_tools) if mcp_config is not None else [],
                }
            )
            return {
                "available": True,
                "configured": True,
                "tool_type": "contact_context",
                "provider": "n8n_webhook",
                "ok": True,
                "found": True,
                "source": "external_tool:n8n",
                "latency_ms": 12,
                "error_code": None,
                "data": {
                    "source": "external_tool:n8n",
                    "timezone": "Atlantic/Canary",
                    "timezone_source": "crm_tenant",
                    "needs_branch_selection": False,
                    "contact": {
                        "name": "Ana García",
                        "phone": "+34999999999",
                    },
                    "business_context": {
                        "timezone": "Atlantic/Canary",
                        "timezone_source": "crm_tenant",
                        "needs_branch_selection": False,
                    },
                },
                "external_tool_available": True,
                "external_tool_called": True,
            }

    contact_context_resolver = RecordingContactContextResolver()
    seen: dict[str, object] = {}

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        assert contact_context["data"]["timezone_source"] == "crm_tenant"
        _system_prompt, user_prompt = LLMPromptBuilder().build(payload, routing, backend_context, contact_context, mcp_config)
        parsed_prompt = json.loads(user_prompt)
        assert parsed_prompt["temporal_context"]["timezone"] == "Atlantic/Canary"
        assert parsed_prompt["temporal_context"]["timezone_source"] == "crm_tenant"
        assert parsed_prompt["operational_context"]["channel"] == "whatsapp"
        return AgentResponse(
            reply="Sí, hay huecos.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_tool_traces": [],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=88,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        contact_context_resolver=contact_context_resolver,
    )  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999"),
        conversation={"channel": "whatsapp"},
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.model == "gpt-4.1-mini"
    assert response.action == "answer_question"
    assert len(contact_context_resolver.calls) == 1
    assert contact_context_resolver.calls[0]["tenant_id"] == "tenant-1"
    assert contact_context_resolver.calls[0]["tenant_slug"] == "negocio-demo"
    assert contact_context_resolver.calls[0]["contact_phone"] == "+34999999999"
    assert contact_context_resolver.calls[0]["message_text"] == "Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias."
    assert contact_context_resolver.calls[0]["allowed_tools"] == ["appointment_availability"]
    assert response.data_to_save["contact_context_resolver_called"] is True
    assert response.data_to_save["contact_context_available"] is True
    assert response.data_to_save["contact_context_source"] == "external_tool:n8n"
    assert response.data_to_save["contact_context_external_tool_available"] is True
    assert response.data_to_save["contact_context_external_tool_called"] is True
    assert response.data_to_save["effective_timezone"] == "Atlantic/Canary"
    assert response.data_to_save["effective_timezone_source"] == "crm_tenant"
    assert response.data_to_save["technical_fallback_timezone"] == "Europe/Madrid"
    assert response.data_to_save["operational_context"]["effective_timezone"] == "Atlantic/Canary"


class RecordingCatalogLLMClient:
    def __init__(self, content: str) -> None:
        self.content = content
        self.resolve_configuration_calls = 0
        self.calls: list[dict[str, object]] = []

    async def resolve_configuration(self) -> dict[str, str]:
        self.resolve_configuration_calls += 1
        return {
            "llm_default_profile": "openai",
            "openai_base_url": "https://api.openai.test/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
        }

    async def generate_with_mcp(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        mcp_config: McpRemoteConfig,
        configuration: dict[str, str] | None = None,
        previous_response_id: str | None = None,
        tool_choice=None,
        parallel_tool_calls=None,
        single_tool_call: bool = False,
        max_tool_rounds: int | None = None,
    ):
        self.calls.append(
            {
                "provider": provider,
                "system_prompt": system_prompt,
                "user_prompt": user_prompt,
                "allowed_tools": list(mcp_config.allowed_tools),
                "previous_response_id": previous_response_id,
                "tool_choice": tool_choice,
                "parallel_tool_calls": parallel_tool_calls,
                "single_tool_call": single_tool_call,
                "max_tool_rounds": max_tool_rounds,
            }
        )
        return type(
            "LLMResponseResultStub",
            (),
            {
                "provider": "openai",
                "model": "gpt-4.1-mini",
                "content": self.content,
                "response_id": "resp-1",
                "tool_traces": [
                    {
                        "tool_name": "services_search",
                        "status": "completed",
                        "output": {
                            "results": [
                                {
                                    "service_name": "Láser cuerpo entero",
                                    "description": "Tratamiento completo.",
                                }
                            ]
                        },
                    }
                ],
            },
        )()


class RecordingAppointmentAvailabilityLLMClient:
    def __init__(self, responses: list[dict[str, object]] | None = None) -> None:
        self.responses = responses or [
            {
                "content": json.dumps({"reply": "ok", "reason": "ok", "slots": []}, ensure_ascii=False),
                "tool_traces": [],
                "provider": "openai",
                "response_id": "resp-1",
            }
        ]
        self.resolve_configuration_calls = 0
        self.calls: list[dict[str, object]] = []

    async def resolve_configuration(self) -> dict[str, str]:
        self.resolve_configuration_calls += 1
        return {
            "llm_default_profile": "openai",
            "openai_base_url": "https://api.openai.test/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
        }

    async def generate_with_mcp(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        mcp_config: McpRemoteConfig,
        configuration: dict[str, str] | None = None,
        previous_response_id: str | None = None,
        tool_choice=None,
        parallel_tool_calls=None,
        single_tool_call: bool = False,
        max_tool_rounds: int | None = None,
    ):
        response_index = len(self.calls)
        response = self.responses[response_index] if response_index < len(self.responses) else self.responses[-1]
        self.calls.append(
            {
                "provider": provider,
                "system_prompt": system_prompt,
                "user_prompt": user_prompt,
                "allowed_tools": list(mcp_config.allowed_tools),
                "previous_response_id": previous_response_id,
                "tool_choice": tool_choice,
                "parallel_tool_calls": parallel_tool_calls,
                "single_tool_call": single_tool_call,
                "max_tool_rounds": max_tool_rounds,
            }
        )
        return type(
            "LLMResponseResultStub",
            (),
            {
                "provider": response.get("provider", "openai"),
                "model": "gpt-4.1-mini",
                "content": response.get("content", ""),
                "response_id": response.get("response_id", f"resp-{response_index + 1}"),
                "tool_traces": response.get("tool_traces", []),
                "raw_payload": response.get("raw_payload"),
            },
        )()


def build_catalog_shadow_trace() -> OrchestrationTrace:
    trace = OrchestrationTrace(
        tenant_id="tenant-1",
        conversation_id="conversation-1",
        external_conversation_id="conversation-1",
        inbound_message="Quiero información sobre láser cuerpo entero",
    )
    trace.add_step(
        step_type="llm_intent_planning",
        input_context_keys=["current_message", "recent_messages"],
        enabled_tools=[],
        output={
            "schema_version": "1.0",
            "domain": "catalog",
            "intent": "ask_product_or_service_info",
            "action_candidate": "search_catalog",
            "confidence": 0.92,
            "entities": {
                "service_name": "láser cuerpo entero",
                "query": "láser cuerpo entero",
            },
            "context_request": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": True,
                "include_inventory_context": False,
                "include_appointment_context": False,
                "include_existing_appointments": False,
                "include_offered_slots": False,
                "include_service_catalog": True,
            },
            "tool_request": {
                "lookup_tools": ["services_search"],
                "write_tools": [],
                "blocked_tools": [],
            },
            "risk_flags": {
                "ambiguous_reference": False,
                "missing_required_data": False,
                "low_confidence": False,
                "needs_human_review": False,
                "explicit_booking_intent": False,
                "explicit_reschedule_intent": False,
                "explicit_cancel_intent": False,
            },
            "clarification": {
                "needed": False,
                "question": None,
                "missing_fields": [],
            },
            "reason": "catalog service information request",
        },
    )
    trace.add_step(
        step_type="sa_context_policy",
        input_context_keys=["planning_result", "context_request", "tool_request"],
        enabled_tools=["services_search"],
        output={
            "context_plan": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": True,
                "include_inventory_context": False,
                "include_appointment_context": False,
                "include_existing_appointments": False,
                "include_offered_slots": False,
                "include_service_catalog": True,
            },
            "tool_policy": {
                "lookup_tools_enabled": ["services_search"],
                "write_tools_requested": [],
                "write_tools_enabled": [],
                "write_tools_blocked": [],
                "reason": "lookup_enabled_write_tools_blocked_by_default",
            },
        },
    )
    return trace


def build_appointment_shadow_trace() -> OrchestrationTrace:
    trace = OrchestrationTrace(
        tenant_id="tenant-1",
        conversation_id="conversation-1",
        external_conversation_id="conversation-1",
        inbound_message="Quiero reservar para mañana por la tarde",
    )
    trace.add_step(
        step_type="llm_intent_planning",
        input_context_keys=["current_message", "recent_messages"],
        enabled_tools=[],
        output={
            "schema_version": "1.0",
            "domain": "appointment",
            "intent": "request_availability",
            "action_candidate": "get_availability",
            "confidence": 0.91,
            "entities": {
                "service_name": "láser cuerpo entero",
                "date": "tomorrow",
                "time_of_day": "afternoon",
            },
            "context_request": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": False,
                "include_inventory_context": False,
                "include_appointment_context": True,
                "include_existing_appointments": False,
                "include_offered_slots": False,
                "include_service_catalog": False,
            },
            "tool_request": {
                "lookup_tools": ["services_search", "appointment_availability"],
                "write_tools": [],
                "blocked_tools": [],
            },
            "risk_flags": {
                "ambiguous_reference": False,
                "missing_required_data": False,
                "low_confidence": False,
                "needs_human_review": False,
                "explicit_booking_intent": False,
                "explicit_reschedule_intent": False,
                "explicit_cancel_intent": False,
            },
            "clarification": {
                "needed": False,
                "question": None,
                "missing_fields": [],
            },
            "reason": "appointment availability request",
        },
    )
    trace.add_step(
        step_type="sa_context_policy",
        input_context_keys=["planning_result", "context_request", "tool_request"],
        enabled_tools=["services_search", "appointment_availability"],
        output={
            "context_plan": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": False,
                "include_inventory_context": False,
                "include_appointment_context": True,
                "include_existing_appointments": False,
                "include_offered_slots": False,
                "include_service_catalog": False,
            },
            "tool_policy": {
                "lookup_tools_enabled": ["services_search", "appointment_availability"],
                "write_tools_requested": [],
                "write_tools_enabled": [],
                "write_tools_blocked": [],
                "reason": "lookup_enabled_write_tools_blocked_by_default",
            },
        },
    )
    return trace


def build_slot_selection_shadow_trace() -> OrchestrationTrace:
    trace = OrchestrationTrace(
        tenant_id="tenant-1",
        conversation_id="conversation-1",
        external_conversation_id="conversation-1",
        inbound_message="Prefiero el de las 16:30",
    )
    trace.add_step(
        step_type="llm_intent_planning",
        input_context_keys=["current_message", "recent_messages"],
        enabled_tools=[],
        output={
            "schema_version": "1.0",
            "domain": "appointment",
            "intent": "select_offered_slot",
            "action_candidate": "prepare_booking_confirmation",
            "confidence": 0.97,
            "entities": {
                "service_name": "láser cuerpo entero",
                "date": "tomorrow",
                "time": "16:30",
            },
            "context_request": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": False,
                "include_inventory_context": False,
                "include_appointment_context": True,
                "include_existing_appointments": False,
                "include_offered_slots": True,
                "include_service_catalog": False,
            },
            "tool_request": {
                "lookup_tools": [],
                "write_tools": [],
                "blocked_tools": [],
            },
            "risk_flags": {
                "ambiguous_reference": False,
                "missing_required_data": False,
                "low_confidence": False,
                "needs_human_review": False,
                "explicit_booking_intent": True,
                "explicit_reschedule_intent": False,
                "explicit_cancel_intent": False,
            },
            "clarification": {
                "needed": False,
                "question": None,
                "missing_fields": [],
            },
            "reason": "slot selection follow-up",
        },
    )
    trace.add_step(
        step_type="sa_context_policy",
        input_context_keys=["planning_result", "context_request", "tool_request"],
        enabled_tools=["appointment_availability"],
        output={
            "context_plan": {
                "include_conversation_history": True,
                "conversation_history_level": "recent",
                "include_customer_context": "basic",
                "include_catalog_context": False,
                "include_inventory_context": False,
                "include_appointment_context": True,
                "include_existing_appointments": False,
                "include_offered_slots": True,
                "include_service_catalog": False,
            },
            "tool_policy": {
                "lookup_tools_enabled": [],
                "write_tools_requested": [],
                "write_tools_enabled": [],
                "write_tools_blocked": [],
                "reason": "lookup_enabled_write_tools_blocked_by_default",
            },
        },
    )
    return trace


def build_offered_slots_context_message() -> dict[str, object]:
    offered_slots = [
        {
            "start": "2026-06-16T16:00:00+01:00",
            "end": "2026-06-16T17:30:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner_id": "owner-claudia-uuid",
            "owner_name": "Claudia Estética",
            "owner_email": "claudia@example.com",
            "owner_preferred": True,
            "owner": {
                "id": "owner-claudia-uuid",
                "name": "Claudia Estética",
                "email": "claudia@example.com",
                "preferred": True,
            },
            "slot_label": "16:00",
            "display_time": "16:00",
        },
        {
            "start": "2026-06-16T16:30:00+01:00",
            "end": "2026-06-16T18:00:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner_id": "owner-maria-uuid",
            "owner_name": "María Gutiérrez",
            "owner_email": "maria@example.com",
            "owner_preferred": False,
            "owner": {
                "id": "owner-maria-uuid",
                "name": "María Gutiérrez",
                "email": "maria@example.com",
                "preferred": False,
            },
            "slot_label": "16:30",
            "display_time": "16:30",
        },
        {
            "start": "2026-06-16T17:00:00+01:00",
            "end": "2026-06-16T18:30:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner_id": "owner-claudia-uuid",
            "owner_name": "Claudia Estética",
            "owner_email": "claudia@example.com",
            "owner_preferred": True,
            "owner": {
                "id": "owner-claudia-uuid",
                "name": "Claudia Estética",
                "email": "claudia@example.com",
                "preferred": True,
            },
            "slot_label": "17:00",
            "display_time": "17:00",
        },
    ]

    return {
        "id": "message-availability-1",
        "direction": "outbound",
        "role": "assistant",
        "message_type": "text",
        "body": "Para el lunes 16 de junio por la tarde hay disponibilidad a las 16:00, 16:30 y 17:00.",
        "metadata": {
            "data_to_save": {
                "new_llm_orchestration_offered_slots": offered_slots,
                "new_llm_orchestration_offered_slots_count": len(offered_slots),
            }
        },
    }


def build_availability_summary_raw_payload(offered_slots: list[dict[str, object]], reply: str) -> dict[str, object]:
    return {
        "data_to_save": {
            "new_llm_orchestration_appointment_availability_attempted": True,
            "new_llm_orchestration_appointment_availability_ok": True,
            "new_llm_orchestration_appointment_availability_used": True,
            "new_llm_orchestration_appointment_availability_trace": {
                "attempted": True,
                "ok": True,
                "reply": reply,
                "offered_slots": offered_slots,
            },
            "new_llm_orchestration_offered_slots": offered_slots,
            "new_llm_orchestration_offered_slots_count": len(offered_slots),
        }
    }


@pytest.mark.asyncio
async def test_catalog_execution_service_filters_allowed_tools_and_uses_services_search_only():
    fake_llm = RecordingCatalogLLMClient(
        content=json.dumps(
            {
                "reply": "Tengo láser cuerpo entero disponible. Puedo darte detalles o ayudarte a reservar.",
                "reason": "service match",
                "items": [
                    {
                        "service_name": "Láser cuerpo entero",
                        "description": "Tratamiento completo.",
                    }
                ],
            },
            ensure_ascii=False,
        )
    )
    settings = Settings()
    settings.new_llm_orchestration_enabled = True
    settings.new_llm_orchestration_catalog_execution_enabled = True
    service = CatalogExecutionService(settings=settings, llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero información sobre láser cuerpo entero",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        build_catalog_shadow_trace(),
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert isinstance(outcome, CatalogExecutionOutcome)
    assert outcome.ok is True
    assert outcome.reply == "Tengo láser cuerpo entero disponible. Puedo darte detalles o ayudarte a reservar."
    assert outcome.mcp_allowed_tools == ["services_search"]
    assert outcome.mcp_tool_traces[0]["tool_name"] == "services_search"
    assert outcome.response_payload["reply"] == "Tengo láser cuerpo entero disponible. Puedo darte detalles o ayudarte a reservar."
    assert fake_llm.resolve_configuration_calls == 1
    assert fake_llm.calls[0]["allowed_tools"] == ["services_search"]
    assert fake_llm.calls[0]["provider"] == "openai"
    assert fake_llm.calls[0]["tool_choice"] is None
    assert fake_llm.calls[0]["parallel_tool_calls"] is False
    assert fake_llm.calls[0]["single_tool_call"] is True
    assert fake_llm.calls[0]["previous_response_id"] is None
    assert fake_llm.calls[0]["max_tool_rounds"] is None
    assert "Call the available MCP tool exactly once." in fake_llm.calls[0]["system_prompt"]
    assert "do not call it again" in fake_llm.calls[0]["system_prompt"].lower()
    assert outcome.bounded_single_tool_call is True


@pytest.mark.asyncio
async def test_catalog_execution_service_declines_non_catalog_planning_without_calling_llm():
    fake_llm = RecordingCatalogLLMClient(content=json.dumps({"reply": "No debería llamarse."}, ensure_ascii=False))
    settings = Settings()
    settings.new_llm_orchestration_enabled = True
    settings.new_llm_orchestration_catalog_execution_enabled = True
    service = CatalogExecutionService(settings=settings, llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar para mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        build_appointment_shadow_trace(),
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert outcome.ok is False
    assert outcome.attempted is False
    assert outcome.fallback_reason == "planning_domain_not_catalog"
    assert fake_llm.resolve_configuration_calls == 0
    assert fake_llm.calls == []


@pytest.mark.asyncio
async def test_appointment_availability_execution_service_filters_tools_and_uses_verified_traces():
    fake_llm = RecordingAppointmentAvailabilityLLMClient(
        responses=[
            {
                "content": json.dumps(
                    {
                        "reply": "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
                        "reason": "availability found",
                        "slots": [
                            {"start": "2026-06-16T16:00:00+01:00", "end": "2026-06-16T17:30:00+01:00"},
                            {"start": "2026-06-16T16:30:00+01:00", "end": "2026-06-16T18:00:00+01:00"},
                            {"start": "2026-06-16T17:00:00+01:00", "end": "2026-06-16T18:30:00+01:00"},
                        ],
                    },
                    ensure_ascii=False,
                ),
                "tool_traces": [
                    {
                        "tool_name": "services_search",
                        "status": "completed",
                        "output": {"results": [{"service_id": "service-uuid", "service_name": "Láser cuerpo entero"}]},
                    },
                    {
                        "tool_name": "appointment_availability",
                        "status": "completed",
                        "output": json.dumps(
                            {
                                "available": True,
                                "slots": [
                                    {
                                        "start": "2026-06-16T16:00:00+01:00",
                                        "end": "2026-06-16T17:30:00+01:00",
                                        "timezone": "Atlantic/Canary",
                                        "duration_minutes": 90,
                                        "owner": {
                                            "id": "owner-claudia-uuid",
                                            "name": "Claudia Estética",
                                            "email": "claudia@example.com",
                                            "ref": "claudia-ref",
                                            "preferred": True,
                                        },
                                    },
                                    {
                                        "start": "2026-06-16T16:30:00+01:00",
                                        "end": "2026-06-16T18:00:00+01:00",
                                        "timezone": "Atlantic/Canary",
                                        "duration_minutes": 90,
                                        "owner_id": "owner-maria-uuid",
                                        "owner_name": "María Gutiérrez",
                                        "owner_email": "maria@example.com",
                                        "owner_preferred": False,
                                        "owner": {
                                            "id": "owner-maria-uuid",
                                            "name": "María Gutiérrez",
                                            "email": "maria@example.com",
                                            "preferred": False,
                                        },
                                    },
                                    {
                                        "start": "2026-06-16T17:00:00+01:00",
                                        "end": "2026-06-16T18:30:00+01:00",
                                        "timezone": "Atlantic/Canary",
                                        "duration_minutes": 90,
                                        "owner_id": "owner-claudia-uuid",
                                        "owner_name": "Claudia Estética",
                                        "owner_email": "claudia@example.com",
                                        "owner_preferred": True,
                                        "owner": {
                                            "id": "owner-claudia-uuid",
                                            "name": "Claudia Estética",
                                            "email": "claudia@example.com",
                                            "preferred": True,
                                        },
                                    },
                                ],
                            },
                            ensure_ascii=False,
                        ),
                    }
                ],
                "response_id": "resp-sequence",
            },
        ]
    )
    settings = Settings()
    service = AppointmentAvailabilityExecutionService(settings=settings, llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar láser cuerpo entero mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        build_appointment_shadow_trace(),
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
            timeout_seconds=15,
        ),
    )

    assert isinstance(outcome, AppointmentAvailabilityExecutionOutcome)
    assert outcome.ok is True
    assert outcome.reply == "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?"
    assert outcome.mcp_allowed_tools == ["services_search", "appointment_availability"]
    assert [trace["tool_name"] for trace in outcome.mcp_tool_traces] == ["services_search", "appointment_availability"]
    assert outcome.response_payload["reason"] == "availability found"
    assert len(outcome.offered_slots) == 3
    assert outcome.offered_slots[0]["owner_name"] == "Claudia Estética"
    assert outcome.offered_slots[0]["owner_email"] == "claudia@example.com"
    assert outcome.offered_slots[0]["owner_ref"] == "claudia-ref"
    assert outcome.offered_slots[0]["owner"]["id"] == "owner-claudia-uuid"
    assert outcome.offered_slots[0]["owner"]["preferred"] is True
    assert fake_llm.resolve_configuration_calls == 1
    assert len(fake_llm.calls) == 1
    assert fake_llm.calls[0]["allowed_tools"] == ["services_search", "appointment_availability"]
    assert fake_llm.calls[0]["previous_response_id"] is None
    assert fake_llm.calls[0]["tool_choice"] is None
    assert fake_llm.calls[0]["parallel_tool_calls"] is False
    assert fake_llm.calls[0]["single_tool_call"] is False
    assert fake_llm.calls[0]["max_tool_rounds"] is None
    assert "services_search una sola vez" in fake_llm.calls[0]["system_prompt"]
    assert "appointment_availability una sola vez" in fake_llm.calls[0]["system_prompt"]
    assert outcome.bounded_single_tool_call is False


@pytest.mark.asyncio
async def test_runtime_recovers_offered_slots_from_backend_summary_context_and_selects_slot(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = True
    backend.settings.new_llm_orchestration_slot_selection_enabled = True

    offered_slots = [
        {
            "start": "2026-06-16T16:00:00+01:00",
            "end": "2026-06-16T17:30:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner_id": "owner-claudia-uuid",
            "owner_name": "Claudia Estética",
            "owner_email": "claudia@example.com",
            "owner_ref": "claudia-ref",
            "owner_preferred": True,
            "owner": {
                "id": "owner-claudia-uuid",
                "name": "Claudia Estética",
                "email": "claudia@example.com",
                "preferred": True,
                "ref": "claudia-ref",
            },
            "slot_label": "16:00",
            "display_time": "16:00",
        },
        {
            "start": "2026-06-16T16:30:00+01:00",
            "end": "2026-06-16T18:00:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner_id": "owner-maria-uuid",
            "owner_name": "María Gutiérrez",
            "owner_email": "maria@example.com",
            "owner_preferred": False,
            "owner": {
                "id": "owner-maria-uuid",
                "name": "María Gutiérrez",
                "email": "maria@example.com",
                "preferred": False,
            },
            "slot_label": "16:30",
            "display_time": "16:30",
        },
    ]
    await backend.create_conversation_message(
        BackendConversationMessagePayload(
            conversation_id="conversation-1",
            direction="outbound",
            role="assistant",
            message_type="text",
            body="Para láser cuerpo entero mañana por la tarde tengo 16:00 y 16:30. ¿Cuál prefieres?",
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=62,
            intent="request_availability",
            score=91,
            action="answer_question",
            needs_human=False,
            raw_payload=build_availability_summary_raw_payload(offered_slots, "Para láser cuerpo entero mañana por la tarde tengo 16:00 y 16:30. ¿Cuál prefieres?"),
            metadata={
                "data_to_save": build_availability_summary_raw_payload(offered_slots, "Para láser cuerpo entero mañana por la tarde tengo 16:00 y 16:30. ¿Cuál prefieres?")[
                    "data_to_save"
                ],
            },
        )
    )

    async def fake_second_shadow_execute(self, payload, routing, backend_context, contact_context):
        trace = build_slot_selection_shadow_trace()
        trace.steps[0].output["entities"]["time"] = "16:00"
        trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
        trace.steps[0].output["entities"]["selected_slot_index"] = None
        trace.steps[0].output["entities"]["owner_name"] = None
        trace.steps[0].output["entities"]["owner_id"] = None
        return trace

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]

    monkeypatch.setattr(ShadowPlanningService, "execute", fake_second_shadow_execute)
    second_response = await runtime.respond(
        AgentRequest(
            tenant_id="tenant-1",
            entrypoint_ref="abc123",
            message="Elijo las 16:00",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "slot-owner-flow-001"},
        )
    )

    assert second_response.intent == "select_offered_slot"
    assert second_response.data_to_save["new_llm_orchestration_slot_selection_attempted"] is True
    assert second_response.data_to_save["new_llm_orchestration_slot_selection_ok"] is True
    assert second_response.data_to_save["new_llm_orchestration_selected_slot"]["start"] == "2026-06-16T16:00:00+01:00"
    assert second_response.data_to_save["new_llm_orchestration_selected_slot"]["owner"]["id"] == "owner-claudia-uuid"
    assert second_response.data_to_save["new_llm_orchestration_selected_slot"]["owner"]["preferred"] is True
    assert second_response.data_to_save["new_llm_orchestration_slot_selection_trace"]["appointment_context"]["offered_slots"][0]["owner"]["id"] == "owner-claudia-uuid"
    assert second_response.data_to_save["new_llm_orchestration_slot_selection_trace"]["offered_slots"][0]["owner"]["name"] == "Claudia Estética"
    assert any(
        call[0] == "get_conversation_summary_context"
        and call[1][0] == "conversation-1"
        and call[1][2] == "tenant-1"
        and call[1][3] == "slot-owner-flow-001"
        and call[1][4] == "+34999999999"
        for call in backend.calls
    )


@pytest.mark.asyncio
async def test_appointment_availability_execution_service_declines_when_services_search_trace_missing():
    fake_llm = RecordingAppointmentAvailabilityLLMClient(
        responses=[
            {
                "content": json.dumps(
                    {
                        "reply": "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
                        "reason": "availability found",
                        "slots": [],
                    },
                    ensure_ascii=False,
                ),
                "tool_traces": [
                    {
                        "tool_name": "appointment_availability",
                        "status": "completed",
                        "output": {"available": True, "slots": []},
                    }
                ],
                "response_id": "resp-sequence",
            }
        ]
    )
    service = AppointmentAvailabilityExecutionService(settings=Settings(), llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar láser cuerpo entero mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        build_appointment_shadow_trace(),
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert outcome.ok is False
    assert outcome.attempted is True
    assert outcome.fallback_reason == "services_search_trace_missing"
    assert len(fake_llm.calls) == 1


@pytest.mark.asyncio
async def test_appointment_availability_execution_service_declines_when_appointment_availability_trace_missing():
    fake_llm = RecordingAppointmentAvailabilityLLMClient(
        responses=[
            {
                "content": json.dumps({"reply": "Servicio resuelto.", "reason": "service search ok", "slots": []}, ensure_ascii=False),
                "tool_traces": [
                    {
                        "tool_name": "services_search",
                        "status": "completed",
                        "output": {"results": [{"service_id": "service-uuid", "service_name": "Láser cuerpo entero"}]},
                    }
                ],
                "response_id": "resp-sequence",
            },
        ]
    )
    service = AppointmentAvailabilityExecutionService(settings=Settings(), llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar láser cuerpo entero mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        build_appointment_shadow_trace(),
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert outcome.ok is False
    assert outcome.attempted is True
    assert outcome.fallback_reason == "appointment_availability_trace_missing"
    assert len(fake_llm.calls) == 1


@pytest.mark.asyncio
async def test_appointment_availability_execution_service_declines_when_write_tools_requested():
    fake_llm = RecordingAppointmentAvailabilityLLMClient(
        responses=[
            {
                "content": json.dumps({"reply": "No debería llamarse."}, ensure_ascii=False),
                "tool_traces": [],
                "response_id": "resp-search",
            }
        ]
    )
    trace = build_appointment_shadow_trace()
    trace.steps[0].output["tool_request"]["write_tools"] = ["appointment_confirm"]
    service = AppointmentAvailabilityExecutionService(settings=Settings(), llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar láser cuerpo entero mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert outcome.ok is False
    assert outcome.attempted is False
    assert outcome.fallback_reason == "write_tools_requested"
    assert fake_llm.calls == []


@pytest.mark.asyncio
async def test_appointment_availability_execution_service_declines_when_clarification_is_needed():
    fake_llm = RecordingAppointmentAvailabilityLLMClient(
        responses=[
            {
                "content": json.dumps({"reply": "No debería llamarse."}, ensure_ascii=False),
                "tool_traces": [],
                "response_id": "resp-search",
            }
        ]
    )
    trace = build_appointment_shadow_trace()
    trace.steps[0].output["clarification"]["needed"] = True
    service = AppointmentAvailabilityExecutionService(settings=Settings(), llm_client=fake_llm)  # type: ignore[arg-type]

    outcome = await service.execute(
        AgentRequest(
            tenant_id="tenant-1",
            message="Quiero reservar láser cuerpo entero mañana por la tarde",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "conversation-1"},
        ),
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
    )

    assert outcome.ok is False
    assert outcome.attempted is False
    assert outcome.fallback_reason == "clarification_requested"
    assert fake_llm.calls == []


@pytest.mark.asyncio
async def test_slot_selection_execution_service_selects_exact_time_from_structured_entities():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Prefiero el de las 16:30",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [build_offered_slots_context_message()],
        },
    )

    trace.steps[0].output["entities"]["time"] = "16:30"
    trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
    trace.steps[0].output["entities"]["selected_slot_index"] = None

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert isinstance(outcome, SlotSelectionExecutionOutcome)
    assert outcome.ok is True
    assert outcome.selected_slot is not None
    assert outcome.selected_slot["start"] == "2026-06-16T16:30:00+01:00"
    assert outcome.selected_slot["owner_name"] == "María Gutiérrez"
    assert outcome.selected_slot_match_count == 1
    assert outcome.selected_slot_ambiguous is False
    assert outcome.reply == "Perfecto, tengo el martes 16/06 a las 16:30 con María Gutiérrez para Láser cuerpo entero. ¿Confirmo la cita?"


@pytest.mark.asyncio
async def test_slot_selection_execution_service_marks_time_only_selection_as_ambiguous_when_owner_collides():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    context_message = build_offered_slots_context_message()
    offered_slots = context_message["metadata"]["data_to_save"]["new_llm_orchestration_offered_slots"]
    offered_slots[1]["start"] = "2026-06-16T16:00:00+01:00"
    offered_slots[1]["end"] = "2026-06-16T17:30:00+01:00"
    offered_slots[1]["display_time"] = "16:00"
    offered_slots[1]["slot_label"] = "16:00"

    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Elijo las 16:00",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [context_message],
        },
    )

    trace.steps[0].output["entities"]["time"] = "16:00"
    trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
    trace.steps[0].output["entities"]["selected_slot_index"] = None
    trace.steps[0].output["entities"]["owner_name"] = None
    trace.steps[0].output["entities"]["owner_id"] = None

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.selected_slot is None
    assert outcome.selected_slot_ambiguous is True
    assert outcome.selected_slot_match_count == 2
    assert "varios horarios posibles" in outcome.reply.lower()
    assert "claudia" in outcome.reply.lower()
    assert "maría" in outcome.reply.lower() or "maria" in outcome.reply.lower()


@pytest.mark.asyncio
async def test_slot_selection_execution_service_selects_time_when_owner_is_structured():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    context_message = build_offered_slots_context_message()
    offered_slots = context_message["metadata"]["data_to_save"]["new_llm_orchestration_offered_slots"]
    offered_slots[1]["start"] = "2026-06-16T16:00:00+01:00"
    offered_slots[1]["end"] = "2026-06-16T17:30:00+01:00"
    offered_slots[1]["display_time"] = "16:00"
    offered_slots[1]["slot_label"] = "16:00"

    payload = AgentRequest(
        tenant_id="tenant-1",
        message="El de María a las 16:00",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [context_message],
        },
    )

    trace.steps[0].output["entities"]["time"] = "16:00"
    trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
    trace.steps[0].output["entities"]["selected_slot_index"] = None
    trace.steps[0].output["entities"]["owner_name"] = "María"
    trace.steps[0].output["entities"]["owner_id"] = None

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.selected_slot is not None
    assert outcome.selected_slot["start"] == "2026-06-16T16:00:00+01:00"
    assert outcome.selected_slot["owner_name"] == "María Gutiérrez"
    assert outcome.selected_slot["owner"]["id"] == "owner-maria-uuid"
    assert outcome.selected_slot["owner"]["email"] == "maria@example.com"
    assert outcome.selected_slot["owner"]["preferred"] is False
    assert outcome.selection_mode == "exact_time_with_owner"
    assert "María Gutiérrez" in outcome.reply


@pytest.mark.asyncio
async def test_slot_selection_execution_service_selects_first_slot_by_index():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="el primero",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [build_offered_slots_context_message()],
        },
    )

    trace.steps[0].output["entities"]["time"] = None
    trace.steps[0].output["entities"]["slot_reference"] = None
    trace.steps[0].output["entities"]["selected_slot_index"] = 0

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.selected_slot is not None
    assert outcome.selected_slot["start"] == "2026-06-16T16:00:00+01:00"
    assert outcome.selected_slot["owner_name"] == "Claudia Estética"
    assert outcome.selection_mode == "selected_slot_index"


@pytest.mark.asyncio
async def test_slot_selection_execution_service_selects_last_slot_by_reference():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="el último",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [build_offered_slots_context_message()],
        },
    )

    trace.steps[0].output["entities"]["time"] = None
    trace.steps[0].output["entities"]["selected_slot_index"] = None
    trace.steps[0].output["entities"]["slot_reference"] = "last"

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.selected_slot is not None
    assert outcome.selected_slot["start"] == "2026-06-16T17:00:00+01:00"
    assert outcome.selected_slot["owner_name"] == "Claudia Estética"
    assert outcome.selection_mode == "slot_reference_last"


@pytest.mark.asyncio
async def test_slot_selection_execution_service_does_not_parse_free_text_without_structured_entities():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Prefiero el de las 16:30",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [build_offered_slots_context_message()],
        },
    )

    trace.steps[0].output["entities"]["time"] = None
    trace.steps[0].output["entities"]["selected_slot_index"] = None
    trace.steps[0].output["entities"]["slot_reference"] = None

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.selected_slot is None
    assert outcome.fallback_reason == "selection_not_structured"
    assert "16:30" not in outcome.reply
    assert "confirmo" not in outcome.reply.lower()


@pytest.mark.asyncio
async def test_slot_selection_execution_service_falls_back_when_no_offered_slots_exist():
    service = SlotSelectionExecutionService(settings=Settings())  # type: ignore[arg-type]
    trace = build_slot_selection_shadow_trace()
    payload = AgentRequest(
        tenant_id="tenant-1",
        message="Prefiero el de las 16:30",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
            "context_messages": [],
        },
    )

    trace.steps[0].output["entities"]["time"] = "16:30"
    trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
    trace.steps[0].output["entities"]["selected_slot_index"] = None

    outcome = await service.execute(
        payload,
        RoutingContext(tenant_id="tenant-1", tenant_slug="negocio-demo"),
        build_context(),
        None,
        trace,
        McpRemoteConfig(enabled=True, server_label="tenant_main_mcp", server_url="https://mcp.example.test", allowed_tools=["appointment_availability"], timeout_seconds=15),
    )

    assert outcome.ok is True
    assert outcome.fallback_reason == "offered_slots_missing"
    assert "buscar disponibilidad" in outcome.reply.lower()


@pytest.mark.asyncio
async def test_runtime_applies_catalog_execution_slice_when_shadow_planning_is_catalog(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.tenant_context.tenant.timezone = "Atlantic/Canary"
    backend.tenant_context.tenant.timezone_source = "crm_tenant"
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = True
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = False

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_catalog_shadow_trace()

    async def fake_catalog_execute(self, payload, routing, backend_context, contact_context, trace, mcp_config, previous_response_id=None):
        return CatalogExecutionOutcome(
            attempted=True,
            ok=True,
            reply="Tengo láser cuerpo entero disponible. ¿Quieres que te dé más detalles?",
            provider="openai",
            model="gpt-4.1-mini",
            response_id="resp-1",
            latency_ms=54,
            planning={
                "domain": "catalog",
                "intent": "ask_product_or_service_info",
            },
            context_plan={
                "include_catalog_context": True,
            },
            tool_policy={
                "lookup_tools_enabled": ["services_search"],
                "write_tools_enabled": [],
            },
            bounded_single_tool_call=True,
            mcp_allowed_tools=["services_search"],
            mcp_tool_traces=[
                {
                    "tool_name": "services_search",
                    "status": "completed",
                }
            ],
            response_payload={
                "reply": "Tengo láser cuerpo entero disponible. ¿Quieres que te dé más detalles?",
            },
            trace_id="trace-1",
        )

    async def fail_if_called(*args, **kwargs):
        raise AssertionError("non-applicable slices should not run for catalog planning")

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", fake_catalog_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(SlotSelectionExecutionService, "execute", assert_slice_not_called)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero información sobre láser cuerpo entero",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.reply == "Tengo láser cuerpo entero disponible. ¿Quieres que te dé más detalles?"
    assert response.intent == "ask_product_or_service_info"
    assert response.data_to_save["new_llm_orchestration_shadow_enabled"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_ok"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_used"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_trace"]["ok"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_trace"]["bounded_single_tool_call"] is True
    assert response.data_to_save["new_llm_orchestration_trace"]["steps"][0]["step_type"] == "llm_intent_planning"


@pytest.mark.asyncio
async def test_runtime_falls_back_to_legacy_when_catalog_slice_fails_technically(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = True

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Respuesta legacy tras fallo técnico del slice.",
            intent="open_question",
            score=0.88,
            action="answer_question",
            needs_human=False,
            data_to_save={"topic": "catalog"},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=30,
        )

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_catalog_shadow_trace()

    async def fail_catalog_execute(*args, **kwargs):
        raise RuntimeError("catalog execution failed")

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", fail_catalog_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(SlotSelectionExecutionService, "execute", assert_slice_not_called)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero información sobre láser cuerpo entero",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.reply == "Respuesta legacy tras fallo técnico del slice."
    assert response.intent == "open_question"
    assert response.data_to_save["new_llm_orchestration_shadow_enabled"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_applies"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_catalog_execution_error"] == "RuntimeError: catalog execution failed"
    assert response.data_to_save["new_llm_orchestration_catalog_execution_fallback_reason"] == "catalog_execution_technical_error"


@pytest.mark.asyncio
async def test_runtime_does_not_apply_appointment_availability_slice_when_flag_is_disabled(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.tenant_context.tenant.timezone = "Atlantic/Canary"
    backend.tenant_context.tenant.timezone_source = "crm_tenant"
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Respuesta previa del flujo actual.",
            intent="open_question",
            score=0.88,
            action="answer_question",
            needs_human=False,
            data_to_save={"topic": "agenda"},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=30,
        )

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_appointment_shadow_trace()

    async def fail_if_called(*args, **kwargs):
        raise AssertionError("appointment availability slice should not run when feature flag is disabled")

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(SlotSelectionExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar láser cuerpo entero mañana por la tarde",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.reply == "Respuesta previa del flujo actual."
    assert response.data_to_save["new_llm_orchestration_shadow_enabled"] is True
    assert "new_llm_orchestration_appointment_availability_attempted" not in response.data_to_save
    assert "new_llm_orchestration_appointment_availability_trace" not in response.data_to_save


@pytest.mark.asyncio
async def test_runtime_applies_appointment_availability_slice_when_shadow_planning_is_appointment(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = True
    backend.settings.new_llm_orchestration_slot_selection_enabled = False

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Respuesta previa del flujo actual.",
            intent="open_question",
            score=0.88,
            action="answer_question",
            needs_human=False,
            data_to_save={"topic": "agenda"},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=30,
        )

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_appointment_shadow_trace()

    async def fake_appointment_execute(self, payload, routing, backend_context, contact_context, trace, mcp_config, previous_response_id=None):
        return AppointmentAvailabilityExecutionOutcome(
            attempted=True,
            ok=True,
            reply="Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
            provider="openai",
            model="gpt-4.1-mini",
            response_id="resp-2",
            latency_ms=61,
            planning={
                "domain": "appointment",
                "intent": "request_availability",
            },
            context_plan={
                "include_appointment_context": True,
            },
            tool_policy={
                "lookup_tools_enabled": ["services_search", "appointment_availability"],
                "write_tools_enabled": [],
            },
            bounded_single_tool_call=True,
            mcp_allowed_tools=["services_search", "appointment_availability"],
                offered_slots=[
                    {
                        "start": "2026-06-16T16:00:00+01:00",
                        "end": "2026-06-16T17:30:00+01:00",
                        "timezone": "Atlantic/Canary",
                        "service_id": "service-uuid",
                        "service_name": "Láser cuerpo entero",
                        "owner_id": "owner-claudia-uuid",
                        "owner_name": "Claudia Estética",
                        "owner_email": "claudia@example.com",
                        "owner_ref": "claudia-ref",
                        "owner_preferred": True,
                        "owner": {
                            "id": "owner-claudia-uuid",
                            "name": "Claudia Estética",
                            "email": "claudia@example.com",
                            "preferred": True,
                        },
                    },
                    {
                        "start": "2026-06-16T16:30:00+01:00",
                        "end": "2026-06-16T18:00:00+01:00",
                        "timezone": "Atlantic/Canary",
                        "service_id": "service-uuid",
                        "service_name": "Láser cuerpo entero",
                        "owner_id": "owner-maria-uuid",
                        "owner_name": "María Gutiérrez",
                        "owner_email": "maria@example.com",
                        "owner_preferred": False,
                        "owner": {
                            "id": "owner-maria-uuid",
                            "name": "María Gutiérrez",
                            "email": "maria@example.com",
                            "preferred": False,
                        },
                    },
                ],
            mcp_tool_traces=[
                {"tool_name": "services_search", "status": "completed"},
                {"tool_name": "appointment_availability", "status": "completed"},
            ],
            response_payload={
                "reply": "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
                "reason": "availability found",
            },
            trace_id="trace-appointment",
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", fake_appointment_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(SlotSelectionExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar láser cuerpo entero mañana por la tarde",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.reply == "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?"
    assert response.data_to_save["new_llm_orchestration_appointment_availability_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_ok"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_used"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["ok"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["mcp_allowed_tools"] == [
        "services_search",
        "appointment_availability",
    ]
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["bounded_single_tool_call"] is True
    assert response.data_to_save["new_llm_orchestration_offered_slots_count"] == 2
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["start"] == "2026-06-16T16:00:00+01:00"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner_name"] == "Claudia Estética"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner_email"] == "claudia@example.com"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner_ref"] == "claudia-ref"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner"]["id"] == "owner-claudia-uuid"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner"]["name"] == "Claudia Estética"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner"]["email"] == "claudia@example.com"
    assert response.data_to_save["new_llm_orchestration_offered_slots"][0]["owner"]["preferred"] is True


@pytest.mark.asyncio
async def test_runtime_returns_planning_clarification_question_when_availability_declines_for_missing_service(monkeypatch: pytest.MonkeyPatch):
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
                "utm_campaign": "campaign",
                "utm_term": None,
                "utm_content": None,
                "gclid": None,
                "fbclid": None,
            }
        ),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
            timeout_seconds=15,
        ),
        tenant_context=build_context(),
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_appointment_availability_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = False

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        trace = build_appointment_shadow_trace()
        trace.steps[0].output["clarification"] = {
            "needed": True,
            "question": "¿Qué servicio desea reservar para mañana por la tarde?",
            "missing_fields": ["service_name"],
        }
        return trace

    async def fake_appointment_execute(self, payload, routing, backend_context, contact_context, trace, mcp_config, previous_response_id=None):
        return AppointmentAvailabilityExecutionOutcome(
            attempted=False,
            ok=False,
            fallback_reason="clarification_requested",
            planning={
                "domain": "appointment",
                "intent": "request_availability",
                "action_candidate": "ask_clarification",
                "clarification": {
                    "needed": True,
                    "question": "¿Qué servicio desea reservar para mañana por la tarde?",
                    "missing_fields": ["service_name"],
                },
            },
            context_plan={
                "include_appointment_context": True,
            },
            tool_policy={
                "lookup_tools_enabled": ["services_search", "appointment_availability"],
                "write_tools_enabled": [],
            },
            bounded_single_tool_call=False,
            mcp_allowed_tools=["services_search", "appointment_availability"],
            mcp_tool_traces=[],
            trace_id="trace-appointment",
        )

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", fake_appointment_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(SlotSelectionExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )
    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar mañana por la tarde",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert "servicio" in response.reply.lower()
    assert "mañana" in response.reply.lower()
    assert "perfecto. estoy revisando" not in response.reply.lower()
    assert "16:00" not in response.reply
    assert "17:00" not in response.reply
    assert response.action == "ask_clarification"
    assert response.needs_human is False
    assert response.data_to_save["new_llm_orchestration_appointment_availability_attempted"] is False
    assert response.data_to_save["new_llm_orchestration_appointment_availability_fallback_reason"] == "clarification_requested"
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["fallback_reason"] == "clarification_requested"
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["mcp_tool_traces"] == []
    assert "new_llm_orchestration_offered_slots" not in response.data_to_save


@pytest.mark.asyncio
async def test_runtime_does_not_postprocess_appointment_confirmation_after_availability_slice(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = True
    backend.settings.new_llm_orchestration_slot_selection_enabled = False

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_appointment_shadow_trace()

    async def fake_appointment_execute(self, payload, routing, backend_context, contact_context, trace, mcp_config, previous_response_id=None):
        return AppointmentAvailabilityExecutionOutcome(
            attempted=True,
            ok=True,
            reply="Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
            provider="openai",
            model="gpt-4.1-mini",
            response_id="resp-2",
            latency_ms=61,
            planning={
                "domain": "appointment",
                "intent": "request_availability",
            },
            context_plan={
                "include_appointment_context": True,
            },
            tool_policy={
                "lookup_tools_enabled": ["services_search", "appointment_availability"],
                "write_tools_enabled": [],
            },
            bounded_single_tool_call=True,
            mcp_allowed_tools=["services_search", "appointment_availability"],
            mcp_tool_traces=[
                {"tool_name": "services_search", "status": "completed"},
                {"tool_name": "appointment_availability", "status": "completed"},
            ],
            response_payload={
                "reply": "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?",
                "reason": "availability found",
            },
            trace_id="trace-appointment",
        )

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", fake_appointment_execute)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar láser cuerpo entero mañana por la tarde",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.reply == "Para láser cuerpo entero mañana por la tarde tengo 16:00, 16:30 y 17:00. ¿Cuál prefieres?"
    assert response.intent == "request_availability"
    assert response.action == "answer_question"
    assert response.data_to_save["new_llm_orchestration_appointment_availability_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_ok"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_used"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["ok"] is True
    assert response.data_to_save["new_llm_orchestration_appointment_availability_trace"]["bounded_single_tool_call"] is True
    assert "appointment_confirm_post_processed" not in response.data_to_save
    assert "confirmada" not in response.reply.lower()


@pytest.mark.asyncio
async def test_runtime_applies_slot_selection_slice_from_structured_offered_slots(monkeypatch: pytest.MonkeyPatch):
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
        tenant_context=build_context(),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            allowed_tools=["services_search", "appointment_availability", "appointment_confirm"],
            timeout_seconds=15,
        ),
        conversation_result={
            "created": True,
            "conversation": {
                "id": "conversation-1",
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = False
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = False
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = True

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        trace = build_slot_selection_shadow_trace()
        trace.steps[0].output["entities"]["time"] = "16:30"
        trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
        trace.steps[0].output["entities"]["selected_slot_index"] = None
        trace.steps[0].output["entities"]["owner_name"] = None
        trace.steps[0].output["entities"]["owner_id"] = None
        return trace

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]

    offered_slots_context = build_offered_slots_context_message()
    offered_slots = offered_slots_context["metadata"]["data_to_save"]["new_llm_orchestration_offered_slots"]
    await backend.create_conversation_message(
        BackendConversationMessagePayload(
            conversation_id="conversation-1",
            direction="outbound",
            role="assistant",
            message_type="text",
            body="Para el lunes 16 de junio por la tarde hay disponibilidad a las 16:00, 16:30 y 17:00.",
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=62,
            intent="request_availability",
            score=91,
            action="answer_question",
            needs_human=False,
            raw_payload=build_availability_summary_raw_payload(
                offered_slots,
                "Para el lunes 16 de junio por la tarde hay disponibilidad a las 16:00, 16:30 y 17:00.",
            ),
            metadata={
                "data_to_save": build_availability_summary_raw_payload(
                    offered_slots,
                    "Para el lunes 16 de junio por la tarde hay disponibilidad a las 16:00, 16:30 y 17:00.",
                )["data_to_save"],
            },
        )
    )

    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Prefiero el de las 16:30",
        contact=Contact(phone="+34999999999", name="Federico Martín"),
        conversation={
            "external_id": "conversation-1",
        },
    )

    response = await runtime.respond(payload)

    assert response.reply == "Perfecto, tengo el martes 16/06 a las 16:30 con María Gutiérrez para Láser cuerpo entero. ¿Confirmo la cita?"
    assert response.intent == "select_offered_slot"
    assert response.action == "answer_question"
    assert response.data_to_save["new_llm_orchestration_slot_selection_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_slot_selection_ok"] is True
    assert response.data_to_save["new_llm_orchestration_slot_selection_used"] is True
    assert response.data_to_save["new_llm_orchestration_slot_selection_trace"]["ok"] is True
    assert response.data_to_save["selected_slot"]["start"] == "2026-06-16T16:30:00+01:00"
    assert response.data_to_save["required_next_action"] == "appointment_confirm"
    assert response.data_to_save["new_llm_orchestration_selected_slot"]["start"] == "2026-06-16T16:30:00+01:00"
    assert response.data_to_save["new_llm_orchestration_offered_slots_count"] == 3
    assert "appointment_confirm" not in json.dumps(response.data_to_save.get("new_llm_orchestration_slot_selection_trace", {}))
    assert any(
        call[0] == "get_conversation_summary_context"
        and call[1][0] == "conversation-1"
        and call[1][2] == "tenant-1"
        and call[1][3] == "conversation-1"
        and call[1][4] == "+34999999999"
        for call in backend.calls
    )


@pytest.mark.asyncio
async def test_runtime_recovers_summary_context_from_external_id_when_internal_conversation_id_is_missing(monkeypatch: pytest.MonkeyPatch):
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
            }
        ),
        tenant_context=build_context(),
        conversation_result={
            "created": True,
            "conversation": {
                "status": "active",
                "summary": None,
            },
        },
    )
    backend.settings = Settings()
    backend.settings.new_llm_orchestration_enabled = True
    backend.settings.new_llm_orchestration_catalog_execution_enabled = False
    backend.settings.new_llm_orchestration_appointment_availability_enabled = False
    backend.settings.new_llm_orchestration_slot_selection_enabled = True

    offered_slots = [
        {
            "start": "2026-06-16T16:00:00+01:00",
            "end": "2026-06-16T17:30:00+01:00",
            "timezone": "Atlantic/Canary",
            "service_id": "service-uuid",
            "service_name": "Láser cuerpo entero",
            "duration_minutes": 90,
            "owner": {
                "id": "owner-claudia-uuid",
                "name": "Claudia Estética",
                "email": "claudia@example.com",
                "preferred": True,
            },
            "slot_label": "16:00",
            "display_time": "16:00",
        }
    ]

    class SummaryMessageStub:
        def __init__(self, payload: dict[str, object]) -> None:
            self.payload = payload

        def model_dump(self):
            return self.payload

    class SummaryContextStub:
        def __init__(self, messages: list[SummaryMessageStub]) -> None:
            self.messages = messages

    backend.summary_context = SummaryContextStub(
        [
            SummaryMessageStub(
                {
                    "conversation_id": "conversation-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Para láser cuerpo entero mañana por la tarde tengo 16:00. ¿Cuál prefieres?",
                    "raw_payload": build_availability_summary_raw_payload(
                        offered_slots,
                        "Para láser cuerpo entero mañana por la tarde tengo 16:00. ¿Cuál prefieres?",
                    ),
                }
            )
        ]
    )

    async def fake_shadow_execute(self, payload, routing, backend_context, contact_context):
        return build_slot_selection_shadow_trace()

    monkeypatch.setattr(DecisionEngine, "decide", fail_if_decide_called)
    monkeypatch.setattr(ShadowPlanningService, "execute", fake_shadow_execute)
    monkeypatch.setattr(CatalogExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(AppointmentAvailabilityExecutionService, "execute", assert_slice_not_called)
    monkeypatch.setattr(
        AgentRuntime,
        "_resolve_agenda_effective_timezone_details",
        lambda self, backend_context, contact_context=None: ("Atlantic/Canary", "crm_tenant"),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]

    async def fake_external_id_shadow_execute(self, payload, routing, backend_context, contact_context):
        trace = build_slot_selection_shadow_trace()
        trace.steps[0].output["entities"]["time"] = "16:00"
        trace.steps[0].output["entities"]["slot_reference"] = "exact_time"
        trace.steps[0].output["entities"]["selected_slot_index"] = None
        trace.steps[0].output["entities"]["owner_name"] = None
        trace.steps[0].output["entities"]["owner_id"] = None
        return trace

    monkeypatch.setattr(ShadowPlanningService, "execute", fake_external_id_shadow_execute)

    response = await runtime.respond(
        AgentRequest(
            tenant_id="tenant-1",
            entrypoint_ref="abc123",
            message="Elijo las 16:00",
            contact=Contact(phone="+34999999999"),
            conversation={"external_id": "external-slot-flow-001"},
        )
    )

    assert response.intent == "select_offered_slot"
    assert response.data_to_save["new_llm_orchestration_slot_selection_attempted"] is True
    assert response.data_to_save["new_llm_orchestration_slot_selection_ok"] is True
    assert response.data_to_save.get("new_llm_orchestration_slot_selection_fallback_reason") != "offered_slots_missing"
    assert response.data_to_save["selected_slot"] is not None
    assert response.data_to_save["required_next_action"] == "collect_customer_name"
    assert response.data_to_save["new_llm_orchestration_selected_slot"] is not None
    assert response.data_to_save["new_llm_orchestration_selected_slot"]["owner"]["id"] == "owner-claudia-uuid"
    assert response.data_to_save["new_llm_orchestration_slot_selection_trace"]["appointment_context"]["offered_slots"][0]["owner"]["name"] == "Claudia Estética"
    assert any(
        call[0] == "get_conversation_summary_context"
        and call[1][0] == "external-slot-flow-001"
        and call[1][2] == "tenant-1"
        and call[1][3] == "external-slot-flow-001"
        for call in backend.calls
    )


@pytest.mark.asyncio
async def test_runtime_passes_recent_contact_context_to_resolver(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability"],
            timeout_seconds=15,
        ),
    )

    class RecordingContactContextResolver:
        def __init__(self) -> None:
            self.calls: list[dict[str, object]] = []

        async def resolve(self, payload, backend_context, mcp_config, recent_contact_context=None):
            self.calls.append(
                {
                    "tenant_id": backend_context.tenant.id if backend_context is not None else None,
                    "recent_contact_context": recent_contact_context,
                }
            )
            assert recent_contact_context is not None
            assert recent_contact_context["data"]["timezone"] == "Atlantic/Canary"
            assert recent_contact_context["data"]["business_context"]["timezone"] == "Atlantic/Canary"
            assert recent_contact_context["data"]["business_context"]["timezone_source"] == "crm_tenant"
            return recent_contact_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        assert contact_context["data"]["business_context"]["timezone"] == "Atlantic/Canary"
        assert contact_context["data"]["business_context"]["timezone_source"] == "crm_tenant"
        return AgentResponse(
            reply="Sí, hay huecos.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_tool_traces": [],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=88,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    contact_context_resolver = RecordingContactContextResolver()
    runtime = AgentRuntime(
        backend,
        RuntimeRoutingResolver(backend),
        DecisionEngine(backend),
        contact_context_resolver=contact_context_resolver,
    )  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [
                "SA: Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
            ],
            "context_messages": [
                {
                    "id": "message-contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto resuelto.",
                    "metadata": {
                        "data_to_save": {
                            "external_context_available": True,
                            "external_context_configured": True,
                            "external_context_timezone": "Atlantic/Canary",
                            "external_context_timezone_source": "contact_context",
                            "external_business_context_timezone": "Atlantic/Canary",
                            "external_business_context_timezone_source": "crm_tenant",
                            "external_business_context_needs_branch_selection": False,
                        }
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.action == "answer_question"
    assert len(contact_context_resolver.calls) == 1
    assert contact_context_resolver.calls[0]["recent_contact_context"] is not None


@pytest.mark.asyncio
async def test_runtime_rejects_appointment_availability_timezone_mismatch(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_availability", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        return AgentResponse(
            reply="Sí, hay huecos para mañana por la tarde.",
            intent="agenda",
            score=0.93,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_availability",
                        "arguments": {
                            "service_id": "service-uuid",
                            "timezone": "Europe/Madrid",
                        },
                        "output": {
                            "available": True,
                            "slots": [
                                {
                                    "start": "2026-06-11T17:35:00+02:00",
                                    "end": "2026-06-11T19:05:00+02:00",
                                    "service_id": "service-uuid",
                                    "owner_id": "owner-uuid",
                                    "owner_ref": "owner-ref-1",
                                    "timezone": "Europe/Madrid",
                                }
                            ],
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=52,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Buenas tardes. Dime disponibilidad de María para láser cuerpo entero para mañana por la tarde. Gracias.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [
                "SA: Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
            ],
            "context_messages": [
                {
                    "id": "message-contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto resuelto.",
                    "metadata": {
                        "data_to_save": {
                            "external_context_available": True,
                            "external_context_configured": True,
                            "external_context_timezone": "Atlantic/Canary",
                            "external_context_timezone_source": "contact_context",
                            "external_business_context_timezone": "Atlantic/Canary",
                            "external_business_context_timezone_source": "crm_tenant",
                            "external_business_context_needs_branch_selection": False,
                        }
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "ask_question"
    assert response.needs_human is False
    assert "zona horaria correcta" in response.reply.lower()
    assert response.data_to_save["timezone_guardrail_blocked"] is True
    assert response.data_to_save["timezone_guardrail_reason"] == "timezone_mismatch"
    assert response.data_to_save["timezone_expected"] == "Atlantic/Canary"
    assert response.data_to_save["timezone_expected_source"] in {"contact_context", "crm_tenant"}
    assert response.data_to_save["mismatched_tool"] == "appointment_availability"
    assert response.data_to_save["contact_context_resolver_called"] is True
    assert response.data_to_save["contact_context_available"] is True
    assert response.data_to_save["effective_timezone"] == "Atlantic/Canary"
    assert response.data_to_save["effective_timezone_source"] in {"crm_tenant", "contact_context.business_context", "contact_context"}
    assert response.data_to_save["mcp_tool_traces"] == []


@pytest.mark.asyncio
async def test_runtime_rejects_appointment_confirm_timezone_mismatch_even_when_tool_succeeds(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_confirm", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert contact_context is not None
        assert contact_context["data"]["timezone"] == "Atlantic/Canary"
        return AgentResponse(
            reply="Perfecto. Estoy revisando ese horario. Si necesito algún dato adicional para cerrarlo, te lo pediré ahora.",
            intent="open_question",
            score=0.95,
            action="ask_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_confirm",
                        "arguments": {
                            "service_id": "service-uuid",
                            "owner_id": "owner-uuid",
                            "timezone": "Europe/Madrid",
                        },
                        "output": json.dumps(
                            {
                                "ok": True,
                                "confirmed": True,
                                "appointment": {
                                    "id": "019eb2a0-e153-78b8-9cde-8a94f5c22cb0",
                                    "ownerName": "María Gutiérrez",
                                    "service": {
                                        "name": "Láser cuerpo entero",
                                    },
                                    "startAt": "2026-06-12T19:10:00+01:00",
                                    "endAt": "2026-06-12T20:40:00+01:00",
                                },
                                "message": "La cita quedó confirmada correctamente.",
                            },
                            ensure_ascii=False,
                        ),
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=52,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Sí, reserva el de las 17:35, por favor.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [
                "SA: Para mañana por la tarde hay disponibilidad a las 17:35 y a las 19:10.",
            ],
            "context_messages": [
                {
                    "id": "message-contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto resuelto.",
                    "metadata": {
                        "data_to_save": {
                            "external_context_available": True,
                            "external_context_configured": True,
                            "external_context_timezone": "Atlantic/Canary",
                            "external_context_timezone_source": "contact_context",
                            "external_business_context_timezone": "Atlantic/Canary",
                            "external_business_context_timezone_source": "crm_tenant",
                            "external_business_context_needs_branch_selection": False,
                        }
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "ask_question"
    assert response.needs_human is False
    assert "zona horaria correcta" in response.reply.lower()
    assert response.data_to_save["timezone_guardrail_blocked"] is True
    assert response.data_to_save["timezone_guardrail_reason"] == "timezone_mismatch"
    assert response.data_to_save["timezone_expected"] == "Atlantic/Canary"
    assert response.data_to_save["mismatched_tool"] == "appointment_confirm"
    assert response.data_to_save["mcp_tool_traces"] == []


@pytest.mark.asyncio
async def test_runtime_does_not_affirm_failed_appointment_confirmation(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_confirm", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="He reservado para ti la cita.",
            intent="agenda",
            score=0.95,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_confirm_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_confirm",
                        "arguments": {
                            "service_id": "service-uuid",
                            "owner_id": "owner-uuid",
                            "timezone": "Atlantic/Canary",
                        },
                        "output": {
                            "ok": False,
                            "confirmed": False,
                            "error_code": "validation_error",
                            "message": "ownerId/owner_id/ownerRef/owner_ref is required.",
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=80,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="17:35 por favor",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [],
            "context_messages": [
                {
                    "id": "contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto externo resuelto.",
                    "metadata": {
                        "contact_context": build_contact_context_payload(),
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert "reserv" not in response.reply.lower()
    assert "confirm" not in response.reply.lower() or "no he podido" in response.reply.lower()


@pytest.mark.asyncio
async def test_runtime_rewrites_provisional_reply_after_successful_appointment_confirmation(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_confirm", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Perfecto. Estoy revisando ese horario. Si necesito algún dato adicional para cerrarlo, te lo pediré ahora.",
            intent="open_question",
            score=0.95,
            action="ask_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_confirm_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_confirm",
                        "arguments": {
                            "service_id": "service-uuid",
                            "owner_id": "owner-uuid",
                            "timezone": "Atlantic/Canary",
                        },
                        "output": {
                            "ok": True,
                            "confirmed": True,
                            "appointment": {
                                "id": "019eb2a0-e153-78b8-9cde-8a94f5c22cb0",
                            },
                            "start": "2026-06-12T19:10:00+01:00",
                            "end": "2026-06-12T20:40:00+01:00",
                            "title": "Cita para Láser cuerpo entero",
                            "owner_name": "María Gutiérrez",
                            "message": "La cita quedó confirmada correctamente.",
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=80,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="El de las 17:35, por favor.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [],
            "context_messages": [
                {
                    "id": "contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto externo resuelto.",
                    "metadata": {
                        "contact_context": build_contact_context_payload(),
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "completed"
    assert response.needs_human is False
    assert "confirmada" in response.reply.lower()
    assert "19:10" in response.reply
    assert "maría gutiérrez" in response.reply.lower()
    assert "láser cuerpo entero" in response.reply.lower()
    assert response.reply.count("confirmad") == 1
    assert "La cita quedó confirmada correctamente." not in response.reply
    assert response.data_to_save["appointment_confirm_post_processed"] is True


@pytest.mark.asyncio
async def test_runtime_rewrites_successful_appointment_confirmation_from_raw_output_string_json(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_confirm", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    raw_output = json.dumps(
        {
            "ok": True,
            "confirmed": True,
            "appointment": {
                "id": "019eb692-19a7-7077-b7df-9ceb6e4874ab",
                "status": "confirmed",
                "ownerName": "María Gutiérrez",
                "service": {
                    "id": "019eb05e-5f79-7630-ba89-38e0ec1493a0",
                    "name": "Láser cuerpo entero",
                    "durationMinutes": 90,
                },
                "startAt": "2026-06-15T19:10:00+01:00",
                "endAt": "2026-06-15T20:40:00+01:00",
                "timezone": "Atlantic/Canary",
            },
            "message": "La cita quedó confirmada correctamente.",
            "raw_summary": {
                "status": "confirmed",
                "source": "crm",
            },
            "error_code": None,
        },
        ensure_ascii=False,
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Perfecto. Estoy revisando ese horario. Si necesito algún dato adicional para cerrarlo, te lo pediré ahora.",
            intent="open_question",
            score=0.95,
            action="ask_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_confirm_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "appointment_confirm",
                        "arguments": {
                            "service_id": "service-uuid",
                            "owner_id": "owner-uuid",
                            "timezone": "Atlantic/Canary",
                        },
                        "output": None,
                        "raw": {
                            "output": raw_output,
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=80,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="El de las 19:10, por favor.",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [],
            "context_messages": [
                {
                    "id": "contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto externo resuelto.",
                    "metadata": {
                        "contact_context": build_contact_context_payload(),
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert response.action == "completed"
    assert response.needs_human is False
    assert "confirmada" in response.reply.lower()
    assert "19:10" in response.reply
    assert "maría gutiérrez" in response.reply.lower()
    assert "láser cuerpo entero" in response.reply.lower()
    assert response.reply.count("confirmad") == 1
    assert "La cita quedó confirmada correctamente." not in response.reply
    assert response.data_to_save["appointment_confirm_post_processed"] is True


@pytest.mark.asyncio
async def test_runtime_does_not_prematurely_claim_booking_on_slot_selection_without_success(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["appointment_confirm", "contact_context_mock"],
            timeout_seconds=15,
        ),
    )

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        return AgentResponse(
            reply="Perfecto, te reservo la cita con ese horario.",
            intent="agenda",
            score=0.95,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "agenda",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_confirm_123",
                "mcp_tool_traces": [],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=80,
        )

    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)
    monkeypatch.setattr(ContactContextResolver, "resolve", resolve_contact_context_payload)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="17:35 por favor",
        contact=Contact(phone="+34999999999"),
        conversation={
            "last_messages": [],
            "context_messages": [
                {
                    "id": "contact-context-1",
                    "direction": "outbound",
                    "role": "assistant",
                    "message_type": "text",
                    "body": "Contexto externo resuelto.",
                    "metadata": {
                        "contact_context": build_contact_context_payload(),
                    },
                }
            ],
        },
    )

    response = await runtime.respond(payload)

    assert "reserv" not in response.reply.lower()
    assert "te reservo" not in response.reply.lower()
    assert "revisando ese horario" in response.reply.lower()


@pytest.mark.asyncio
async def test_runtime_keeps_agenda_fallback_when_appointment_tools_are_unavailable(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["services_search"],
            timeout_seconds=15,
        ),
    )

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Quiero reservar láser axilas esta semana. ¿Qué huecos tienes?",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider is None
    assert response.model is None
    assert response.action == "offer_booking_or_handoff"
    assert "Ahora mismo no puedo consultar la agenda" in response.reply


@pytest.mark.asyncio
async def test_runtime_skips_legacy_handoff_webhook_when_llm_used_handoff_request(monkeypatch: pytest.MonkeyPatch):
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
        handoff_tool=BackendExternalTool.model_validate(
            {
                "id": "tool-1",
                "tenantId": "tenant-1",
                "name": "Handoff webhook",
                "type": "handoff_webhook",
                "provider": "n8n_webhook",
                "webhookUrl": "https://n8n.example.test/webhook/handoff",
                "authType": "none",
                "timeoutSeconds": 3,
                "config": {},
            }
        ),
        mcp_config=McpRemoteConfig(
            enabled=True,
            server_label="tech_investments_mcp",
            server_url="https://mcp.tech-investments.net/mcp",
            allowed_tools=["services_search", "handoff_request"],
            timeout_seconds=15,
        ),
    )
    tenant = BackendTenant.model_validate(
        {
            "id": "tenant-1",
            "name": "Negocio Demo",
            "slug": "negocio-demo",
            "businessContext": "Negocio especializado en automatización de WhatsApp.",
            "tone": "consultivo",
            "salesPolicy": {},
            "isActive": True,
            "handoff": {
                "enabled": True,
                "strategy": "n8n_webhook",
                "whatsapp_public": "+34 612 345 678",
                "message": "Prefiero que esto lo revise una persona del equipo.",
            },
            "createdAt": "2026-04-28T12:00:00+00:00",
        }
    )
    backend_context = CommercialContext(
        tenant=tenant,
        products=[],
        playbooks=[],
        selected_product=None,
        selected_playbook=None,
    )

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return backend_context

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
        assert mcp_config is not None and mcp_config.enabled is True
        return AgentResponse(
            reply="He registrado el caso para revisión.",
            intent="handoff",
            score=0.96,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={
                "topic": "handoff",
                "mcp_enabled": True,
                "mcp_server_label": "tech_investments_mcp",
                "mcp_response_id": "resp_handoff_123",
                "mcp_tool_traces": [
                    {
                        "type": "mcp_call",
                        "server_label": "tech_investments_mcp",
                        "tool_name": "handoff_request",
                        "arguments": {
                            "tenant_id": "tenant-1",
                            "priority": "high",
                        },
                        "output": {
                            "ok": True,
                            "handoff_requested": True,
                            "status": "requested",
                            "message": "Handoff request queued.",
                        },
                        "status": "completed",
                    }
                ],
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=102,
        )

    monkeypatch.setattr(backend, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "decide", fake_decide)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Estoy muy frustrado con esto",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.data_to_save["mcp_tool_traces"][0]["tool_name"] == "handoff_request"
    assert ("get_external_tool", ("tenant-1", "handoff_webhook")) not in backend.calls


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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, previous_response_id=None):
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


@pytest.mark.asyncio
async def test_runtime_forces_llm_path_when_catalog_is_empty_and_mcp_search_is_available(monkeypatch: pytest.MonkeyPatch):
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
            allowed_tools=["services_search"],
            timeout_seconds=15,
        ),
    )

    async def fake_fetch_tenant_context(self, tenant_id, selected_product_id=None, selected_playbook_id=None, *args):
        self.calls.append(("fetch_tenant_context", (tenant_id, selected_product_id, selected_playbook_id, *args)))
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
            product_selection={
                "selection_source": "none",
                "candidate_count": 0,
                "needs_service_clarification": False,
                "fallback_to_mcp_allowed": True,
                "reason": "no local catalog; MCP fallback available",
            },
            playbooks=[],
            selected_product=None,
            selected_playbook=None,
        )

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        assert force_llm is True
        assert mcp_config is not None and mcp_config.enabled is True
        return AgentResponse(
            reply="Respuesta desde LLM con MCP.",
            intent="open_question",
            score=0.9,
            action="answer_question",
            needs_human=False,
            data_to_save={
                "topic": "discovery",
                "local_response_short_circuited": False,
                "mcp_services_search_available": True,
            },
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=77,
        )

    monkeypatch.setattr(RecordingBackendClient, "fetch_tenant_context", fake_fetch_tenant_context)
    monkeypatch.setattr(DecisionEngine, "resolve_llm_response", fake_resolve_llm_response)

    runtime = AgentRuntime(backend, RuntimeRoutingResolver(backend), DecisionEngine(backend))  # type: ignore[arg-type]
    payload = AgentRequest(
        tenant_id="tenant-ignored",
        entrypoint_ref="abc123",
        message="Estoy interesado en un servicio de integración con Holded o FacturaScripts.",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.provider == "openai"
    assert response.model == "gpt-4.1-mini"
    assert response.data_to_save["local_response_short_circuited"] is False
    assert response.data_to_save["mcp_services_search_available"] is True
