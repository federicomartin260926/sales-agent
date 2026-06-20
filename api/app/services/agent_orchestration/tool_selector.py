from __future__ import annotations

from typing import Any

from app.schemas.llm import McpRemoteConfig
from app.services.agent_orchestration.schemas import IntentPlan, RuntimeContext, ToolPlan


APPOINTMENT_READ_TOOLS = [
    "contact_context",
    "services_search",
    "appointment_events",
    "appointment_availability",
]

APPOINTMENT_WRITE_TOOLS_BY_INTENT = {
    "request_booking_confirmation": ["appointment_confirm"],
    "request_reschedule": ["appointment_reschedule"],
    "request_cancel": ["appointment_cancel"],
}

CATALOG_READ_TOOLS = ["services_search"]
HANDOFF_TOOLS = ["handoff_request"]
CRM_TOOLS = ["crm_contact_submit", "contact_context"]


class ToolSelector:
    """Selects allowed MCP tools for the next LLM call.

    The selector only exposes tools relevant to the domain and intent already
    returned by the LLM planner. It does not manage conversational microstates.
    """

    def select(self, plan: IntentPlan, context: RuntimeContext, mcp_config: McpRemoteConfig | None) -> ToolPlan:
        configured = list(mcp_config.allowed_tools) if mcp_config is not None and mcp_config.enabled else []
        if not configured:
            return ToolPlan(reason="mcp_disabled_or_no_tools")

        if plan.domain == "appointment" or plan.intent in {
            "request_availability",
            "select_offered_slot",
            "select_existing_appointment",
            "request_booking_confirmation",
            "request_reschedule",
            "request_cancel",
            "provide_contact_data",
        }:
            read_tools = self._intersect(APPOINTMENT_READ_TOOLS, configured)
            write_tools = self._appointment_write_tools(plan.intent, context, configured)

            allowed_tools = list(dict.fromkeys([*read_tools, *write_tools]))
            return ToolPlan(
                allowed_tools=allowed_tools,
                read_tools=read_tools,
                write_tools=write_tools,
                reason=f"domain={plan.domain};intent={plan.intent};appointment_tools_exposed=true",
            )

        if plan.domain == "crm" or plan.intent == "request_quote":
            return self._crm_contact_submit_plan(plan, context, configured, allow_followup_read=True)

        if plan.domain == "catalog" or plan.intent in {"ask_product_or_service_info", "catalog_search"}:
            read_tools = self._intersect(CATALOG_READ_TOOLS, configured)
            return ToolPlan(
                allowed_tools=read_tools,
                read_tools=read_tools,
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};catalog_tools_exposed=true",
            )

        if plan.domain == "handoff" or plan.intent == "request_handoff":
            handoff_enabled, handoff_strategy = self._handoff_settings(context)
            external_handoff_enabled = handoff_enabled and handoff_strategy in {"n8n_webhook", "manual_wa_link_and_n8n"}
            handoff_request_allowed = external_handoff_enabled and "handoff_request" in configured

            if handoff_request_allowed:
                return ToolPlan(
                    allowed_tools=["handoff_request"],
                    read_tools=[],
                    write_tools=["handoff_request"],
                    reason=f"domain={plan.domain};intent={plan.intent};handoff_enabled=true;handoff_strategy={handoff_strategy};route_to_handoff_request=true",
                )

            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};handoff_unavailable_or_disabled=true",
            )

        read_tools = self._intersect(["contact_context"], configured)
        return ToolPlan(
            allowed_tools=read_tools,
            read_tools=read_tools,
            write_tools=[],
            reason=f"domain={plan.domain};intent={plan.intent};default_tools_exposed=true",
        )

    def _intersect(self, desired: list[str], configured: list[str]) -> list[str]:
        configured_set = set(configured)
        return [tool for tool in dict.fromkeys(desired) if tool in configured_set]

    def _handoff_settings(self, context: RuntimeContext) -> tuple[bool, str]:
        tenant = self._read_value(context, "tenant", {}) or {}
        handoff = self._read_value(tenant, "handoff", {}) or {}

        enabled = bool(self._read_value(handoff, "enabled", False))
        strategy = self._read_value(handoff, "strategy", "disabled")
        normalized = str(strategy).strip().lower() if strategy is not None else "disabled"
        if normalized not in {"disabled", "manual_wa_link", "n8n_webhook", "manual_wa_link_and_n8n"}:
            normalized = "disabled"

        return enabled, normalized

    def _read_value(self, value: Any, key: str, default: Any = None) -> Any:
        if isinstance(value, dict):
            return value.get(key, default)
        return getattr(value, key, default)

    def _crm_contact_submit_plan(
        self,
        plan: IntentPlan,
        context: RuntimeContext,
        configured: list[str],
        allow_followup_read: bool,
    ) -> ToolPlan:
        contact = context.contact if isinstance(context.contact, dict) else {}
        has_contact_minimum = self._has_contact_minimum(contact)

        if not has_contact_minimum:
            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};crm_contact_submit_missing_contact=true",
            )

        contact_context_available = "contact_context" in configured
        crm_submit_available = "crm_contact_submit" in configured

        read_tools: list[str] = []
        if contact_context_available and allow_followup_read:
            read_tools.append("contact_context")

        if not crm_submit_available:
            return ToolPlan(
                allowed_tools=read_tools,
                read_tools=read_tools,
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};crm_contact_submit_not_configured=true",
            )

        allowed_tools = list(dict.fromkeys([*read_tools, "crm_contact_submit"]))
        return ToolPlan(
            allowed_tools=allowed_tools,
            read_tools=read_tools,
            write_tools=["crm_contact_submit"],
            reason=f"domain={plan.domain};intent={plan.intent};route_to_crm_contact_submit=true",
        )

    def _appointment_write_tools(self, intent: str, context: RuntimeContext, configured: list[str]) -> list[str]:
        write_tools = self._intersect(APPOINTMENT_WRITE_TOOLS_BY_INTENT.get(intent, []), configured)

        if intent == "request_booking_confirmation":
            return ["appointment_confirm"] if "appointment_confirm" in write_tools else []

        if intent == "request_reschedule":
            return ["appointment_reschedule"] if "appointment_reschedule" in write_tools else []

        if intent == "request_cancel":
            return ["appointment_cancel"] if "appointment_cancel" in write_tools else []

        return write_tools

    def _has_contact_minimum(self, contact: Any) -> bool:
        if not isinstance(contact, dict):
            return False
        phone = self._read_value(contact, "phone")
        email = self._read_value(contact, "email")
        return self._is_non_empty_string(phone) or self._is_non_empty_string(email)

    def _is_non_empty_string(self, value: Any) -> bool:
        return isinstance(value, str) and value.strip() != ""
