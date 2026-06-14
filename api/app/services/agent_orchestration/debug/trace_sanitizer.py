from __future__ import annotations

from collections.abc import Mapping, Sequence
from typing import Any


REDACTION_KEYS = ("token", "secret", "authorization", "password")
EXACT_REDACTION_KEYS = {"authorization", "bearer_token", "bearertoken", "downstream_authorization", "downstream_authorization_token"}
REDACTION_VALUE = "***REDACTED***"


def _should_redact_key(key: str) -> bool:
    normalized = key.lower()
    if normalized in EXACT_REDACTION_KEYS:
        return True
    return any(fragment in normalized for fragment in REDACTION_KEYS)


def sanitize_value(value: Any) -> Any:
    if isinstance(value, Mapping):
        sanitized: dict[str, Any] = {}
        for key, item in value.items():
            if isinstance(key, str) and _should_redact_key(key):
                sanitized[key] = REDACTION_VALUE
            else:
                sanitized[key] = sanitize_value(item)
        return sanitized

    if isinstance(value, list):
        return [sanitize_value(item) for item in value]

    if isinstance(value, tuple):
        return [sanitize_value(item) for item in value]

    if isinstance(value, set):
        return [sanitize_value(item) for item in sorted(value, key=lambda item: repr(item))]

    return value
