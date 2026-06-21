from __future__ import annotations

import time
from typing import Any

from app.schemas.agent import AgentResponse
from app.services.agent_orchestration.schemas import IntentPlan, LLMFinalResponse, ToolPlan
from app.services.llm_provider_resilience import LlmProviderUnavailable
from app.services.routing_resolver import RoutingContext


class AgentTurnResponseBuilder:
    def build_response(
        self,
        final: LLMFinalResponse,
        plan: IntentPlan,
        tool_plan: ToolPlan,
        llm_result: Any | None,
        started_at: float,
        routing: RoutingContext | None = None,
        provider_failure: LlmProviderUnavailable | None = None,
    ) -> AgentResponse:
        structured_data = final.structured_data.model_dump(exclude_none=True) if hasattr(final, "structured_data") else {}
        tool_results = [trace.model_dump(exclude_none=True) for trace in getattr(llm_result, "tool_traces", [])] if llm_result is not None else []
        technical_metadata = self.build_technical_metadata(provider_failure)

        data_to_save: dict[str, Any] = {
            "orchestration_version": "llm_context_tools_v3",
            "intent_plan": plan.model_dump(exclude_none=True),
            "tool_plan": tool_plan.model_dump(exclude_none=True),
            "mcp_tool_traces": tool_results,
            "tool_results": tool_results,
            "structured_data": structured_data,
            "next_expected": final.next_expected.model_dump(exclude_none=True) if final.next_expected is not None else {},
        }
        data_to_save.update(final.data_to_save or {})
        if routing is not None:
            data_to_save["conversation_id"] = routing.conversation_id
            data_to_save["tenant_id"] = routing.tenant_id

        contact_context_result = self._contact_context_result(llm_result)
        if contact_context_result is not None:
            data_to_save["backend_context"] = {"contact_context": contact_context_result}

        if final.required_next_action:
            data_to_save["required_next_action"] = final.required_next_action
        data_to_save["technical_metadata"] = technical_metadata

        reply = final.reply.strip() if isinstance(final.reply, str) and final.reply.strip() else "¿Puedes repetirlo de otra forma?"

        return AgentResponse(
            reply=reply,
            intent=final.intent or plan.intent or "unknown",
            score=final.score,
            action=final.action,
            needs_human=bool(final.needs_human),
            data_to_save=data_to_save,
            provider=getattr(llm_result, "provider", None) if llm_result is not None else None,
            model=getattr(llm_result, "model", None) if llm_result is not None else None,
            latency_ms=int((time.monotonic() - started_at) * 1000),
        )

    def build_technical_metadata(self, provider_failure: LlmProviderUnavailable | None = None) -> dict[str, Any]:
        if provider_failure is None:
            return {}

        metadata: dict[str, Any] = {
            "llm_provider_failure": True,
            "failure_kind": provider_failure.kind,
            "attempts": provider_failure.attempts,
        }
        if provider_failure.status_code is not None:
            metadata["status_code"] = provider_failure.status_code
        return metadata

    def _contact_context_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "contact_context")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        post_processed = parsed_output is not None or trace_status is not None or trace_error_code is not None
        if not post_processed:
            return None

        data: dict[str, Any] = {}
        if isinstance(parsed_output, dict):
            contact_context = parsed_output.get("contact_context")
            if isinstance(contact_context, dict):
                data = dict(contact_context)
            elif parsed_output != {}:
                data = dict(parsed_output)

        if trace_status is not None:
            data.setdefault("status", trace_status)
        if trace_error_code is not None:
            data.setdefault("error_code", trace_error_code)

        return data if data != {} else None

    def _latest_mcp_call_output(self, llm_result: Any | None, tool_name: str) -> tuple[Any | None, dict[str, Any] | None]:
        if llm_result is None:
            return None, None

        tool_traces = getattr(llm_result, "tool_traces", [])
        if not isinstance(tool_traces, list) or tool_traces == []:
            return None, None

        for trace in reversed(tool_traces):
            trace_type = self._clean(getattr(trace, "type", None))
            candidate_tool_name = self._clean(getattr(trace, "tool_name", None))
            if trace_type != "mcp_call" or candidate_tool_name != tool_name:
                continue

            parsed_output = self._tool_trace_output_dict(trace)
            if parsed_output is None and self._clean(getattr(trace, "status", None)) is None and self._clean(getattr(trace, "error_code", None)) is None:
                return trace, None

            return trace, parsed_output

        return None, None

    def _tool_trace_output_dict(self, trace: Any) -> dict[str, Any] | None:
        output = getattr(trace, "output", None)
        if isinstance(output, dict):
            return output
        if isinstance(output, str):
            try:
                import json

                parsed_output = json.loads(output)
            except Exception:
                return None
            if isinstance(parsed_output, dict):
                return parsed_output
        return None

    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None
