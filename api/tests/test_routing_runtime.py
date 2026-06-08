import httpx
import pytest

from app.config import Settings
from app.schemas.agent import AgentResponse
from app.schemas.agent import AgentRequest, Contact
from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendExternalTool, BackendRoutingEntryPointUtmContext, BackendTenant, CommercialContext
from app.schemas.llm import LLMUsage, McpRemoteConfig
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
        handoff_tool: BackendExternalTool | None = None,
    ) -> None:
        self.ref_context = ref_context
        self.phone_context = phone_context
        self.mcp_config = mcp_config
        self.handoff_tool = handoff_tool
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


class FailingAudioTranscriptionClient:
    async def transcribe(self, audio_bytes: bytes, content_type: str | None, media_id: str, duration_seconds: int | None = None):
        raise RuntimeError("OpenAI transcription rejected media media-123: 400 body=Invalid audio format")

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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
        assert mcp_config is not None and mcp_config.enabled is True
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
                            "timezone": "Europe/Madrid",
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

    async def fake_decide(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None):
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

    async def fake_resolve_llm_response(self, payload, routing=None, backend_context=None, contact_context=None, mcp_config=None, force_llm=False):
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
