import json

import pytest

from app.config import Settings
from app.services.backend_client import BackendConversationSummaryContext
from app.services.conversation_summary_service import ConversationSummaryService


class FakeLLMClient:
    def __init__(self, content: str = '{"summary":"Cliente interesado en depilación láser de cuerpo entero para mañana a las 9:00 con María. Último estado: requiere revisión humana por problema técnico en la confirmación. Siguiente acción: revisar agenda y confirmar manualmente."}') -> None:
        self.content = content
        self.generate_calls = []
        self.generate_with_mcp_calls = []

    async def resolve_configuration(self) -> dict[str, str]:
        return {
            "llm_default_profile": "openai",
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "test-key",
        }

    async def generate(self, provider, system_prompt, user_prompt, configuration=None):
        self.generate_calls.append(
            {
                "provider": provider,
                "system_prompt": system_prompt,
                "user_prompt": user_prompt,
                "configuration": configuration,
            }
        )
        return type(
            "LLMResponseResultStub",
            (),
            {
                "provider": provider,
                "model": "gpt-4.1-mini",
                "content": self.content,
                "response_id": None,
                "usage": None,
                "estimated_cost": None,
                "raw_payload": {},
                "tool_traces": [],
            },
        )()

    async def generate_with_mcp(self, *args, **kwargs):  # pragma: no cover - defensive
        self.generate_with_mcp_calls.append((args, kwargs))
        raise AssertionError("Conversation summary must not use MCP or previous_response_id")


class FakeBackendClient:
    def __init__(self, context: BackendConversationSummaryContext) -> None:
        self.context = context
        self.summary_updates = []

    async def get_conversation_summary_context(self, conversation_id: str, limit: int = 20):
        assert conversation_id == "conversation-1"
        assert limit == 20
        return self.context

    async def update_conversation_summary(self, conversation_id: str, summary: str):
        self.summary_updates.append((conversation_id, summary))
        return {"updated": True, "conversation": {"id": conversation_id, "summary": summary}}


def build_context() -> BackendConversationSummaryContext:
    return BackendConversationSummaryContext.model_validate(
        {
            "conversation": {
                "id": "conversation-1",
                "tenant_id": "tenant-1",
                "status": "pending_human",
                "summary": "Resumen previo",
                "lastMessageAt": "2026-06-08T10:00:00+00:00",
            },
            "messages": [
                {
                    "id": f"message-{index}",
                    "conversation_id": "conversation-1",
                    "direction": "inbound" if index % 2 == 0 else "outbound",
                    "role": "user" if index % 2 == 0 else "assistant",
                    "message_type": "text",
                    "body": f"Mensaje {index} " + ("x" * 50),
                    "provider": "openai" if index % 2 else None,
                    "model": "gpt-4.1-mini" if index % 2 else None,
                    "latency_ms": 42 if index % 2 else None,
                    "intent": "agenda" if index == 0 else None,
                    "action": "offer_booking" if index == 0 else None,
                    "needs_human": index == 5,
                    "created_at": f"2026-06-08T09:{index:02d}:00+00:00",
                }
                for index in range(30)
            ],
            "limit": 20,
        }
    )


@pytest.mark.asyncio
async def test_conversation_summary_service_generates_compact_summary_without_mcp_or_previous_response_id():
    context = build_context()
    backend_client = FakeBackendClient(context)
    llm_client = FakeLLMClient()
    service = ConversationSummaryService(
        Settings(BACKEND_BASE_URL="http://backend", SALES_AGENT_BEARER_TOKEN="token"),
        backend_client,  # type: ignore[arg-type]
        llm_client=llm_client,  # type: ignore[arg-type]
    )

    summary = await service.generate_and_persist("conversation-1", reason="handoff")

    assert summary is not None
    assert summary.startswith("Cliente interesado")
    assert backend_client.summary_updates == [("conversation-1", summary)]
    assert len(llm_client.generate_calls) == 1
    assert llm_client.generate_with_mcp_calls == []

    call = llm_client.generate_calls[0]
    assert call["provider"] == "openai"
    assert "Devuelve solo JSON válido" in call["system_prompt"]
    assert "summary" in call["user_prompt"]
    assert "previous_response_id" not in call["user_prompt"]
    parsed = json.loads(call["user_prompt"])
    assert len(parsed["messages"]) == 20
    assert parsed["messages"][-1]["direction"] == "outbound"
    assert parsed["messages"][-1]["body"].startswith("Mensaje 29")


@pytest.mark.asyncio
async def test_conversation_summary_service_failure_is_best_effort():
    context = build_context()
    backend_client = FakeBackendClient(context)
    llm_client = FakeLLMClient()

    async def failing_generate(*args, **kwargs):
        raise RuntimeError("openai down")

    llm_client.generate = failing_generate  # type: ignore[assignment]

    service = ConversationSummaryService(
        Settings(BACKEND_BASE_URL="http://backend", SALES_AGENT_BEARER_TOKEN="token"),
        backend_client,  # type: ignore[arg-type]
        llm_client=llm_client,  # type: ignore[arg-type]
    )

    summary = await service.generate_and_persist("conversation-1", reason="handoff")

    assert summary is None
    assert backend_client.summary_updates == []
