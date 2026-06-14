from __future__ import annotations

from dataclasses import dataclass, field
import json
import re
from typing import Any

from app.services.agent_orchestration.planning.prompts import build_planning_system_prompt
from app.services.agent_orchestration.planning.schemas import LLMPlanningResult


@dataclass(slots=True)
class PlanningParseDiagnostics:
    error_type: str
    error_message: str
    raw_content_preview: str | None = None
    validation_errors: list[str] = field(default_factory=list)


class IntentPlannerService:
    def build_planning_messages(
        self,
        current_message: str,
        recent_messages: list[str] | None = None,
        conversation_summary: str | None = None,
        extra_rules: list[str] | None = None,
    ) -> list[dict[str, str]]:
        payload: dict[str, Any] = {
            "current_message": current_message,
            "recent_messages": recent_messages or [],
        }
        if conversation_summary is not None and conversation_summary.strip() != "":
            payload["conversation_summary"] = conversation_summary.strip()

        return [
            {"role": "system", "content": build_planning_system_prompt(extra_rules)},
            {"role": "user", "content": json.dumps(payload, ensure_ascii=False)},
        ]

    def parse_planning_result(self, raw: dict[str, Any] | str) -> LLMPlanningResult:
        result, _ = self.parse_planning_result_with_diagnostics(raw)
        return result

    def parse_planning_result_with_diagnostics(
        self,
        raw: dict[str, Any] | str,
    ) -> tuple[LLMPlanningResult, PlanningParseDiagnostics | None]:
        if isinstance(raw, str):
            extracted = self._extract_json_payload(raw)
            if extracted is None:
                return self.fallback_unknown("planning_result_invalid_json"), self._build_diagnostics(
                    error_type="json_decode_error",
                    error_message="Unable to extract a JSON object from planning response.",
                    raw_content=raw,
                )

            parsed: Any = extracted
        else:
            parsed = raw

        if not isinstance(parsed, dict) or parsed == {}:
            return self.fallback_unknown("planning_result_not_a_dict"), self._build_diagnostics(
                error_type="validation_error",
                error_message="Planning response did not contain a JSON object.",
                raw_content=self._preview_raw_content(parsed),
            )

        try:
            return LLMPlanningResult.model_validate(parsed), None
        except Exception as exc:
            validation_errors = self._simplify_validation_errors(exc)
            return self.fallback_unknown("planning_result_validation_failed"), self._build_diagnostics(
                error_type="pydantic_validation_error",
                error_message="Planning response did not match LLMPlanningResult.",
                raw_content=self._preview_raw_content(parsed),
                validation_errors=validation_errors,
            )

    def fallback_unknown(self, reason: str) -> LLMPlanningResult:
        return LLMPlanningResult.model_validate(
            {
                "domain": "general",
                "intent": "unknown",
                "action_candidate": "no_action",
                "confidence": 0.0,
                "reason": reason.strip()[:120],
                "clarification": {
                    "needed": True,
                    "missing_fields": [],
                },
                "tool_request": {
                    "lookup_tools": [],
                    "write_tools": [],
                    "blocked_tools": [],
                    "reason": reason.strip()[:120],
                },
                "risk_flags": {
                    "low_confidence": True,
                },
            }
        )

    def _extract_json_payload(self, raw: str) -> dict[str, Any] | None:
        candidate = self._strip_markdown_fence(raw)
        if candidate is not None:
            parsed = self._try_parse_json(candidate)
            if isinstance(parsed, dict):
                return parsed

        parsed = self._try_parse_json(raw)
        if isinstance(parsed, dict):
            return parsed

        extracted = self._extract_balanced_json_object(raw)
        if extracted is None:
            return None

        parsed = self._try_parse_json(extracted)
        if isinstance(parsed, dict):
            return parsed

        return None

    def _strip_markdown_fence(self, raw: str) -> str | None:
        text = raw.strip()
        if not text.startswith("```"):
            return None

        match = re.search(r"```(?:json)?\s*(.*?)\s*```", text, flags=re.IGNORECASE | re.DOTALL)
        if match is None:
            return None

        return match.group(1).strip()

    def _extract_balanced_json_object(self, text: str) -> str | None:
        start = text.find("{")
        if start < 0:
            return None

        depth = 0
        in_string = False
        escape = False

        for index in range(start, len(text)):
            char = text[index]
            if in_string:
                if escape:
                    escape = False
                elif char == "\\":
                    escape = True
                elif char == '"':
                    in_string = False
                continue

            if char == '"':
                in_string = True
                continue

            if char == "{":
                depth += 1
                continue

            if char == "}":
                depth -= 1
                if depth == 0:
                    return text[start : index + 1].strip()

        return None

    def _try_parse_json(self, raw: str) -> Any:
        try:
            return json.loads(raw)
        except json.JSONDecodeError:
            return None

    def _build_diagnostics(
        self,
        *,
        error_type: str,
        error_message: str,
        raw_content: str | None = None,
        validation_errors: list[str] | None = None,
    ) -> PlanningParseDiagnostics:
        return PlanningParseDiagnostics(
            error_type=error_type,
            error_message=error_message[:240],
            raw_content_preview=self._sanitize_preview(raw_content) if raw_content is not None else None,
            validation_errors=list(dict.fromkeys(validation_errors or [])),
        )

    def _preview_raw_content(self, raw: Any) -> str:
        if isinstance(raw, str):
            return self._sanitize_preview(raw)

        try:
            rendered = json.dumps(raw, ensure_ascii=False, default=str)
        except Exception:
            rendered = repr(raw)

        return self._sanitize_preview(rendered)

    def _sanitize_preview(self, text: str) -> str:
        compact = " ".join(text.strip().split())
        compact = re.sub(r"\bBearer\s+[A-Za-z0-9._-]+\b", "Bearer ***REDACTED***", compact, flags=re.IGNORECASE)
        compact = re.sub(r"\bsk-[A-Za-z0-9]{16,}\b", "***REDACTED***", compact)
        compact = re.sub(
            r"\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\b",
            "***REDACTED***",
            compact,
        )
        if len(compact) > 240:
            compact = compact[:240].rstrip() + "…"
        return compact

    def _simplify_validation_errors(self, exc: Exception) -> list[str]:
        errors = getattr(exc, "errors", None)
        if callable(errors):
            try:
                raw_errors = errors()
            except Exception:
                return [exc.__class__.__name__]

            simplified: list[str] = []
            for item in raw_errors if isinstance(raw_errors, list) else []:
                if not isinstance(item, dict):
                    continue
                location = item.get("loc", ())
                if isinstance(location, tuple):
                    location_text = ".".join(str(part) for part in location)
                elif isinstance(location, list):
                    location_text = ".".join(str(part) for part in location)
                else:
                    location_text = str(location)
                message = str(item.get("msg", "")).strip()
                error_type = str(item.get("type", "")).strip()
                parts = [part for part in [location_text, message, error_type] if part]
                if parts:
                    simplified.append(" | ".join(parts))

            return list(dict.fromkeys(simplified)) or [exc.__class__.__name__]

        return [exc.__class__.__name__]
