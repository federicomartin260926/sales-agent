from __future__ import annotations

import httpx
import pytest

from app.services.llm_provider_resilience import LlmProviderUnavailable, run_with_llm_provider_retries


@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_retries_transient_then_succeeds() -> None:
    attempts = 0
    request = httpx.Request("POST", "https://example.test/v1/responses")

    async def operation() -> str:
        nonlocal attempts
        attempts += 1
        if attempts == 1:
            raise httpx.ReadError("temporary read error", request=request)
        return "ok"

    result = await run_with_llm_provider_retries(operation, max_attempts=2, retry_delay_seconds=0)

    assert result == "ok"
    assert attempts == 2


@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_wraps_non_transient_without_retry() -> None:
    attempts = 0

    async def operation() -> str:
        nonlocal attempts
        attempts += 1
        raise ValueError("bad request")

    with pytest.raises(LlmProviderUnavailable) as exc_info:
        await run_with_llm_provider_retries(operation, max_attempts=2, retry_delay_seconds=0)

    assert attempts == 1
    assert exc_info.value.attempts == 1
    assert exc_info.value.retryable is False
    assert exc_info.value.status_code is None
