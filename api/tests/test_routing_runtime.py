from datetime import datetime, timedelta, timezone
import json

import httpx
import pytest

from app.schemas.agent import AgentResponse
from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendExternalTool, BackendRoutingEntryPointUtmContext, BackendTenant, CommercialContext
from app.services.contact_context_resolver import ContactContextResolver
from app.schemas.llm import LLMUsage, McpRemoteConfig
from app.services.decision_engine import DecisionEngine
from app.services.llm_decision_service import LLMDecisionService
from app.services.llm_prompt_builder import LLMPromptBuilder
from app.services.routing_resolver import RuntimeRoutingResolver
from app.services.runtime import AgentRuntime


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

    async def get_conversation_summary_context(self, conversation_id: str, limit: int = 20):
        self.calls.append(("get_conversation_summary_context", (conversation_id, limit)))
        return self.summary_context

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

    async def fake_fetch_tenant_context(*args, **kwargs):
        backend.calls.append(("fetch_tenant_context", args))
        return build_context()

    seen: dict[str, object] = {}

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False, previous_response_id=None):
        seen["previous_response_id"] = previous_response_id
        seen["context_messages"] = payload.conversation.context_messages
        return AgentResponse(
            reply="Perfecto, confirmo la cita.",
            intent="agenda",
            score=0.94,
            action="answer_question",
            needs_human=False,
            data_to_save={},
            provider="openai",
            model="gpt-4.1-mini",
            latency_ms=10,
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
        message="Elijo las 17:35. ¿Me puedes confirmar la cita?",
        contact=Contact(phone="+34999999999"),
    )

    response = await runtime.respond(payload)

    assert response.intent == "agenda"
    assert seen["previous_response_id"] == previous_response_id
    assert seen["context_messages"][0]["metadata"]["mcp_tool_traces"][0]["output"]["slots"][0]["owner_id"] == "owner-uuid"


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
    assert response.data_to_save["operational_context"]["channel"] == "whatsapp"
    assert response.data_to_save["operational_context"]["contact_context_source"] == "external_tool:n8n"
    assert response.data_to_save["timezone_guardrail_blocked"] is False
    assert response.data_to_save["timezone_mismatch_detected"] is False


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
    assert "láser cuerpo entero" in response.reply.lower()
    assert "maría gutiérrez" in response.reply.lower()
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
    assert "láser cuerpo entero" in response.reply.lower()
    assert "maría gutiérrez" in response.reply.lower()
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
