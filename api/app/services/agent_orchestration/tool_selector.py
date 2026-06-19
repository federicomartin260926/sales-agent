from __future__ import annotations

from typing import Any

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
            appointment = context.appointment if isinstance(context.appointment, dict) else {}
            if self._has_active_appointment_flow(appointment):
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};appointment_flow_active=true",
                )

            return self._crm_contact_submit_plan(plan, context, configured, allow_followup_read=True)

        if plan.domain == "crm" or plan.intent == "request_quote":
            return self._crm_contact_submit_plan(plan, context, configured, allow_followup_read=True)

        if plan.intent == "select_offered_slot":
            offered_slots = context.appointment.get("offered_slots") if isinstance(context.appointment, dict) else []
            if isinstance(offered_slots, list) and offered_slots:
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};offered_slots_present=true",
                )

        if plan.intent == "select_existing_appointment":
            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};existing_appointments_selected_by_llm=true",
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

            if not handoff_enabled:
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};handoff_disabled=true",
                )

            if handoff_strategy == "manual_wa_link":
                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};handoff_manual_link_only=true",
                )

            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};handoff_external_tool_not_configured=true",
            )

        if plan.intent == "request_booking_confirmation":
            appointment = context.appointment if isinstance(context.appointment, dict) else {}
            existing_appointment = appointment.get("existing_appointment")
            selected_slot = appointment.get("selected_slot")
            required_next_action = appointment.get("required_next_action")

            if isinstance(existing_appointment, dict) and existing_appointment:
                if required_next_action == "appointment_cancel":
                    appointment_cancel_allowed = "appointment_cancel" in configured
                    return ToolPlan(
                        allowed_tools=["appointment_cancel"] if appointment_cancel_allowed else [],
                        read_tools=[],
                        write_tools=["appointment_cancel"] if appointment_cancel_allowed else [],
                        reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_present;route_to_cancel=true",
                    )
                if isinstance(selected_slot, dict) and bool(selected_slot):
                    appointment_reschedule_allowed = "appointment_reschedule" in configured
                    return ToolPlan(
                        allowed_tools=["appointment_reschedule"] if appointment_reschedule_allowed else [],
                        read_tools=[],
                        write_tools=["appointment_reschedule"] if appointment_reschedule_allowed else [],
                        reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_present;selected_slot_present;route_to_reschedule=true",
                    )

                return ToolPlan(
                    allowed_tools=[],
                    read_tools=[],
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_blocks_booking_confirmation=true",
                )

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

        if plan.intent == "request_cancel":
            appointment = context.appointment if isinstance(context.appointment, dict) else {}
            existing_appointment = appointment.get("existing_appointment")
            required_next_action = appointment.get("required_next_action")

            if not isinstance(existing_appointment, dict) or not existing_appointment:
                read_tools = self._intersect(["appointment_events"], configured)
                return ToolPlan(
                    allowed_tools=read_tools,
                    read_tools=read_tools,
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_missing=true",
                )

            if required_next_action == "appointment_cancel":
                appointment_cancel_allowed = "appointment_cancel" in configured
                return ToolPlan(
                    allowed_tools=["appointment_cancel"] if appointment_cancel_allowed else [],
                    read_tools=[],
                    write_tools=["appointment_cancel"] if appointment_cancel_allowed else [],
                    reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_present;route_to_cancel=true",
                )

            return ToolPlan(
                allowed_tools=[],
                read_tools=[],
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_present;waiting_for_cancel_confirmation=true",
            )

        if plan.intent == "request_reschedule":
            appointment = context.appointment if isinstance(context.appointment, dict) else {}
            existing_appointment = appointment.get("existing_appointment")
            selected_slot = appointment.get("selected_slot")
            required_next_action = appointment.get("required_next_action")

            ready_for_reschedule = (
                isinstance(existing_appointment, dict)
                and bool(existing_appointment)
                and isinstance(selected_slot, dict)
                and bool(selected_slot)
                and required_next_action == "appointment_reschedule"
            )

            if ready_for_reschedule:
                appointment_reschedule_allowed = "appointment_reschedule" in configured
                return ToolPlan(
                    allowed_tools=["appointment_reschedule"] if appointment_reschedule_allowed else [],
                    read_tools=[],
                    write_tools=["appointment_reschedule"] if appointment_reschedule_allowed else [],
                    reason=f"domain={plan.domain};intent={plan.intent};ready_for_reschedule=true",
                )

            if not isinstance(existing_appointment, dict) or not existing_appointment:
                read_tools = self._intersect(["appointment_events"], configured)
                return ToolPlan(
                    allowed_tools=read_tools,
                    read_tools=read_tools,
                    write_tools=[],
                    reason=f"domain={plan.domain};intent={plan.intent};existing_appointment_missing=true",
                )

            read_tools = self._intersect(["appointment_availability"], configured)
            return ToolPlan(
                allowed_tools=read_tools,
                read_tools=read_tools,
                write_tools=[],
                reason=f"domain={plan.domain};intent={plan.intent};selected_slot_missing=true",
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

    def _has_contact_minimum(self, contact: Any) -> bool:
        if not isinstance(contact, dict):
            return False
        phone = self._read_value(contact, "phone")
        email = self._read_value(contact, "email")
        return self._is_non_empty_string(phone) or self._is_non_empty_string(email)

    def _has_active_appointment_flow(self, appointment: Any) -> bool:
        if not isinstance(appointment, dict):
            return False

        if self._read_value(appointment, "selected_slot") not in (None, {}, []):
            return True
        if self._read_value(appointment, "existing_appointment") not in (None, {}, []):
            return True
        if self._read_value(appointment, "existing_appointments") not in (None, [], {}):
            return True

        required_next_action = self._read_value(appointment, "required_next_action")
        if isinstance(required_next_action, str) and required_next_action.strip() != "":
            return required_next_action.strip() in {
                "collect_customer_name",
                "collect_contact_data",
                "select_offered_slot",
                "confirm_selected_slot",
                "appointment_confirm",
                "appointment_reschedule",
                "appointment_cancel",
            }

        return False

    def _is_non_empty_string(self, value: Any) -> bool:
        return isinstance(value, str) and value.strip() != ""
