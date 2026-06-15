from __future__ import annotations

import json
from datetime import datetime
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
from app.services.llm_prompt_builder import LLMPromptBuilder
from app.services.routing_resolver import RoutingContext


class SlotSelectionExecutionOutcome(BaseModel):
    model_config = ConfigDict(extra="forbid")

    attempted: bool = False
    ok: bool = False
    reply: str | None = None
    fallback_reason: str | None = None
    planning: dict[str, Any] = Field(default_factory=dict)
    context_plan: dict[str, Any] = Field(default_factory=dict)
    tool_policy: dict[str, Any] = Field(default_factory=dict)
    appointment_context: dict[str, Any] = Field(default_factory=dict)
    offered_slots: list[dict[str, Any]] = Field(default_factory=list)
    selected_slot: dict[str, Any] | None = None
    selected_slot_match_count: int | None = None
    selected_slot_ambiguous: bool = False
    selected_slot_ambiguity_options: list[str] = Field(default_factory=list)
    selection_mode: str | None = None
    response_payload: dict[str, Any] = Field(default_factory=dict)
    trace_id: str | None = None

    def to_safe_dict(self) -> dict[str, Any]:
        return sanitize_value(self.model_dump(exclude_none=True))


class SlotSelectionExecutionService:
    def __init__(self, settings: Settings | None = None, llm_client: Any | None = None) -> None:
        self.settings = settings or get_settings()
        self.prompt_builder = LLMPromptBuilder(settings=self.settings)

    async def execute(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
        shadow_trace: OrchestrationTrace,
        mcp_config: McpRemoteConfig | None,
        previous_response_id: str | None = None,
    ) -> SlotSelectionExecutionOutcome:
        planning_result = self._planning_result_from_trace(shadow_trace)
        context_plan = self._context_plan_from_trace(shadow_trace)
        tool_policy = self._tool_policy_from_trace(shadow_trace)

        if planning_result is None:
            return self._declined("planning_result_missing", shadow_trace, planning_result, context_plan, tool_policy)

        if backend_context is None or backend_context.tenant.id.strip() == "":
            return self._declined("backend_context_missing", shadow_trace, planning_result, context_plan, tool_policy)

        eligibility_reason = self._eligibility_reason(planning_result, context_plan, tool_policy)
        if eligibility_reason is not None:
            return self._declined(eligibility_reason, shadow_trace, planning_result, context_plan, tool_policy)

        timezone, timezone_source = self._resolve_timezone_details(backend_context, contact_context)
        appointment_context = self.prompt_builder._appointment_context_payload(
            payload.conversation.context_messages,
            timezone=timezone,
            timezone_source=timezone_source,
        )
        if not isinstance(appointment_context, dict):
            return self._controlled_missing_slots_reply(shadow_trace, planning_result, context_plan, tool_policy)

        offered_slots = appointment_context.get("offered_slots")
        if not isinstance(offered_slots, list) or offered_slots == []:
            return self._controlled_missing_slots_reply(
                shadow_trace,
                planning_result,
                context_plan,
                tool_policy,
                appointment_context=appointment_context,
            )

        normalized_slots = self.prompt_builder._normalize_offered_slots(offered_slots)
        if normalized_slots == []:
            return self._controlled_missing_slots_reply(
                shadow_trace,
                planning_result,
                context_plan,
                tool_policy,
                appointment_context=appointment_context,
            )

        selected_slot, match_count, ambiguity_options, selection_mode = self._resolve_selected_slot(
            planning_result,
            normalized_slots,
        )

        if selected_slot is None and ambiguity_options != []:
            reply = self._ambiguous_slot_reply(ambiguity_options)
            return SlotSelectionExecutionOutcome(
                attempted=True,
                ok=True,
                reply=reply,
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                appointment_context=sanitize_value(appointment_context),
                offered_slots=normalized_slots,
                selected_slot_ambiguous=True,
                selected_slot_ambiguity_options=ambiguity_options,
                selected_slot_match_count=match_count,
                selection_mode=selection_mode,
                response_payload=sanitize_value(
                    {
                        "reply": reply,
                        "reason": "slot_selection_ambiguous",
                        "ambiguity_options": ambiguity_options,
                    }
                ),
                trace_id=shadow_trace.trace_id,
            )

        if selected_slot is None:
            reply = self._missing_slot_reply(planning_result, normalized_slots)
            fallback_reason = "slot_not_found"
            if selection_mode == "selection_not_structured":
                fallback_reason = selection_mode
            return SlotSelectionExecutionOutcome(
                attempted=True,
                ok=True,
                reply=reply,
                fallback_reason=fallback_reason,
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                appointment_context=sanitize_value(appointment_context),
                offered_slots=normalized_slots,
                selected_slot_ambiguous=False,
                selected_slot_match_count=match_count,
                selection_mode=selection_mode,
                response_payload=sanitize_value(
                    {
                        "reply": reply,
                        "reason": "slot_selection_missing",
                    }
                ),
                trace_id=shadow_trace.trace_id,
            )

        enriched_slot = self.prompt_builder._enrich_selected_slot(
            selected_slot,
            appointment_context,
            payload,
            routing,
            backend_context,
            timezone,
            timezone_source,
        )
        reply = self._preconfirmation_reply(payload, contact_context, enriched_slot, appointment_context)
        response_payload = {
            "reply": reply,
            "reason": "slot_selection_resolved",
            "selected_slot": enriched_slot,
            "offered_slots": normalized_slots,
            "selection_mode": selection_mode,
            "selected_slot_match_count": match_count,
        }

        return SlotSelectionExecutionOutcome(
            attempted=True,
            ok=True,
            reply=reply,
            planning=planning_result.model_dump(exclude_none=True),
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            appointment_context=sanitize_value(appointment_context),
            offered_slots=normalized_slots,
            selected_slot=sanitize_value(enriched_slot),
            selected_slot_match_count=match_count,
            selected_slot_ambiguous=False,
            selection_mode=selection_mode,
            response_payload=sanitize_value(response_payload),
            trace_id=shadow_trace.trace_id,
        )

    def _declined(
        self,
        reason: str,
        shadow_trace: OrchestrationTrace,
        planning_result: LLMPlanningResult | None,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
    ) -> SlotSelectionExecutionOutcome:
        return SlotSelectionExecutionOutcome(
            attempted=False,
            ok=False,
            fallback_reason=reason,
            planning=planning_result.model_dump(exclude_none=True) if planning_result is not None else {},
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            trace_id=shadow_trace.trace_id,
        )

    def _controlled_missing_slots_reply(
        self,
        shadow_trace: OrchestrationTrace,
        planning_result: LLMPlanningResult,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
        appointment_context: dict[str, Any] | None = None,
    ) -> SlotSelectionExecutionOutcome:
        reply = "No veo horarios ofrecidos previos. ¿Quieres buscar disponibilidad de nuevo?"
        return SlotSelectionExecutionOutcome(
            attempted=True,
            ok=True,
            reply=reply,
            fallback_reason="offered_slots_missing",
            planning=planning_result.model_dump(exclude_none=True),
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            appointment_context=sanitize_value(appointment_context or {}),
            response_payload=sanitize_value(
                {
                    "reply": reply,
                    "reason": "offered_slots_missing",
                }
            ),
            trace_id=shadow_trace.trace_id,
        )

    def _eligibility_reason(
        self,
        planning_result: LLMPlanningResult,
        context_plan: ContextExpansionPlan,
        tool_policy: ToolPolicyDecision,
    ) -> str | None:
        if planning_result.domain != "appointment":
            return "planning_domain_not_appointment"

        if planning_result.intent != "select_offered_slot":
            return "planning_intent_not_select_offered_slot"

        if planning_result.action_candidate != "prepare_booking_confirmation":
            return "planning_action_candidate_not_prepare_booking_confirmation"

        if planning_result.clarification.needed:
            return "clarification_requested"

        if planning_result.risk_flags.low_confidence:
            return "low_confidence"

        if planning_result.tool_request.write_tools != []:
            return "write_tools_requested"

        if tool_policy.write_tools_enabled != []:
            return "write_tools_enabled"

        if not context_plan.include_appointment_context or not context_plan.include_offered_slots:
            return "offered_slots_not_requested"

        return None

    def _resolve_selected_slot(
        self,
        planning_result: LLMPlanningResult,
        offered_slots: list[dict[str, Any]],
    ) -> tuple[dict[str, Any] | None, int, list[str], str | None]:
        selected_index = planning_result.entities.selected_slot_index
        if selected_index is not None:
            if 0 <= selected_index < len(offered_slots):
                return offered_slots[selected_index], 1, [], "selected_slot_index"
            return None, 0, [], "selected_slot_index_out_of_range"

        slot_reference = self._clean_string(planning_result.entities.slot_reference)
        if slot_reference == "first":
            if offered_slots == []:
                return None, 0, [], "slot_reference_first_missing"
            return offered_slots[0], 1, [], "slot_reference_first"
        if slot_reference == "last":
            if offered_slots == []:
                return None, 0, [], "slot_reference_last_missing"
            return offered_slots[-1], 1, [], "slot_reference_last"

        time_value = self._clean_string(planning_result.entities.time)
        if time_value is not None:
            candidates = [slot for slot in offered_slots if self._slot_time(slot) == time_value]
            if len(candidates) == 1:
                return candidates[0], 1, [], "exact_time"
            if len(candidates) > 1:
                return None, len(candidates), self._ambiguity_options(candidates), "exact_time_ambiguous"
            return None, 0, [], "exact_time_not_found"

        return None, 0, [], "selection_not_structured"

    def _preconfirmation_reply(
        self,
        payload: AgentRequest,
        contact_context: dict[str, Any] | None,
        selected_slot: dict[str, Any],
        appointment_context: dict[str, Any],
    ) -> str:
        slot_label = self._slot_display_label(selected_slot)
        service_name = (
            self._clean_string(selected_slot.get("service_name"))
            or self._clean_string(appointment_context.get("service_name"))
            or self._clean_string(appointment_context.get("serviceName"))
            or "este servicio"
        )
        contact_name = self._contact_name(payload, contact_context)

        if contact_name is None:
            return f"Perfecto, puedo reservar el horario de {slot_label} para {service_name}. Para confirmarlo necesito tu nombre."

        owner_name = self._clean_string(selected_slot.get("owner_name"))
        if owner_name is not None:
            return f"Perfecto, tengo seleccionado el horario de {slot_label} con {owner_name} para {service_name}. ¿Confirmo la reserva?"

        return f"Perfecto, tengo seleccionado el horario de {slot_label} para {service_name}. ¿Confirmo la reserva?"

    def _missing_slot_reply(self, planning_result: LLMPlanningResult, offered_slots: list[dict[str, Any]]) -> str:
        service_name = self._clean_string(planning_result.entities.service_name) or "este servicio"
        if offered_slots == []:
            return "No veo horarios ofrecidos previos. ¿Quieres buscar disponibilidad de nuevo?"

        return f"No encuentro un horario válido para {service_name} entre los slots ofrecidos. ¿Quieres que busque disponibilidad de nuevo?"

    def _ambiguous_slot_reply(self, ambiguity_options: list[str]) -> str:
        if ambiguity_options == []:
            return "Veo varios horarios posibles. ¿Cuál prefieres?"

        formatted_options = " o ".join(ambiguity_options[:2]) if len(ambiguity_options) <= 2 else ", ".join(ambiguity_options[:-1]) + f" o {ambiguity_options[-1]}"
        return f"Veo varios horarios posibles: {formatted_options}. ¿Cuál prefieres?"

    def _slot_time(self, slot: dict[str, Any]) -> str | None:
        start = self._clean_string(slot.get("start"))
        if start is None:
            return None

        try:
            parsed = datetime.fromisoformat(start)
        except Exception:
            return None

        return f"{parsed.hour:02d}:{parsed.minute:02d}"

    def _slot_display_label(self, slot: dict[str, Any]) -> str:
        slot_label = self._clean_string(slot.get("slot_label"))
        if slot_label is not None:
            return slot_label

        display_time = self._clean_string(slot.get("display_time"))
        if display_time is not None:
            return display_time

        time_value = self._slot_time(slot)
        if time_value is not None:
            return time_value

        start = self._clean_string(slot.get("start"))
        return start or "horario seleccionado"

    def _ambiguity_options(self, candidates: list[dict[str, Any]]) -> list[str]:
        options: list[str] = []
        for slot in candidates:
            owner_name = self._clean_string(slot.get("owner_name"))
            label = self._slot_display_label(slot)
            if owner_name is not None:
                option = f"{label} con {owner_name}"
            else:
                option = label
            if option not in options:
                options.append(option)
        return options

    def _contact_name(self, payload: AgentRequest, contact_context: dict[str, Any] | None) -> str | None:
        contact_name = self._clean_string(payload.contact.name)
        if contact_name is not None:
            return contact_name

        if not isinstance(contact_context, dict):
            return None

        contact = contact_context.get("contact")
        if isinstance(contact, dict):
            for key in ("name", "full_name", "fullName"):
                candidate = self._clean_string(contact.get(key))
                if candidate is not None:
                    return candidate

        data = contact_context.get("data")
        if isinstance(data, dict):
            contact_data = data.get("contact")
            if isinstance(contact_data, dict):
                for key in ("name", "full_name", "fullName"):
                    candidate = self._clean_string(contact_data.get(key))
                    if candidate is not None:
                        return candidate

        for key in ("external_contact_name", "contact_name", "name"):
            candidate = self._clean_string(contact_context.get(key))
            if candidate is not None:
                return candidate

        return None

    def _resolve_timezone_details(self, backend_context: CommercialContext, contact_context: dict[str, Any] | None) -> tuple[str | None, str | None]:
        timezone = self._clean_string(getattr(backend_context.tenant, "timezone", None))
        timezone_source = self._clean_string(getattr(backend_context.tenant, "timezone_source", None))
        if timezone is not None:
            return timezone, timezone_source

        if isinstance(contact_context, dict):
            business_context = contact_context.get("business_context")
            if isinstance(business_context, dict):
                timezone = self._clean_string(business_context.get("timezone"))
                timezone_source = self._clean_string(business_context.get("timezone_source"))
                if timezone is not None:
                    return timezone, timezone_source

        return None, None

    def _clean_string(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        cleaned = value.strip()
        return cleaned or None

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
