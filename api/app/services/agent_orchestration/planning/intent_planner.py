from __future__ import annotations

import json
from typing import Any

from app.services.agent_orchestration.planning.prompts import build_planning_system_prompt
from app.services.agent_orchestration.planning.schemas import LLMPlanningResult


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
        try:
            if isinstance(raw, str):
                parsed = json.loads(raw)
            else:
                parsed = raw
        except json.JSONDecodeError:
            return self.fallback_unknown("planning_result_invalid_json")

        if not isinstance(parsed, dict) or parsed == {}:
            return self.fallback_unknown("planning_result_not_a_dict")

        try:
            return LLMPlanningResult.model_validate(parsed)
        except Exception:
            return self.fallback_unknown("planning_result_validation_failed")

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
