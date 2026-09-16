from __future__ import annotations

import asyncio
from typing import Awaitable, Callable, TypeVar

import httpx


T = TypeVar("T")

TRANSIENT_HTTP_STATUS_CODES = {429, 500, 502, 503, 504}

NON_RETRYABLE_429_ERROR_TYPES = {
    "insufficient_quota",
}

NON_RETRYABLE_429_ERROR_CODES = {
    "credit_balance_exhausted",
    "organization_usage_limit_exceeded",
    "organization_spend_limit_exceeded",
    "project_spend_limit_exceeded",
    "billing_hard_limit_reached",
}


class LlmProviderUnavailable(RuntimeError):
    def __init__(self, kind: str, status_code: int | None = None, attempts: int = 1, retryable: bool = False) -> None:
        self.kind = kind
        self.status_code = status_code
        self.attempts = attempts
        self.retryable = retryable
        super().__init__(self._build_message())

    def _build_message(self) -> str:
        parts = [f"kind={self.kind}", f"attempts={self.attempts}"]
        if self.status_code is not None:
            parts.append(f"status_code={self.status_code}")
        return "LlmProviderUnavailable(" + ", ".join(parts) + ")"


def _http_provider_error(exc: Exception) -> tuple[str | None, str | None]:
    if not isinstance(exc, httpx.HTTPStatusError):
        return None, None

    response = exc.response
    if response is None:
        return None, None

    try:
        payload = response.json()
    except Exception:
        return None, None

    if not isinstance(payload, dict):
        return None, None

    error = payload.get("error")
    if not isinstance(error, dict):
        return None, None

    error_type = error.get("type")
    error_code = error.get("code")

    return (
        error_type if isinstance(error_type, str) else None,
        error_code if isinstance(error_code, str) else None,
    )


def classify_llm_provider_failure(exc: Exception) -> tuple[str, int | None, bool]:
    if isinstance(exc, LlmProviderUnavailable):
        return exc.kind, exc.status_code, exc.retryable

    if isinstance(exc, httpx.TimeoutException):
        return "timeout", None, True

    if isinstance(exc, (httpx.ConnectError, httpx.ReadError, httpx.RemoteProtocolError, httpx.CloseError, httpx.NetworkError)):
        return "network_error", None, True

    if isinstance(exc, httpx.HTTPStatusError):
        status_code = exc.response.status_code if exc.response is not None else None
        error_type, error_code = _http_provider_error(exc)

        if status_code == 429 and (
            error_type in NON_RETRYABLE_429_ERROR_TYPES
            or error_code in NON_RETRYABLE_429_ERROR_CODES
        ):
            kind = error_code or error_type or "http_429"
            return kind, status_code, False

        retryable = status_code in TRANSIENT_HTTP_STATUS_CODES if status_code is not None else False
        kind = error_code or (f"http_{status_code}" if status_code is not None else "http_status_error")
        return kind, status_code, retryable

    if isinstance(exc, httpx.HTTPError):
        return "http_error", None, True

    return exc.__class__.__name__, None, False


def _retry_after_seconds(exc: Exception) -> float | None:
    if not isinstance(exc, httpx.HTTPStatusError):
        return None

    response = exc.response
    if response is None:
        return None

    raw_value = response.headers.get("retry-after")
    if raw_value is None:
        return None

    try:
        value = float(raw_value.strip())
    except (TypeError, ValueError):
        return None

    return max(0.0, value)


def _retry_sleep_seconds(
    exc: Exception,
    *,
    attempt: int,
    base_delay_seconds: float,
) -> float:
    base_delay = max(0.0, float(base_delay_seconds))
    exponential_delay = base_delay * (2 ** max(0, attempt - 1))

    retry_after = _retry_after_seconds(exc)
    if retry_after is not None:
        return max(exponential_delay, retry_after)

    return exponential_delay


async def run_with_llm_provider_retries(
    operation: Callable[[], Awaitable[T]],
    max_attempts: int,
    retry_delay_seconds: float,
) -> T:
    attempts = max(1, int(max_attempts))
    delay = max(0.0, float(retry_delay_seconds))

    last_exc: Exception | None = None
    for attempt in range(1, attempts + 1):
        try:
            return await operation()
        except Exception as exc:  # noqa: BLE001 - provider boundaries are intentionally broad here
            last_exc = exc
            kind, status_code, retryable = classify_llm_provider_failure(exc)
            if not retryable or attempt >= attempts:
                raise LlmProviderUnavailable(kind=kind, status_code=status_code, attempts=attempt, retryable=retryable) from exc
            retry_delay = _retry_sleep_seconds(
                exc,
                attempt=attempt,
                base_delay_seconds=delay,
            )
            if retry_delay > 0:
                await asyncio.sleep(retry_delay)

    if last_exc is not None:
        kind, status_code, retryable = classify_llm_provider_failure(last_exc)
        raise LlmProviderUnavailable(kind=kind, status_code=status_code, attempts=attempts, retryable=retryable) from last_exc

    raise LlmProviderUnavailable(kind="provider_error", attempts=attempts, retryable=False)
