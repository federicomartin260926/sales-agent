from __future__ import annotations

import json
import re
import time
import logging
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


logger = logging.getLogger(__name__)


class AppointmentAvailabilityExecutionOutcome(BaseModel):
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
    verified_service_context: dict[str, Any] = Field(default_factory=dict)
    offered_slots: list[dict[str, Any]] = Field(default_factory=list)
    bounded_single_tool_call: bool = False
    mcp_allowed_tools: list[str] = Field(default_factory=list)
    mcp_tool_traces: list[dict[str, Any]] = Field(default_factory=list)
    response_payload: dict[str, Any] = Field(default_factory=dict)
    trace_id: str | None = None

    def to_safe_dict(self) -> dict[str, Any]:
        return sanitize_value(self.model_dump(exclude_none=True))


class AppointmentAvailabilityExecutionService:
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
    ) -> AppointmentAvailabilityExecutionOutcome:
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

        if mcp_config is None:
            return self._declined("mcp_config_missing", shadow_trace, planning_result, context_plan, tool_policy, mcp_config)

        configuration = await self.llm_client.resolve_configuration()
        provider = str(configuration.get("llm_default_profile", self.settings.llm_provider)).strip().lower()
        if provider != "openai":
            return self._declined(
                "appointment_availability_provider_unsupported",
                shadow_trace,
                planning_result,
                context_plan,
                tool_policy,
                mcp_config,
            )

        bounded_single_tool_call = False
        sequence_allowed_tools = ["services_search", "appointment_availability"]
        sequence_mcp_config = mcp_config.model_copy(update={"allowed_tools": sequence_allowed_tools})
        search_system_prompt, search_user_prompt = self._build_prompts(
            payload=payload,
            routing=routing,
            backend_context=backend_context,
            contact_context=contact_context,
            planning_result=planning_result,
            context_plan=context_plan,
            tool_policy=tool_policy,
            phase="availability_sequence",
            verified_service_context=None,
        )

        sequence_started_at = time.perf_counter()
        try:
            sequence_result = await self.llm_client.generate_with_mcp(
                provider,
                search_system_prompt,
                search_user_prompt,
                sequence_mcp_config,
                configuration=configuration,
                previous_response_id=None,
                parallel_tool_calls=False,
                single_tool_call=False,
            )
        except Exception as exc:
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                fallback_reason="availability_sequence_llm_failed",
                error_type=exc.__class__.__name__,
                error_message=self._sanitize_error_message(str(exc)),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                trace_id=shadow_trace.trace_id,
            )

        sequence_latency_ms = int(round((time.perf_counter() - sequence_started_at) * 1000))
        sequence_tool_traces = self._safe_tool_traces(sequence_result.tool_traces)
        repeated_tool_call_summary = self._repeated_tool_call_summary(sequence_tool_traces)
        if repeated_tool_call_summary is not None:
            logger.warning(
                "Appointment availability bounded sequence repeated tool call detected summary=%s",
                json.dumps(repeated_tool_call_summary, ensure_ascii=False, default=str),
            )
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                provider=sequence_result.provider,
                model=sequence_result.model,
                response_id=sequence_result.response_id,
                latency_ms=sequence_latency_ms,
                fallback_reason="repeated_tool_call_detected",
                error_type="repeated_tool_call",
                error_message="Appointment availability bounded sequence repeated the same tool call with the same arguments.",
                raw_content_preview=self._preview_raw_content(sequence_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                mcp_tool_traces=sequence_tool_traces,
                verified_service_context={},
                trace_id=shadow_trace.trace_id,
            )

        verified_service_context = self._verified_services_search_context(sequence_result.tool_traces)
        if verified_service_context is None:
            verified_service_context = {
                "tool_name": "services_search",
                "service_name": planning_result.entities.service_name,
                "query": planning_result.entities.query or payload.message.text,
                "source": "planning_result",
                "verified": False,
            }
        if not self._has_tool_trace(sequence_result.tool_traces, "services_search"):
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                provider=sequence_result.provider,
                model=sequence_result.model,
                response_id=sequence_result.response_id,
                latency_ms=sequence_latency_ms,
                fallback_reason="services_search_trace_missing",
                error_type="missing_services_search_trace",
                error_message="Appointment availability execution completed without a services_search trace.",
                raw_content_preview=self._preview_raw_content(sequence_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                mcp_tool_traces=sequence_tool_traces,
                response_payload=sanitize_value({}),
                verified_service_context={},
                trace_id=shadow_trace.trace_id,
            )

        if not self._has_tool_trace(sequence_result.tool_traces, "appointment_availability"):
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                provider=sequence_result.provider,
                model=sequence_result.model,
                response_id=sequence_result.response_id,
                latency_ms=sequence_latency_ms,
                fallback_reason="appointment_availability_trace_missing",
                error_type="missing_appointment_availability_trace",
                error_message="Appointment availability execution completed without an appointment_availability trace.",
                raw_content_preview=self._preview_raw_content(sequence_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                mcp_tool_traces=sequence_tool_traces,
                response_payload=sanitize_value({}),
                verified_service_context=sanitize_value(verified_service_context),
                trace_id=shadow_trace.trace_id,
            )

        parsed_payload, parse_error = self._extract_json_payload(sequence_result.content)
        if parsed_payload is None:
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                provider=sequence_result.provider,
                model=sequence_result.model,
                response_id=sequence_result.response_id,
                latency_ms=sequence_latency_ms,
                fallback_reason="appointment_availability_response_parse_failed",
                error_type=parse_error or "json_decode_error",
                error_message="Appointment availability response did not contain a usable JSON object.",
                raw_content_preview=self._preview_raw_content(sequence_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                mcp_tool_traces=sequence_tool_traces,
                verified_service_context=sanitize_value(verified_service_context),
                trace_id=shadow_trace.trace_id,
            )

        reply = parsed_payload.get("reply")
        if not isinstance(reply, str) or reply.strip() == "":
            return AppointmentAvailabilityExecutionOutcome(
                attempted=True,
                ok=False,
                provider=sequence_result.provider,
                model=sequence_result.model,
                response_id=sequence_result.response_id,
                latency_ms=sequence_latency_ms,
                fallback_reason="appointment_availability_reply_missing",
                error_type="missing_reply",
                error_message="Appointment availability response did not include a usable reply.",
                raw_content_preview=self._preview_raw_content(sequence_result.content),
                planning=planning_result.model_dump(exclude_none=True),
                context_plan=context_plan.model_dump(exclude_none=True),
                tool_policy=tool_policy.model_dump(exclude_none=True),
                bounded_single_tool_call=bounded_single_tool_call,
                mcp_allowed_tools=sequence_allowed_tools,
                mcp_tool_traces=sequence_tool_traces,
                response_payload=sanitize_value(parsed_payload),
                verified_service_context=sanitize_value(verified_service_context),
                trace_id=shadow_trace.trace_id,
            )

        raw_output_items: list[Any] = []
        if isinstance(sequence_result.raw_payload, dict):
            output_items = sequence_result.raw_payload.get("output")
            if isinstance(output_items, list):
                raw_output_items = output_items

        trace_offered_slots = self.extract_offered_slots_from_appointment_availability_tool_trace(
            raw_output_items,
            verified_service_context,
            backend_context.tenant.timezone,
        )
        if trace_offered_slots == []:
            trace_offered_slots = self.extract_offered_slots_from_appointment_availability_tool_trace(
                sequence_result.tool_traces,
                verified_service_context,
                backend_context.tenant.timezone,
            )
        if trace_offered_slots != []:
            offered_slots = trace_offered_slots
        else:
            offered_slots = self._offered_slots_from_payload(
                parsed_payload,
                verified_service_context,
                backend_context.tenant.timezone,
            )
        return AppointmentAvailabilityExecutionOutcome(
            attempted=True,
            ok=True,
            reply=reply.strip(),
            provider=sequence_result.provider,
            model=sequence_result.model,
            response_id=sequence_result.response_id,
            latency_ms=sequence_latency_ms,
            planning=planning_result.model_dump(exclude_none=True),
            context_plan=context_plan.model_dump(exclude_none=True),
            tool_policy=tool_policy.model_dump(exclude_none=True),
            bounded_single_tool_call=bounded_single_tool_call,
            verified_service_context=sanitize_value(verified_service_context),
            offered_slots=offered_slots,
            mcp_allowed_tools=sequence_allowed_tools,
            mcp_tool_traces=sequence_tool_traces,
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
    ) -> AppointmentAvailabilityExecutionOutcome:
        return AppointmentAvailabilityExecutionOutcome(
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
        if planning_result.domain != "appointment":
            return "planning_domain_not_appointment"

        if planning_result.intent != "request_availability":
            return "planning_intent_not_request_availability"

        if planning_result.action_candidate != "get_availability":
            return "planning_action_candidate_not_get_availability"

        if planning_result.clarification.needed:
            return "clarification_requested"

        if planning_result.risk_flags.low_confidence:
            return "low_confidence"

        if planning_result.tool_request.write_tools != []:
            return "write_tools_requested"

        if tool_policy.write_tools_enabled != []:
            return "write_tools_enabled"

        requested_lookup = set(planning_result.tool_request.lookup_tools)
        if not {"services_search", "appointment_availability"}.issubset(requested_lookup):
            return "lookup_tools_not_requested"

        if not context_plan.include_appointment_context:
            return "appointment_context_not_requested"

        if not {"services_search", "appointment_availability"}.issubset(set(tool_policy.lookup_tools_enabled)):
            return "lookup_tools_blocked_by_policy"

        if mcp_config is None or not mcp_config.enabled:
            return "mcp_disabled"

        if not isinstance(mcp_config.server_label, str) or mcp_config.server_label.strip() == "":
            return "mcp_server_label_missing"

        if not isinstance(mcp_config.server_url, str) or mcp_config.server_url.strip() == "":
            return "mcp_server_url_missing"

        allowed_tools = [tool.strip() for tool in mcp_config.allowed_tools if isinstance(tool, str) and tool.strip() != ""]
        if not {"services_search", "appointment_availability"}.issubset(set(allowed_tools)):
            return "mcp_required_tools_not_available"

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
        phase: str,
        verified_service_context: dict[str, Any] | None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres una capa experimental de disponibilidad de citas. "
            "Sigue exactamente esta secuencia: si todavía no tienes un servicio verificado, llama services_search una sola vez; "
            "con el service_id encontrado, llama appointment_availability una sola vez; después devuelve el JSON final. "
            "No repitas una herramienta con los mismos argumentos. "
            "No hagas llamadas de verificación adicionales. "
            "Si la herramienta devuelve ok=true, found=true, available=true, items o slots, usa ese resultado y termina. "
            "Si el resultado es vacío o inválido, devuelve el JSON controlado de fallback en lugar de reintentar. "
            "No uses appointment_confirm, appointment_reschedule, appointment_cancel ni crm_contact_submit. "
            "Devuelve únicamente un objeto JSON válido con estas claves: reply, reason y slots. "
            "No uses markdown, no añadas texto explicativo y no incluyas campos fuera de ese contrato. "
            "reply debe ser breve, natural y en español. "
            "La respuesta final debe basarse en la secuencia verificada services_search -> appointment_availability. "
            "Si hay slots disponibles, resume solo esos slots reales; no inventes horarios. "
            "Si no hay slots, explica la falta de disponibilidad de forma breve y ofrece buscar otro día o franja. "
            "Si el usuario indicó una franja como afternoon, trata la búsqueda como una aproximación de 14:00-20:00 en el timezone del negocio cuando necesites acotar la ventana."
        )
        user_payload: dict[str, Any] = {
            "phase": phase,
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
                "timezone": backend_context.tenant.timezone,
                "timezone_source": backend_context.tenant.timezone_source,
            },
            "planning": planning_result.model_dump(exclude_none=True),
            "context_plan": context_plan.model_dump(exclude_none=True),
            "tool_policy": tool_policy.model_dump(exclude_none=True),
            "required_tool_sequence": ["services_search", "appointment_availability"],
            "service_hint": {
                "service_name": planning_result.entities.service_name,
                "query": planning_result.entities.query or payload.message.text,
                "date": planning_result.entities.date,
                "date_from": planning_result.entities.date_from,
                "date_to": planning_result.entities.date_to,
                "time_of_day": planning_result.entities.time_of_day,
            },
        }
        availability_hint = self._availability_hint(backend_context.tenant.timezone, planning_result.entities.time_of_day)
        if availability_hint is not None:
            user_payload["availability_hint"] = availability_hint
        if verified_service_context is not None:
            user_payload["verified_service_context"] = verified_service_context
        if contact_context is not None:
            user_payload["contact_context"] = contact_context

        return system_prompt, json.dumps(user_payload, ensure_ascii=False)

    def _availability_hint(self, timezone_name: str | None, time_of_day: str | None) -> dict[str, Any] | None:
        if time_of_day != "afternoon":
            return None

        hint: dict[str, Any] = {
            "time_of_day": "afternoon",
            "approximate_local_window": "14:00-20:00",
        }
        if isinstance(timezone_name, str) and timezone_name.strip() != "":
            hint["timezone"] = timezone_name.strip()
        return hint

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

    def _offered_slots_from_payload(
        self,
        payload: dict[str, Any],
        verified_service_context: dict[str, Any],
        timezone_name: str | None,
    ) -> list[dict[str, Any]]:
        if not isinstance(payload, dict):
            return []

        candidate_slots = payload.get("slots")
        if not isinstance(candidate_slots, list):
            candidate_slots = payload.get("available_slots")
        if not isinstance(candidate_slots, list):
            candidate_slots = []

        return self._normalize_offered_slots(candidate_slots, verified_service_context, timezone_name)

    def extract_offered_slots_from_appointment_availability_tool_trace(
        self,
        mcp_tool_traces: list[Any],
        service_context: dict[str, Any],
        timezone_name: str | None,
    ) -> list[dict[str, Any]]:
        """La fuente de verdad de los slots ofrecidos es la salida cruda de la tool MCP."""
        return self._offered_slots_from_tool_traces(mcp_tool_traces, service_context, timezone_name)

    def _offered_slots_from_trace(
        self,
        tool_traces: list[dict[str, Any]],
        verified_service_context: dict[str, Any],
        timezone_name: str | None,
    ) -> list[dict[str, Any]]:
        output = self._trace_tool_output(tool_traces, "appointment_availability")
        slots = []
        if isinstance(output, dict):
            candidate_slots = output.get("slots")
            if not isinstance(candidate_slots, list):
                candidate_slots = output.get("available_slots")
            if isinstance(candidate_slots, list):
                slots = candidate_slots

        return self._normalize_offered_slots(slots, verified_service_context, timezone_name)

    def _offered_slots_from_tool_traces(
        self,
        tool_traces: list[Any],
        verified_service_context: dict[str, Any],
        timezone_name: str | None,
    ) -> list[dict[str, Any]]:
        output = self._trace_tool_output(tool_traces, "appointment_availability")
        slots: list[Any] = []
        if isinstance(output, dict):
            candidate_slots = output.get("slots")
            if not isinstance(candidate_slots, list):
                candidate_slots = output.get("available_slots")
            if isinstance(candidate_slots, list):
                slots = candidate_slots

        return self._normalize_offered_slots(slots, verified_service_context, timezone_name)

    def _normalize_offered_slots(
        self,
        slots: list[Any],
        verified_service_context: dict[str, Any],
        timezone_name: str | None,
    ) -> list[dict[str, Any]]:
        normalized_slots: list[dict[str, Any]] = []
        service_id = self._clean_string(
            verified_service_context.get("service_id")
            or verified_service_context.get("serviceId")
            or verified_service_context.get("id")
        )
        service_name = self._clean_string(
            verified_service_context.get("service_name")
            or verified_service_context.get("serviceName")
            or verified_service_context.get("name")
        )
        service_ref = self._clean_string(
            verified_service_context.get("service_ref")
            or verified_service_context.get("serviceRef")
            or verified_service_context.get("ref")
            or verified_service_context.get("slug")
            or verified_service_context.get("integration_key")
        )
        duration_minutes = verified_service_context.get("duration_minutes")
        if duration_minutes is None:
            duration_minutes = verified_service_context.get("durationMinutes")
        if duration_minutes is None:
            duration_minutes = verified_service_context.get("duration")

        for slot in slots:
            if not isinstance(slot, dict):
                continue

            owner = slot.get("owner") if isinstance(slot.get("owner"), dict) else {}
            owner_id = self._clean_string(
                slot.get("owner_id")
                or slot.get("ownerId")
                or owner.get("id")
                or owner.get("owner_id")
                or owner.get("ownerId")
            )
            owner_name = self._clean_string(
                slot.get("owner_name")
                or slot.get("ownerName")
                or owner.get("name")
                or owner.get("display_name")
            )
            owner_email = self._clean_string(
                slot.get("owner_email")
                or slot.get("ownerEmail")
                or owner.get("email")
                or owner.get("owner_email")
                or owner.get("ownerEmail")
            )
            owner_ref = self._clean_string(
                slot.get("owner_ref")
                or slot.get("ownerRef")
                or owner.get("ref")
                or owner.get("owner_ref")
                or owner.get("ownerRef")
            )
            owner_preferred = owner.get("preferred")
            if owner_preferred is None:
                owner_preferred = slot.get("owner_preferred")
            if owner_preferred is None:
                owner_preferred = slot.get("ownerPreferred")
            start = self._clean_string(slot.get("start"))
            end = self._clean_string(slot.get("end"))
            slot_timezone = self._clean_string(slot.get("timezone"))
            if slot_timezone is None:
                slot_timezone = self._clean_string(timezone_name)

            normalized_slot: dict[str, Any] = {}
            if start is not None:
                normalized_slot["start"] = start
                normalized_slot["display_time"] = self._slot_time_from_iso(start) or start
            if end is not None:
                normalized_slot["end"] = end
            if slot_timezone is not None:
                normalized_slot["timezone"] = slot_timezone
            if service_id is not None:
                normalized_slot["service_id"] = service_id
            if service_name is not None:
                normalized_slot["service_name"] = service_name
            if service_ref is not None:
                normalized_slot["service_ref"] = service_ref
            if owner_id is not None:
                normalized_slot["owner_id"] = owner_id
            if owner_name is not None:
                normalized_slot["owner_name"] = owner_name
            if owner_email is not None:
                normalized_slot["owner_email"] = owner_email
            if owner_ref is not None:
                normalized_slot["owner_ref"] = owner_ref
            if owner_preferred is not None:
                normalized_slot["owner_preferred"] = owner_preferred

            owner_payload: dict[str, Any] = {}
            if owner_id is not None:
                owner_payload["id"] = owner_id
            if owner_name is not None:
                owner_payload["name"] = owner_name
            if owner_email is not None:
                owner_payload["email"] = owner_email
            if owner_ref is not None:
                owner_payload["ref"] = owner_ref
            if owner_preferred is not None:
                owner_payload["preferred"] = owner_preferred
            if owner_payload:
                normalized_slot["owner"] = owner_payload

            service_payload: dict[str, Any] = {}
            if service_id is not None:
                service_payload["id"] = service_id
            if service_name is not None:
                service_payload["name"] = service_name
            if service_ref is not None:
                service_payload["ref"] = service_ref
            if duration_minutes is not None:
                service_payload["duration_minutes"] = int(duration_minutes)
            if service_payload:
                normalized_slot["service"] = service_payload

            label = self._clean_string(slot.get("label"))
            if label is not None:
                normalized_slot["slot_label"] = label

            if normalized_slot:
                normalized_slots.append(normalized_slot)

        return normalized_slots

    def _merge_offered_slots(
        self,
        primary_slots: list[dict[str, Any]],
        secondary_slots: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        merged_slots: list[dict[str, Any]] = []
        max_len = max(len(primary_slots), len(secondary_slots))
        for index in range(max_len):
            primary_slot = primary_slots[index] if index < len(primary_slots) else {}
            secondary_slot = secondary_slots[index] if index < len(secondary_slots) else {}
            if not isinstance(primary_slot, dict):
                primary_slot = {}
            if not isinstance(secondary_slot, dict):
                secondary_slot = {}

            merged_slot = dict(primary_slot)
            for key, value in secondary_slot.items():
                if key not in merged_slot or merged_slot[key] in (None, ""):
                    merged_slot[key] = value

            if merged_slot != {}:
                merged_slots.append(merged_slot)

        return merged_slots

    def _trace_step_nested_output(self, trace: OrchestrationTrace, step_type: str, key: str) -> dict[str, Any] | None:
        step_output = self._trace_step_output(trace, step_type)
        if not isinstance(step_output, dict):
            return None

        nested = step_output.get(key)
        if isinstance(nested, dict):
            return nested

        return None

    def _trace_tool_output(self, tool_traces: list[dict[str, Any]], tool_name: str) -> dict[str, Any] | None:
        for trace in reversed(tool_traces):
            if not isinstance(trace, dict):
                continue

            current_tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(current_tool_name, str) or current_tool_name.strip() != tool_name:
                continue

            output = trace.get("output")
            if isinstance(output, dict):
                return output
            if isinstance(output, str):
                parsed, _ = self._extract_json_payload(output)
                if isinstance(parsed, dict):
                    return parsed

            raw = trace.get("raw")
            if isinstance(raw, dict):
                raw_output = raw.get("output")
                if isinstance(raw_output, dict):
                    return raw_output
                if isinstance(raw_output, str):
                    parsed, _ = self._extract_json_payload(raw_output)
                    if isinstance(parsed, dict):
                        return parsed

        return None

    def _repeated_tool_call_summary(self, tool_traces: list[dict[str, Any]]) -> dict[str, Any] | None:
        seen: dict[tuple[str, str], int] = {}
        for index, trace in enumerate(tool_traces):
            if not isinstance(trace, dict):
                continue

            tool_name = self._tool_trace_name(trace)
            if tool_name is None:
                continue

            arguments = trace.get("arguments")
            if arguments is None and isinstance(trace.get("raw"), dict):
                arguments = trace["raw"].get("arguments")

            sanitized_arguments = sanitize_value(arguments) if isinstance(arguments, (dict, list)) else arguments
            try:
                arguments_fingerprint = json.dumps(sanitized_arguments, sort_keys=True, ensure_ascii=False, default=str)
            except Exception:
                arguments_fingerprint = repr(sanitized_arguments)

            key = (tool_name, arguments_fingerprint)
            if key in seen:
                return {
                    "tool_name": tool_name,
                    "arguments": sanitized_arguments,
                    "first_index": seen[key],
                    "repeat_index": index,
                }

            seen[key] = index

        return None

    def _slot_time_from_iso(self, value: str) -> str | None:
        try:
            parsed = datetime.fromisoformat(value)
        except Exception:
            return None

        return f"{parsed.hour:02d}:{parsed.minute:02d}"

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

    def _clean_string(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        cleaned = value.strip()
        return cleaned or None

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

    def _merge_safe_tool_traces(
        self,
        first: list[dict[str, Any]],
        second: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        merged: list[dict[str, Any]] = []
        seen: set[str] = set()
        for trace in [*first, *second]:
            try:
                fingerprint = json.dumps(trace, sort_keys=True, ensure_ascii=False, default=str)
            except Exception:
                fingerprint = repr(trace)
            if fingerprint in seen:
                continue
            seen.add(fingerprint)
            merged.append(trace)
        return merged

    def _verified_services_search_context(self, traces: list[Any]) -> dict[str, Any] | None:
        for trace in traces:
            tool_name = self._tool_trace_name(trace)
            if tool_name != "services_search":
                continue

            if hasattr(trace, "model_dump"):
                raw_trace = sanitize_value(trace.model_dump(exclude_none=True))
            elif isinstance(trace, dict):
                raw_trace = sanitize_value(trace)
            else:
                continue

            if not isinstance(raw_trace, dict):
                continue

            output = raw_trace.get("output")
            if isinstance(output, dict):
                return {
                    "tool_name": "services_search",
                    "output": output,
                }

            if isinstance(raw_trace.get("raw"), dict):
                raw_output = raw_trace["raw"].get("output")
                if isinstance(raw_output, dict):
                    return {
                        "tool_name": "services_search",
                        "output": sanitize_value(raw_output),
                    }

            return {"tool_name": "services_search", "trace": raw_trace}

        return None

    def _has_tool_trace(self, traces: list[Any], tool_name: str) -> bool:
        for trace in traces:
            candidate_name = self._tool_trace_name(trace)
            if candidate_name == tool_name:
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
