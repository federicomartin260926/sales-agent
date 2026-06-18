from __future__ import annotations

from app.schemas.llm import McpRemoteConfig
from app.services.agent_orchestration.schemas import IntentPlan, RuntimeContext, ToolPlan


READ_TOOLS = [
    "contact_context",
    "services_search",
    "appointment_availability",
    "appointment_events",
]

WRITE_TOOLS_BY_INTENT = {
    "request_booking_confirmation": ["appointment_confirm"],
    "request_reschedule": ["appointment_reschedule"],
    "request_cancel": ["appointment_cancel"],
    "request_handoff": ["handoff_request"],
}

CATALOG_READ_TOOLS = ["services_search"]
HANDOFF_TOOLS = ["handoff_request"]
CRM_TOOLS = ["crm_contact_submit", "contact_context"]


class ToolSelector:
    """Selects allowed MCP tools for the next LLM call.

    SA does not decide what to do with user text. It only gives the LLM the tools
    that are safe and relevant for the intent already returned by the LLM planner.
    """

    def select(self, plan: IntentPlan, context: RuntimeContext, mcp_config: McpRemoteConfig | None) -> ToolPlan:
        configured = list(mcp_config.allowed_tools) if mcp_config is not None and mcp_config.enabled else []
        if not configured:
            return ToolPlan(reason="mcp_disabled_or_no_tools")

        if plan.intent == "provide_contact_data":
            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};contact_data_only=true",
            )

        if plan.intent == "select_offered_slot":
            offered_slots = context.appointment.get("offered_slots") if isinstance(context.appointment, dict) else []
            if isinstance(offered_slots, list) and offered_slots:
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};offered_slots_present=true",
                )

        if plan.intent == "request_booking_confirmation":
            selected_slot = context.appointment.get("selected_slot") if isinstance(context.appointment, dict) else None
            if not isinstance(selected_slot, dict) or not selected_slot:
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};missing_selected_slot=true",
                )

            appointment_confirm_allowed = "appointment_confirm" in configured
            allowed_tools = ["appointment_confirm"] if appointment_confirm_allowed else []
            return ToolPlan(
                allowed_tools=allowed_tools,
                read_tools=[],
                write_tools=["appointment_confirm"] if appointment_confirm_allowed else [],
                reason=f"domain={plan.domain};intent={plan.intent};selected_slot_present=true",
            )

        desired_read: list[str] = []
        desired_write: list[str] = []

        if plan.domain == "appointment" or plan.intent in {
            "request_availability",
            "select_offered_slot",
            "provide_contact_data",
        }:
            desired_read.extend(READ_TOOLS)
            desired_write.extend(WRITE_TOOLS_BY_INTENT.get(plan.intent, []))

        if plan.domain == "catalog" or plan.intent in {"ask_product_or_service_info", "catalog_search"}:
            desired_read.extend(CATALOG_READ_TOOLS)

        if plan.domain == "handoff" or plan.intent == "request_handoff":
            desired_write.extend(HANDOFF_TOOLS)

        if plan.domain == "crm":
            desired_read.extend(["contact_context"])
            desired_write.extend(CRM_TOOLS)

        # For general turns, contact_context is harmless if configured, but avoid
        # forcing tool usage. The LLM can answer without tools.
        read_tools = self._intersect(desired_read, configured)
        write_tools = self._intersect(desired_write, configured)
        allowed_tools = list(dict.fromkeys([*read_tools, *write_tools]))
        return ToolPlan(
            allowed_tools=allowed_tools,
            read_tools=read_tools,
            write_tools=write_tools,
            reason=f"domain={plan.domain};intent={plan.intent}",
        )

    def _intersect(self, desired: list[str], configured: list[str]) -> list[str]:
        configured_set = set(configured)
        return [tool for tool in dict.fromkeys(desired) if tool in configured_set]
