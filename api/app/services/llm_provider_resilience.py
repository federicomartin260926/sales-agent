from __future__ import annotations

import asyncio
from typing import Awaitable, Callable, TypeVar

import httpx


T = TypeVar("T")

TRANSIENT_HTTP_STATUS_CODES = {429, 500, 502, 503, 504}


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


def classify_llm_provider_failure(exc: Exception) -> tuple[str, int | None, bool]:
    if isinstance(exc, LlmProviderUnavailable):
        return exc.kind, exc.status_code, exc.retryable

    if isinstance(exc, httpx.TimeoutException):
        return "timeout", None, True

    if isinstance(exc, (httpx.ConnectError, httpx.ReadError, httpx.RemoteProtocolError, httpx.CloseError, httpx.NetworkError)):
        return "network_error", None, True

    if isinstance(exc, httpx.HTTPStatusError):
        status_code = exc.response.status_code if exc.response is not None else None
        retryable = status_code in TRANSIENT_HTTP_STATUS_CODES if status_code is not None else False
        kind = f"http_{status_code}" if status_code is not None else "http_status_error"
        return kind, status_code, retryable

    if isinstance(exc, httpx.HTTPError):
        return "http_error", None, True

    return exc.__class__.__name__, None, False


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
            if delay > 0:
                await asyncio.sleep(delay)

    if last_exc is not None:
        kind, status_code, retryable = classify_llm_provider_failure(last_exc)
        raise LlmProviderUnavailable(kind=kind, status_code=status_code, attempts=attempts, retryable=retryable) from last_exc

    raise LlmProviderUnavailable(kind="provider_error", attempts=attempts, retryable=False)
