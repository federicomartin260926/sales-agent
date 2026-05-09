from __future__ import annotations

from typing import Any


class LLMContextHelper:
    MAX_CONVERSATION_MESSAGES = 8
    MAX_CONVERSATION_CHARS = 4000
    MAX_TEXT_CHARS = 1200
    MAX_JSON_ITEMS = 12
    MAX_JSON_DEPTH = 2
    TRUNCATION_MARKER = "[truncated]"

    def sanitize_text(self, value: Any, max_chars: int | None = None) -> str | None:
        if not isinstance(value, str):
            return None

        text = value.strip()
        if text == "":
            return None

        limit = max_chars if max_chars is not None else self.MAX_TEXT_CHARS
        if limit <= 0 or len(text) <= limit:
            return text

        marker = f" {self.TRUNCATION_MARKER}"
        if limit <= len(marker):
            return text[:limit]

        return text[: limit - len(marker)] + marker

    def sanitize_jsonish(
        self,
        value: Any,
        *,
        max_depth: int | None = None,
        max_items: int | None = None,
        max_string_chars: int | None = None,
    ) -> Any:
        depth = self.MAX_JSON_DEPTH if max_depth is None else max_depth
        items = self.MAX_JSON_ITEMS if max_items is None else max_items
        string_chars = self.MAX_TEXT_CHARS if max_string_chars is None else max_string_chars

        return self._sanitize_jsonish(value, depth, items, string_chars)

    def build_conversation_payload(self, summary: Any, last_messages: list[str] | None) -> dict[str, Any]:
        messages, truncated = self.limit_history(last_messages or [])

        payload: dict[str, Any] = {
            "summary": self.sanitize_text(summary, max_chars=self.MAX_TEXT_CHARS),
            "last_messages": messages,
        }
        if truncated:
            payload["history_truncated"] = True

        return payload

    def limit_history(self, last_messages: list[str]) -> tuple[list[str], bool]:
        normalized_messages = [
            message.strip()
            for message in last_messages
            if isinstance(message, str) and message.strip() != ""
        ]
        if normalized_messages == []:
            return [], False

        recent_messages = normalized_messages[-self.MAX_CONVERSATION_MESSAGES :]
        truncated = len(normalized_messages) > len(recent_messages)

        kept_reversed: list[str] = []
        remaining_chars = self.MAX_CONVERSATION_CHARS
        for message in reversed(recent_messages):
            if remaining_chars <= 0:
                truncated = True
                break

            if len(message) <= remaining_chars:
                kept_reversed.append(message)
                remaining_chars -= len(message)
                continue

            kept_reversed.append(self.sanitize_text(message, max_chars=remaining_chars) or "")
            truncated = True
            remaining_chars = 0
            break

        kept_messages = list(reversed([message for message in kept_reversed if message.strip() != ""]))
        if kept_messages != recent_messages:
            truncated = True

        return kept_messages, truncated

    def _sanitize_jsonish(self, value: Any, max_depth: int, max_items: int, max_string_chars: int) -> Any:
        if value is None or isinstance(value, (bool, int, float)):
            return value

        if isinstance(value, str):
            return self.sanitize_text(value, max_chars=max_string_chars)

        if max_depth <= 0:
            return self.sanitize_text(str(value), max_chars=max_string_chars)

        if isinstance(value, list):
            sanitized_items = [
                self._sanitize_jsonish(item, max_depth - 1, max_items, max_string_chars)
                for item in value[:max_items]
            ]
            if len(value) > max_items:
                sanitized_items.append(self.TRUNCATION_MARKER)

            return sanitized_items

        if isinstance(value, dict):
            sanitized: dict[str, Any] = {}
            for index, (key, item) in enumerate(value.items()):
                if index >= max_items:
                    sanitized["_truncated"] = True
                    break

                sanitized[str(key)] = self._sanitize_jsonish(item, max_depth - 1, max_items, max_string_chars)

            return sanitized

        return self.sanitize_text(str(value), max_chars=max_string_chars)
