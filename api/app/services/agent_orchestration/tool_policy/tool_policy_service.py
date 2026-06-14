from __future__ import annotations

from pydantic import BaseModel, ConfigDict, Field

from app.services.agent_orchestration.planning.schemas import LLMPlanningResult, LOOKUP_TOOL_NAMES, WRITE_TOOL_NAMES


class ToolPolicyDecision(BaseModel):
    model_config = ConfigDict(extra="forbid")

    lookup_tools_enabled: list[str] = Field(default_factory=list)
    write_tools_requested: list[str] = Field(default_factory=list)
    write_tools_enabled: list[str] = Field(default_factory=list)
    write_tools_blocked: list[str] = Field(default_factory=list)
    reason: str = ""


class ToolPolicyService:
    def evaluate(self, planning: LLMPlanningResult) -> ToolPolicyDecision:
        requested_lookup = [tool for tool in planning.tool_request.lookup_tools if tool in LOOKUP_TOOL_NAMES]
        requested_write = [tool for tool in planning.tool_request.write_tools if tool in WRITE_TOOL_NAMES]

        if planning.risk_flags.ambiguous_reference or planning.clarification.needed:
            return ToolPolicyDecision(
                lookup_tools_enabled=requested_lookup,
                write_tools_requested=requested_write,
                write_tools_enabled=[],
                write_tools_blocked=requested_write,
                reason="blocked_by_ambiguous_reference_or_clarification",
            )

        enabled_write: list[str] = []
        blocked_write = list(requested_write)

        if planning.action_candidate == "prepare_booking_confirmation" and planning.intent in {
            "select_offered_slot",
            "request_booking_confirmation",
        }:
            if "appointment_confirm" in requested_write:
                enabled_write.append("appointment_confirm")
                blocked_write = [tool for tool in blocked_write if tool != "appointment_confirm"]

        if planning.action_candidate == "prepare_reschedule" and planning.intent == "request_reschedule":
            if "appointment_reschedule" in requested_write:
                enabled_write.append("appointment_reschedule")
                blocked_write = [tool for tool in blocked_write if tool != "appointment_reschedule"]

        if planning.action_candidate == "prepare_cancel" and planning.intent == "request_cancel":
            if "appointment_cancel" in requested_write:
                enabled_write.append("appointment_cancel")
                blocked_write = [tool for tool in blocked_write if tool != "appointment_cancel"]

        if planning.action_candidate == "create_or_update_crm_contact" and planning.intent in {
            "provide_contact_data",
            "request_quote",
            "complaint_or_problem",
        }:
            if "crm_contact_submit" in requested_write:
                enabled_write.append("crm_contact_submit")
                blocked_write = [tool for tool in blocked_write if tool != "crm_contact_submit"]

        reason = "lookup_enabled_write_tools_blocked_by_default"
        if enabled_write:
            reason = "write_tools_enabled_declaratively"

        return ToolPolicyDecision(
            lookup_tools_enabled=requested_lookup,
            write_tools_requested=requested_write,
            write_tools_enabled=enabled_write,
            write_tools_blocked=blocked_write,
            reason=reason,
        )
