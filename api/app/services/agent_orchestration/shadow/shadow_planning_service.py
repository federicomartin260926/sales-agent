from __future__ import annotations

from dataclasses import asdict
import time
from typing import Any

from app.config import Settings, get_settings
from app.schemas.agent import AgentRequest
from app.services.agent_orchestration.context.context_expansion_router import ContextExpansionRouter
from app.services.agent_orchestration.debug.orchestration_trace import OrchestrationTrace
from app.services.agent_orchestration.planning.intent_planner import IntentPlannerService, PlanningParseDiagnostics
from app.services.agent_orchestration.planning.schemas import LLMPlanningResult
from app.services.agent_orchestration.tool_policy.tool_policy_service import ToolPolicyService
from app.services.backend_client import CommercialContext
from app.services.llm_client import LLMClient
from app.services.routing_resolver import RoutingContext


class ShadowPlanningService:
    def __init__(
        self,
        settings: Settings | None = None,
        llm_client: LLMClient | None = None,
        intent_planner: IntentPlannerService | None = None,
        context_router: ContextExpansionRouter | None = None,
        tool_policy: ToolPolicyService | None = None,
    ) -> None:
        self.settings = settings or get_settings()
        self.llm_client = llm_client or LLMClient(self.settings)
        self.intent_planner = intent_planner or IntentPlannerService()
        self.context_router = context_router or ContextExpansionRouter()
        self.tool_policy = tool_policy or ToolPolicyService()

    async def execute(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
    ) -> OrchestrationTrace:
        trace = OrchestrationTrace(
            tenant_id=routing.tenant_id,
            conversation_id=self._conversation_id(payload),
            external_conversation_id=payload.conversation.external_id,
            inbound_message=payload.message.text,
        )

        if not self._enabled():
            trace.add_step(
                step_type="shadow_planning_disabled",
                input_context_keys=["feature_flag"],
                enabled_tools=[],
                output={"enabled": False},
            )
            return trace

        try:
            planning_messages = self.intent_planner.build_planning_messages(
                current_message=payload.message.text or "",
                recent_messages=payload.conversation.last_messages[-3:],
                conversation_summary=payload.conversation.summary,
            )
            trace.add_step(
                step_type="llm_intent_planning_input",
                input_context_keys=self._planning_input_keys(payload),
                enabled_tools=[],
                output={"message_count": len(planning_messages)},
            )

            started_at = time.perf_counter()
            planning_result, planning_parse_diagnostics = await self._run_planning_llm(planning_messages)
            planning_latency_ms = int(round((time.perf_counter() - started_at) * 1000))
            trace.add_step(
                step_type="llm_intent_planning",
                input_context_keys=self._planning_input_keys(payload),
                enabled_tools=[],
                output=planning_result.model_dump(exclude_none=True),
                latency_ms=planning_latency_ms,
            )

            if planning_parse_diagnostics is not None:
                trace.add_step(
                    step_type="llm_intent_planning_parse_diagnostics",
                    input_context_keys=self._planning_input_keys(payload),
                    enabled_tools=[],
                    output=asdict(planning_parse_diagnostics),
                )

            context_plan = self.context_router.build(planning_result)
            tool_policy = self.tool_policy.evaluate(planning_result)
            trace.add_step(
                step_type="sa_context_policy",
                input_context_keys=["planning_result", "context_request", "tool_request"],
                enabled_tools=tool_policy.lookup_tools_enabled,
                output={
                    "context_plan": context_plan.model_dump(exclude_none=True),
                    "tool_policy": tool_policy.model_dump(exclude_none=True),
                },
            )
            return trace
        except Exception as exc:
            trace.add_step(
                step_type="shadow_planning_error",
                input_context_keys=self._planning_input_keys(payload),
                enabled_tools=[],
                output={"error_type": exc.__class__.__name__},
                error=str(exc),
            )
            return trace

    async def _run_planning_llm(self, planning_messages: list[dict[str, str]]) -> tuple[LLMPlanningResult, PlanningParseDiagnostics | None]:
        configuration = await self.llm_client.resolve_configuration()
        provider = str(configuration.get("llm_default_profile", self.settings.llm_provider)).strip().lower()
        if provider == "" or provider == "heuristic":
            return self.intent_planner.fallback_unknown("planning_provider_unavailable"), None

        if provider not in {"openai", "ollama"}:
            return self.intent_planner.fallback_unknown("planning_provider_unsupported"), None

        if provider == "openai" and any(configuration.get(key, "").strip() == "" for key in ("openai_base_url", "openai_model", "openai_api_key")):
            return self.intent_planner.fallback_unknown("planning_openai_configuration_incomplete"), None

        if provider == "ollama" and any(configuration.get(key, "").strip() == "" for key in ("ollama_base_url", "ollama_model")):
            return self.intent_planner.fallback_unknown("planning_ollama_configuration_incomplete"), None

        system_prompt = planning_messages[0]["content"]
        user_prompt = planning_messages[1]["content"]
        result = await self.llm_client.generate(provider, system_prompt, user_prompt, configuration)
        raw_llm_output = result.content
        parsed_planning_result, planning_parse_diagnostics = self.intent_planner.parse_planning_result_with_diagnostics(raw_llm_output)
        return parsed_planning_result, planning_parse_diagnostics

    def _enabled(self) -> bool:
        return bool(getattr(self.settings, "new_llm_orchestration_enabled", False))

    def _planning_input_keys(self, payload: AgentRequest) -> list[str]:
        keys = ["current_message", "recent_messages"]
        if payload.conversation.summary is not None and payload.conversation.summary.strip() != "":
            keys.append("conversation_summary")
        return keys

    def _conversation_id(self, payload: AgentRequest) -> str | None:
        if isinstance(payload.conversation.external_id, str) and payload.conversation.external_id.strip() != "":
            return payload.conversation.external_id.strip()
        return None
