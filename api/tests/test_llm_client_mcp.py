import json

import httpx
import pytest

from app.config import Settings
from app.schemas.llm import McpRemoteConfig
from app.services.llm_client import LLMClient


def mcp_transport_handler(request: httpx.Request) -> httpx.Response:
    assert request.method == "POST"
    assert request.url.path == "/v1/responses"

    payload = json.loads(request.content.decode("utf-8"))
    assert payload["model"] == "gpt-4.1-mini"
    assert payload["instructions"].startswith("Eres un asistente de ventas")
    assert "json" in payload["input"].lower()
    assert payload["tools"][0]["type"] == "mcp"
    assert payload["tools"][0]["server_label"] == "tenant_main_mcp"
    assert payload["tools"][0]["server_url"] == "https://mcp.example.test"
    assert payload["tools"][0]["allowed_tools"] == ["search_properties"]
    assert payload["tools"][0]["require_approval"] == "never"

    return httpx.Response(
        200,
        json={
            "id": "resp_1",
            "usage": {
                "input_tokens": 120,
                "output_tokens": 32,
                "cached_tokens": 40,
                "total_tokens": 152,
            },
            "output_text": json.dumps(
                {
                    "reply": "Tu próxima cita es mañana a las 10:00.",
                    "intent": "agenda",
                    "score": 0.93,
                    "action": "answer_question",
                    "needs_human": False,
                    "data_to_save": {"topic": "agenda"},
                }
            ),
            "output": [
                {
                    "type": "mcp_call",
                    "server_label": "tenant_main_mcp",
                    "tool_name": "appointment_availability",
                    "arguments": {"phone": "+34999999999"},
                    "output": {"found": True},
                    "status": "completed",
                }
            ],
        },
    )


@pytest.mark.asyncio
async def test_llm_client_uses_openai_responses_with_mcp_tools():
    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15),
        transport=httpx.MockTransport(mcp_transport_handler),
    )

    result = await client.generate_with_mcp(
        "openai",
        "Eres un asistente de ventas.",
        "Hola, ¿cuál es mi próxima cita?",
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            auth_type="bearer",
            bearer_token="mcp-token",
            allowed_tools=["search_properties"],
            require_approval="auto",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
    )

    assert result.provider == "openai"
    assert result.model == "gpt-4.1-mini"
    payload = json.loads(result.content)
    assert "próxima cita" in payload["reply"]
    assert result.response_id == "resp_1"
    assert result.usage is not None
    assert result.usage.input_tokens == 120
    assert result.usage.cached_tokens == 40
    assert result.estimated_cost is not None
    assert len(result.tool_traces) == 1
    assert result.tool_traces[0].tool_name == "appointment_availability"


@pytest.mark.asyncio
async def test_llm_client_maps_mcp_require_approval_always():
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["require_approval"] = payload["tools"][0]["require_approval"]
        return httpx.Response(200, json={"id": "resp_1", "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}), "output": []})

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15),
        transport=httpx.MockTransport(handler),
    )

    await client.generate_with_mcp(
        "openai",
        "Eres un asistente de ventas.",
        "Hola",
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            auth_type="bearer",
            bearer_token="mcp-token",
            allowed_tools=["search_properties"],
            require_approval="always",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
    )

    assert seen["require_approval"] == "always"
