from __future__ import annotations

import httpx
import pytest

from app.config import Settings
from app.schemas.llm import McpRemoteConfig
from app.services.llm_client import LLMClient
from app.services.llm_provider_resilience import LlmProviderUnavailable


@pytest.mark.asyncio
async def test_generate_with_mcp_retries_responses_only() -> None:
    request_paths: list[str] = []

    async def handler(request: httpx.Request) -> httpx.Response:
        request_paths.append(request.url.path)
        if not request.url.path.endswith("/responses"):
            raise AssertionError(f"Unexpected path: {request.url.path}")

        return httpx.Response(503, json={"error": "temporary"}, request=request)

    transport = httpx.MockTransport(handler)
    settings = Settings()
    client = LLMClient(settings, transport=transport)
    config = {
        "openai_api_key": "test-key",
        "openai_base_url": "https://api.openai.test/v1",
        "openai_model": "gpt-4o-mini",
        "openai_responses_timeout_seconds": "5",
        "openai_responses_max_attempts": "2",
        "openai_responses_retry_delay_seconds": "0",
    }
    mcp_config = McpRemoteConfig(enabled=True, server_label="mcp", server_url="https://mcp.test", allowed_tools=[])

    with pytest.raises(LlmProviderUnavailable) as exc_info:
        await client.generate_with_mcp(
            "openai",
            "system prompt",
            '{"task":"check"}',
            mcp_config,
            configuration=config,
            single_tool_call=True,
            max_tool_rounds=1,
        )

    assert exc_info.value.attempts == 2
    assert exc_info.value.retryable is True
    assert all(path.endswith("/responses") for path in request_paths)
    assert len(request_paths) == 2
