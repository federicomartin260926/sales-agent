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
    assert payload["tools"][0]["allowed_tools"] == ["search_properties", "crm_contact_submit"]
    assert payload["tools"][0]["require_approval"] == "never"
    assert payload["tools"][0]["authorization"] == "downstream-token"

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
async def test_llm_client_uses_dedicated_timeout_for_openai_responses(monkeypatch):
    seen: dict[str, object] = {}

    class FakeAsyncClient:
        def __init__(self, *args, **kwargs):
            seen["base_url"] = kwargs.get("base_url")
            seen["timeout"] = kwargs.get("timeout")
            seen["transport"] = kwargs.get("transport")

        async def __aenter__(self):
            return self

        async def __aexit__(self, exc_type, exc, tb):
            return False

        async def post(self, url, json=None, headers=None):
            seen["url"] = url
            seen["headers"] = headers
            request = httpx.Request("POST", f"{seen['base_url']}{url}")
            return httpx.Response(
                200,
                request=request,
                json={
                    "id": "resp_1",
                    "output_text": json_module.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}),
                    "output": [],
                },
            )

    json_module = json
    monkeypatch.setattr("app.services.llm_client.httpx.AsyncClient", FakeAsyncClient)
    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15, OPENAI_RESPONSES_TIMEOUT_SECONDS=57),
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
            downstream_authorization_token="downstream-token",
            allowed_tools=["search_properties", "crm_contact_submit"],
            require_approval="auto",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
    )

    assert seen["base_url"] == "https://api.openai.com/v1"
    assert seen["url"] == "/responses"
    assert seen["timeout"].read == 57
    assert seen["timeout"].connect == 2.0
    assert seen["headers"]["Authorization"] == "Bearer sk-test"


@pytest.mark.asyncio
async def test_llm_client_reports_openai_responses_http_error_details():
    async def handler(request: httpx.Request) -> httpx.Response:
        return httpx.Response(
            500,
            request=request,
            json={"error": "upstream failed"},
        )

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15, OPENAI_RESPONSES_TIMEOUT_SECONDS=57),
        transport=httpx.MockTransport(handler),
    )

    with pytest.raises(RuntimeError) as exc_info:
        await client._generate_openai_responses(
            "Eres un asistente de ventas.",
            "{\"reply\":\"ok\"}",
            {
                "openai_base_url": "https://api.openai.com/v1",
                "openai_model": "gpt-4.1-mini",
                "openai_api_key": "sk-test",
                "openai_timeout_seconds": "15",
            },
            McpRemoteConfig(
                enabled=True,
                server_label="tenant_main_mcp",
                server_url="https://mcp.example.test",
                auth_type="bearer",
                downstream_authorization_token="downstream-token",
                allowed_tools=["search_properties"],
                require_approval="auto",
            ),
        )

    message = str(exc_info.value)
    assert "HTTPStatusError" in message
    assert "status_code=500" in message
    assert "upstream failed" in message


@pytest.mark.asyncio
async def test_llm_client_uses_openai_responses_with_mcp_tools(monkeypatch):
    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

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
            downstream_authorization_token="downstream-token",
            allowed_tools=["search_properties", "crm_contact_submit"],
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
    assert result.tool_traces[0].status == "completed"
    assert result.tool_traces[0].output == {"found": True}
    assert result.tool_traces[0].raw["output"] == {"found": True}


@pytest.mark.asyncio
async def test_llm_client_sends_previous_response_id_only_to_openai_responses(monkeypatch):
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["payload"] = payload
        return httpx.Response(
            200,
            request=request,
            json={
                "id": "resp_2",
                "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}),
                "output": [],
            },
        )

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15),
        transport=httpx.MockTransport(handler),
    )

    result = await client.generate_with_mcp(
        "openai",
        "Eres un asistente de ventas.",
        "Hola",
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            auth_type="bearer",
            downstream_authorization_token="downstream-token",
            allowed_tools=["search_properties"],
            require_approval="auto",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
        previous_response_id="resp_1",
    )

    payload = seen["payload"]
    assert isinstance(payload, dict)
    assert payload["previous_response_id"] == "resp_1"
    assert result.response_id == "resp_2"


@pytest.mark.asyncio
async def test_llm_client_omits_previous_response_id_when_not_provided(monkeypatch):
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["payload"] = payload
        return httpx.Response(
            200,
            request=request,
            json={
                "id": "resp_3",
                "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}),
                "output": [],
            },
        )

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

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
            downstream_authorization_token="downstream-token",
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

    payload = seen["payload"]
    assert isinstance(payload, dict)
    assert "previous_response_id" not in payload


@pytest.mark.asyncio
async def test_llm_client_retries_openai_responses_without_previous_response_id_when_cursor_is_invalid(monkeypatch):
    calls: list[tuple[str, dict[str, object]]] = []

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        calls.append((request.url.path, payload))

        if request.url.path.endswith("/responses") and len(calls) == 1:
            return httpx.Response(
                400,
                request=request,
                json={
                    "error": {
                        "message": "Invalid previous_response_id: resp_1",
                        "type": "invalid_request_error",
                    }
                },
            )

        if request.url.path.endswith("/responses") and len(calls) == 2:
            assert "previous_response_id" not in payload
            return httpx.Response(
                200,
                request=request,
                json={
                    "id": "resp_2",
                    "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}),
                    "output": [],
                },
            )

        raise AssertionError(f"Unexpected request path {request.url.path}")

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15),
        transport=httpx.MockTransport(handler),
    )

    result = await client.generate_with_mcp(
        "openai",
        "Eres un asistente de ventas.",
        "Hola",
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            auth_type="bearer",
            downstream_authorization_token="downstream-token",
            allowed_tools=["search_properties"],
            require_approval="auto",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
        previous_response_id="resp_1",
    )

    assert result.response_id == "resp_2"
    assert client.last_previous_response_id_invalid is False
    assert len(calls) == 2


@pytest.mark.asyncio
async def test_llm_client_clears_cursor_flag_when_openai_responses_falls_back_to_chat_completions(monkeypatch):
    calls: list[str] = []

    def handler(request: httpx.Request) -> httpx.Response:
        calls.append(request.url.path)
        payload = json.loads(request.content.decode("utf-8"))

        if request.url.path.endswith("/responses"):
            if len(calls) == 1:
                return httpx.Response(
                    400,
                    request=request,
                    json={
                        "error": {
                            "message": "Invalid previous_response_id: resp_1",
                            "type": "invalid_request_error",
                        }
                    },
                )

            return httpx.Response(
                400,
                request=request,
                json={
                    "error": {
                        "message": "Invalid previous_response_id: resp_1",
                        "type": "invalid_request_error",
                    }
                },
            )

        if request.url.path.endswith("/chat/completions"):
            assert payload["messages"][0]["role"] == "system"
            assert payload["messages"][1]["role"] == "user"
            return httpx.Response(
                200,
                request=request,
                json={
                    "id": "chatcmpl-1",
                    "choices": [
                        {
                            "message": {
                                "content": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}),
                            }
                        }
                    ],
                },
            )

        raise AssertionError(f"Unexpected request path {request.url.path}")

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

    client = LLMClient(
        Settings(OPENAI_API_KEY="sk-test", OPENAI_TIMEOUT_SECONDS=15),
        transport=httpx.MockTransport(handler),
    )

    result = await client.generate_with_mcp(
        "openai",
        "Eres un asistente de ventas.",
        "Hola",
        McpRemoteConfig(
            enabled=True,
            server_label="tenant_main_mcp",
            server_url="https://mcp.example.test",
            auth_type="bearer",
            downstream_authorization_token="downstream-token",
            allowed_tools=["search_properties"],
            require_approval="auto",
        ),
        configuration={
            "openai_base_url": "https://api.openai.com/v1",
            "openai_model": "gpt-4.1-mini",
            "openai_api_key": "sk-test",
            "openai_timeout_seconds": "15",
        },
        previous_response_id="resp_1",
    )

    assert result.response_id == "chatcmpl-1"
    assert client.last_previous_response_id_invalid is True
    assert calls == ["/v1/responses", "/v1/responses", "/v1/chat/completions"]


@pytest.mark.asyncio
async def test_llm_client_falls_back_to_legacy_bearer_token_when_downstream_missing(monkeypatch):
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["authorization"] = payload["tools"][0]["authorization"]
        return httpx.Response(200, json={"id": "resp_1", "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}), "output": []})

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

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

    assert seen["authorization"] == "mcp-token"


@pytest.mark.asyncio
async def test_llm_client_normalizes_downstream_token_with_bearer_prefix(monkeypatch):
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["authorization"] = payload["tools"][0]["authorization"]
        return httpx.Response(200, json={"id": "resp_1", "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}), "output": []})

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "")

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
            downstream_authorization_token="Bearer downstream-token",
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

    assert seen["authorization"] == "downstream-token"


@pytest.mark.asyncio
async def test_llm_client_prefers_test_authorization_override(monkeypatch):
    seen: dict[str, object] = {}

    def handler(request: httpx.Request) -> httpx.Response:
        payload = json.loads(request.content.decode("utf-8"))
        seen["authorization"] = payload["tools"][0]["authorization"]
        return httpx.Response(200, json={"id": "resp_1", "output_text": json.dumps({"reply": "ok", "intent": "open_question", "score": 0.1, "action": "ask_question", "needs_human": False, "data_to_save": {}}), "output": []})

    monkeypatch.setenv("MCP_TEST_AUTHORIZATION", "Bearer TEST_MCP_AUTH_TOKEN_123456")

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
            downstream_authorization_token="downstream-token",
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

    assert seen["authorization"] == "TEST_MCP_AUTH_TOKEN_123456"


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
