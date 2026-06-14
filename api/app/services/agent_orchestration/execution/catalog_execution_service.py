from __future__ import annotations

import json
import re
import time
from typing import Any

from pydantic import BaseModel, ConfigDict, Field

from app.config import Settings, get_settings
from app.schemas.agent import AgentRequest
from app.schemas.llm import McpRemoteConfig
from app.services.agent_orchestration.context.context_expansion_router import ContextExpansionPlan
from app.services.agent_orchestration.debug.orchestration_trace import OrchestrationTrace
from app.services.agent_orchestration.debug.trace_sanitizer import sanitize_value
from app.services.agent_orchestration.planning.schemas import LLMPlanningResult
from app.services.agent_orchestration.tool_policy.tool_policy_service import ToolPolicyDecision
from app.services.backend_client import CommercialContext
from app.services.llm_client import LLMClient
from app.services.routing_resolver import RoutingContext


class CatalogExecutionOutcome(BaseModel):
    model_config = ConfigDict(extra="forbid")

    attempted: bool = False
    ok: bool = False
    reply: str | None = None
    provider: str | None = None
    model: str | None = None
    response_id: str | None = None
    latency_ms: int | None = None
    fallback_reason: str | None = None
    error_type: str | None = None
    error_message: str | None = None
    raw_content_preview: str | None = None
    planning: dict[str, Any] = Field(default_factory=dict)
    context_plan: dict[str, Any] = Field(default_factory=dict)
    tool_policy: dict[str, Any] = Field(default_factory=dict)
    mcp_allowed_tools: list[str] = Field(default_factory=list)
    mcp_tool_traces: list[dict[str, Any]] = Field(default_factory=list)
    response_payload: dict[str, Any] = Field(default_factory=dict)
    trace_id: str | None = None

    def to_safe_dict(self) -> dict[str, Any]:
        return sanitize_value(self.model_dump(exclude_none=True))


class CatalogExecutionService:
    def __init__(self, settings: Settings | None = None, llm_client: LLMClient | None = None) -> None:
        self.settings = settings or get_settings()
        self.llm_client = llm_client or LLMClient(self.settings)

    async def execute(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
        shadow_trace: OrchestrationTrace,
        mcp_config: McpRemoteConfig | None,
        previous_response_id: str | None = None,
    ) -> CatalogExecutionOutcome:
        planning_result = self._planning_result_from_trace(shadow_trace)
        context_plan = self._context_plan_from_trace(shadow_trace)
        tool_policy = self._tool_policy_from_trace(shadow_trace)

        if planning_result is None:
            return self._declined("planning_result_missing", shadow_trace, planning_result, context_plan, tool_policy, mcp_config)

        if backend_context is None or backend_context.tenant.id.strip() == "":
            return self._declined("backend_context_missing", shadow_trace, planning_result, context_plan, tool_policy, mcp_config)

        eligibility_reason = self._eligibility_reason(planning_result, context_plan, tool_policy, mcp_config)
        if eligibility_reason is not None:
            return self._declined(eligibility_reason, shadow_trace, planning_result, context_plan, tool_policy, mcp_config)

        allowed_tools = ["services_search"]
        filtered_mcp_config = mcp_config.model_copy(update={"allowed_tools": allowed_tools}) if mcp_config is not None else None
        if filtered_mcp_config is None:
            return self._declined("mcp_config_missing", shadow_trace, planning_result, context_plan, tool_policy, mcp_config)

        configuration = await self.llm_client.resolve_configuration()
        provider = str(configuration.get("llm_default_profile", self.settings.llm_provider)).strip().lower()
        if provider != "openai":
            return self._declined("catalog_execution_provider_unsupported", shadow_trace, planning_result, context_plan, tool_policy, filtered_mcp_config)

        system_prompt, user_prompt = self._build_prompts(
            payload=payload,
            routing=routing,
            backend_context=backend_context,
            contact_context=contact_context,
            planning_result=planning_result,
            context_plan=context_plan,
            tool_policy=tool_policy,
        )

        started_at = time.perf_counter()
        try:
            llm_result = await self.llm_client.generate_with_mcp(
                provider,
                system_prompt,
                user_prompt,
                filtered_mcp_config,
                configuration=configuration,
                previous_response_id=previous_response_id,
                tool_choice="required",
                parallel_tool_calls=False,
            )
        except Exception as exc:
            return CatalogExecutionOutcome(
                attempted=True,
                ok=False,
                fallback_reason="catalog_execution_llm_failed",
                error_type=exc.__class__.__name__,
                error_message=self._sanitize_error_message(str(exc)),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                mcp_allowed_tools=allowed_tools,
                trace_id=shadow_trace.trace_id,
            )

        latency_ms = int(round((time.perf_counter() - started_at) * 1000))
        parsed_payload, parse_error = self._extract_json_payload(llm_result.content)
        if parsed_payload is None:
            return CatalogExecutionOutcome(
                attempted=True,
                ok=False,
                provider=llm_result.provider,
                model=llm_result.model,
                response_id=llm_result.response_id,
                latency_ms=latency_ms,
                fallback_reason="catalog_execution_response_parse_failed",
                error_type=parse_error or "json_decode_error",
                error_message="Catalog execution response did not contain a usable JSON object.",
                raw_content_preview=self._preview_raw_content(llm_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                mcp_allowed_tools=allowed_tools,
                mcp_tool_traces=self._safe_tool_traces(llm_result.tool_traces),
                trace_id=shadow_trace.trace_id,
            )

        reply = parsed_payload.get("reply")
        if not isinstance(reply, str) or reply.strip() == "":
            return CatalogExecutionOutcome(
                attempted=True,
                ok=False,
                provider=llm_result.provider,
                model=llm_result.model,
                response_id=llm_result.response_id,
                latency_ms=latency_ms,
                fallback_reason="catalog_execution_reply_missing",
                error_type="missing_reply",
                error_message="Catalog execution response did not include a usable reply.",
                raw_content_preview=self._preview_raw_content(llm_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                mcp_allowed_tools=allowed_tools,
                mcp_tool_traces=self._safe_tool_traces(llm_result.tool_traces),
                response_payload=sanitize_value(parsed_payload),
                trace_id=shadow_trace.trace_id,
            )

        if not self._has_services_search_trace(llm_result.tool_traces):
            return CatalogExecutionOutcome(
                attempted=True,
                ok=False,
                provider=llm_result.provider,
                model=llm_result.model,
                response_id=llm_result.response_id,
                latency_ms=latency_ms,
                fallback_reason="services_search_trace_missing",
                error_type="missing_services_search_trace",
                error_message="Catalog execution completed without a services_search trace.",
                raw_content_preview=self._preview_raw_content(llm_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                mcp_allowed_tools=allowed_tools,
                mcp_tool_traces=self._safe_tool_traces(llm_result.tool_traces),
                response_payload=sanitize_value(parsed_payload),
                trace_id=shadow_trace.trace_id,
            )

        return CatalogExecutionOutcome(
            attempted=True,
            ok=True,
            reply=reply.strip(),
            provider=llm_result.provider,
            model=llm_result.model,
            response_id=llm_result.response_id,
            latency_ms=latency_ms,
            planning=planning_result.model_dump(exclude_none=True),
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            mcp_allowed_tools=allowed_tools,
            mcp_tool_traces=self._safe_tool_traces(llm_result.tool_traces),
            response_payload=sanitize_value(parsed_payload),
            trace_id=shadow_trace.trace_id,
        )

    def _declined(
        self,
        reason: str,
        shadow_trace: OrchestrationTrace,
        planning_result: LLMPlanningResult | None,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
        mcp_config: McpRemoteConfig | None,
    ) -> CatalogExecutionOutcome:
        return CatalogExecutionOutcome(
            attempted=False,
            ok=False,
            fallback_reason=reason,
            planning=planning_result.model_dump(exclude_none=True) if planning_result is not None else {},
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            mcp_allowed_tools=list(mcp_config.allowed_tools) if mcp_config is not None else [],
            trace_id=shadow_trace.trace_id,
        )

    def _eligibility_reason(
        self,
        planning_result: LLMPlanningResult,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
        mcp_config: McpRemoteConfig | None,
    ) -> str | None:
        if planning_result.domain != "catalog":
            return "planning_domain_not_catalog"

        if planning_result.intent not in {"ask_product_or_service_info", "catalog_search"}:
            return "planning_intent_not_catalog"

        if planning_result.action_candidate not in {"search_catalog", "answer_directly"}:
            return "planning_action_candidate_not_catalog"

        if planning_result.clarification.needed:
            return "clarification_requested"

        if planning_result.risk_flags.low_confidence:
            return "low_confidence"

        if not context_plan.include_catalog_context:
            return "catalog_context_not_requested"

        if "services_search" not in planning_result.tool_request.lookup_tools:
            return "services_search_not_requested"

        if planning_result.tool_request.write_tools != []:
            return "write_tools_requested"

        if tool_policy.write_tools_enabled != []:
            return "write_tools_enabled"

        if "services_search" not in tool_policy.lookup_tools_enabled:
            return "services_search_blocked_by_policy"

        if mcp_config is None or not mcp_config.enabled:
            return "mcp_disabled"

        if not isinstance(mcp_config.server_label, str) or mcp_config.server_label.strip() == "":
            return "mcp_server_label_missing"

        if not isinstance(mcp_config.server_url, str) or mcp_config.server_url.strip() == "":
            return "mcp_server_url_missing"

        if "services_search" not in [tool.strip() for tool in mcp_config.allowed_tools if tool.strip() != ""]:
            return "services_search_not_available"

        return None

    def _build_prompts(
        self,
        *,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext,
        contact_context: dict[str, Any] | None,
        planning_result: LLMPlanningResult,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres una capa experimental de ejecución de catálogo. "
            "Esta llamada solo puede usar services_search. "
            "No uses appointment_confirm, appointment_reschedule, appointment_cancel ni crm_contact_submit. "
            "Devuelve únicamente un objeto JSON válido con estas claves: reply, reason y items. "
            "No uses markdown, no añadas texto explicativo y no incluyas campos fuera de ese contrato. "
            "Si hay resultados, responde en español con una propuesta breve, concreta y útil. "
            "Si no hay resultados suficientes, explica la limitación de forma breve y pide una aclaración mínima."
        )
        user_payload: dict[str, Any] = {
            "current_message": payload.message.text or "",
            "routing": {
                "tenant_id": routing.tenant_id,
                "tenant_slug": routing.tenant_slug,
            },
            "tenant": {
                "id": backend_context.tenant.id,
                "name": backend_context.tenant.name,
                "slug": backend_context.tenant.slug,
                "business_context": backend_context.tenant.business_context,
            },
            "planning": planning_result.model_dump(exclude_none=True),
            "context_plan": context_plan.model_dump(exclude_none=True),
            "tool_policy": tool_policy.model_dump(exclude_none=True),
            "service_hint": {
                "service_name": planning_result.entities.service_name,
                "query": planning_result.entities.query or payload.message.text,
                "date": planning_result.entities.date,
                "time_of_day": planning_result.entities.time_of_day,
            },
        }
        if contact_context is not None:
            user_payload["contact_context"] = contact_context

        return system_prompt, json.dumps(user_payload, ensure_ascii=False)

    def _planning_result_from_trace(self, trace: OrchestrationTrace) -> LLMPlanningResult | None:
        planning_output = self._trace_step_output(trace, "llm_intent_planning")
        if not isinstance(planning_output, dict):
            return None

        try:
            return LLMPlanningResult.model_validate(planning_output)
        except Exception:
            return None

    def _context_plan_from_trace(self, trace: OrchestrationTrace) -> ContextExpansionPlan:
        raw = self._trace_step_nested_output(trace, "sa_context_policy", "context_plan")
        if isinstance(raw, dict):
            try:
                return ContextExpansionPlan.model_validate(raw)
            except Exception:
                pass

        return ContextExpansionPlan()

    def _tool_policy_from_trace(self, trace: OrchestrationTrace) -> ToolPolicyDecision:
        raw = self._trace_step_nested_output(trace, "sa_context_policy", "tool_policy")
        if isinstance(raw, dict):
            try:
                return ToolPolicyDecision.model_validate(raw)
            except Exception:
                pass

        return ToolPolicyDecision()

    def _trace_step_output(self, trace: OrchestrationTrace, step_type: str) -> dict[str, Any] | None:
        for step in trace.steps:
            if step.step_type != step_type:
                continue
            if isinstance(step.output, dict):
                return step.output
        return None

    def _trace_step_nested_output(self, trace: OrchestrationTrace, step_type: str, key: str) -> dict[str, Any] | None:
        step_output = self._trace_step_output(trace, step_type)
        if not isinstance(step_output, dict):
            return None

        nested = step_output.get(key)
        if isinstance(nested, dict):
            return nested

        return None

    def _extract_json_payload(self, raw: str) -> tuple[dict[str, Any] | None, str | None]:
        candidate = self._strip_markdown_fence(raw)
        if candidate is not None:
            parsed = self._try_parse_json(candidate)
            if isinstance(parsed, dict):
                return parsed, None

        parsed = self._try_parse_json(raw)
        if isinstance(parsed, dict):
            return parsed, None

        extracted = self._extract_balanced_json_object(raw)
        if extracted is None:
            return None, "json_decode_error"

        parsed = self._try_parse_json(extracted)
        if isinstance(parsed, dict):
            return parsed, None

        return None, "json_decode_error"

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

    def _preview_raw_content(self, text: str) -> str:
        compact = " ".join(text.strip().split())
        compact = re.sub(r"\bBearer\s+[A-Za-z0-9._-]+\b", "Bearer ***REDACTED***", compact, flags=re.IGNORECASE)
        compact = re.sub(r"\bsk-[A-Za-z0-9]{16,}\b", "***REDACTED***", compact)
        if len(compact) > 240:
            compact = compact[:240].rstrip() + "…"
        return compact

    def _sanitize_error_message(self, value: str) -> str:
        compact = " ".join(value.strip().split())
        compact = re.sub(r"\bBearer\s+[A-Za-z0-9._-]+\b", "Bearer ***REDACTED***", compact, flags=re.IGNORECASE)
        compact = re.sub(r"\bsk-[A-Za-z0-9]{16,}\b", "***REDACTED***", compact)
        if len(compact) > 240:
            compact = compact[:240].rstrip() + "…"
        return compact

    def _safe_tool_traces(self, traces: list[Any]) -> list[dict[str, Any]]:
        sanitized: list[dict[str, Any]] = []
        for trace in traces:
            if hasattr(trace, "model_dump"):
                sanitized.append(sanitize_value(trace.model_dump(exclude_none=True)))
            elif isinstance(trace, dict):
                sanitized.append(sanitize_value(trace))
        return sanitized

    def _has_services_search_trace(self, traces: list[Any]) -> bool:
        for trace in traces:
            tool_name = self._tool_trace_name(trace)
            if tool_name == "services_search":
                return True
        return False

    def _tool_trace_name(self, trace: Any) -> str | None:
        if hasattr(trace, "tool_name"):
            value = getattr(trace, "tool_name")
            if isinstance(value, str) and value.strip() != "":
                return value.strip()

        if isinstance(trace, dict):
            for key in ("tool_name", "toolName", "name"):
                value = trace.get(key)
                if isinstance(value, str) and value.strip() != "":
                    return value.strip()

        return None
