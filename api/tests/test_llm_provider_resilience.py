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


@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_uses_exponential_backoff(monkeypatch) -> None:
    attempts = 0
    sleeps: list[float] = []

    async def fake_sleep(seconds: float) -> None:
        sleeps.append(seconds)

    async def operation() -> str:
        nonlocal attempts
        attempts += 1

        if attempts < 4:
            request = httpx.Request("POST", "https://api.openai.com/v1/responses")
            response = httpx.Response(429, request=request)
            raise httpx.HTTPStatusError(
                "rate limited",
                request=request,
                response=response,
            )

        return "ok"

    monkeypatch.setattr(
        "app.services.llm_provider_resilience.asyncio.sleep",
        fake_sleep,
    )

    result = await run_with_llm_provider_retries(
        operation,
        max_attempts=4,
        retry_delay_seconds=5,
    )

    assert result == "ok"
    assert attempts == 4
    assert sleeps == [5.0, 10.0, 20.0]


@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_respects_retry_after(monkeypatch) -> None:
    attempts = 0
    sleeps: list[float] = []

    async def fake_sleep(seconds: float) -> None:
        sleeps.append(seconds)

    async def operation() -> str:
        nonlocal attempts
        attempts += 1

        if attempts == 1:
            request = httpx.Request("POST", "https://api.openai.com/v1/responses")
            response = httpx.Response(
                429,
                headers={"Retry-After": "12"},
                request=request,
            )
            raise httpx.HTTPStatusError(
                "rate limited",
                request=request,
                response=response,
            )

        return "ok"

    monkeypatch.setattr(
        "app.services.llm_provider_resilience.asyncio.sleep",
        fake_sleep,
    )

    result = await run_with_llm_provider_retries(
        operation,
        max_attempts=4,
        retry_delay_seconds=5,
    )

    assert result == "ok"
    assert attempts == 2
    assert sleeps == [12.0]

@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_does_not_retry_exhausted_credit(monkeypatch) -> None:
    attempts = 0
    sleeps: list[float] = []

    async def fake_sleep(seconds: float) -> None:
        sleeps.append(seconds)

    async def operation() -> str:
        nonlocal attempts
        attempts += 1

        request = httpx.Request(
            "POST",
            "https://api.openai.com/v1/responses",
        )
        response = httpx.Response(
            429,
            request=request,
            json={
                "error": {
                    "message": "You have no credits remaining.",
                    "type": "insufficient_quota",
                    "code": "credit_balance_exhausted",
                }
            },
        )
        raise httpx.HTTPStatusError(
            "quota exhausted",
            request=request,
            response=response,
        )

    monkeypatch.setattr(
        "app.services.llm_provider_resilience.asyncio.sleep",
        fake_sleep,
    )

    with pytest.raises(LlmProviderUnavailable) as exc_info:
        await run_with_llm_provider_retries(
            operation,
            max_attempts=4,
            retry_delay_seconds=5,
        )

    assert attempts == 1
    assert sleeps == []
    assert exc_info.value.kind == "credit_balance_exhausted"
    assert exc_info.value.status_code == 429
    assert exc_info.value.attempts == 1
    assert exc_info.value.retryable is False


@pytest.mark.asyncio
async def test_run_with_llm_provider_retries_still_retries_transient_429(monkeypatch) -> None:
    attempts = 0
    sleeps: list[float] = []

    async def fake_sleep(seconds: float) -> None:
        sleeps.append(seconds)

    async def operation() -> str:
        nonlocal attempts
        attempts += 1

        if attempts == 1:
            request = httpx.Request(
                "POST",
                "https://api.openai.com/v1/responses",
            )
            response = httpx.Response(
                429,
                request=request,
                json={
                    "error": {
                        "message": "Rate limit reached.",
                        "type": "rate_limit_error",
                        "code": "rate_limit_exceeded",
                    }
                },
            )
            raise httpx.HTTPStatusError(
                "rate limited",
                request=request,
                response=response,
            )

        return "ok"

    monkeypatch.setattr(
        "app.services.llm_provider_resilience.asyncio.sleep",
        fake_sleep,
    )

    result = await run_with_llm_provider_retries(
        operation,
        max_attempts=4,
        retry_delay_seconds=5,
    )

    assert result == "ok"
    assert attempts == 2
    assert sleeps == [5.0]
