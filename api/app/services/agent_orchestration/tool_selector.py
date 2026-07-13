from __future__ import annotations

from typing import Any

from app.schemas.llm import McpRemoteConfig
from app.services.agent_orchestration.schemas import BackendContext, ConversationContext, IntentPlan, ToolPlan


SUPPORTED_READ_TOOLS = [
    "contact_context",
    "services_search",
    "appointment_events",
    "appointment_availability",
]

WRITE_TOOL_BY_PLAN = {
    ("request_booking_confirmation", "prepare_booking_confirmation"): "appointment_confirm",
    ("request_booking_invitation", "create_booking_invitation"): "appointment_booking_invitation",
    ("request_reschedule", "prepare_reschedule"): "appointment_reschedule",
    ("request_cancel", "prepare_cancel"): "appointment_cancel",
    ("provide_contact_data", "create_or_update_crm_contact"): "crm_contact_submit",
    ("request_quote", "create_or_update_crm_contact"): "crm_contact_submit",
    ("request_handoff", "handoff_to_human"): "handoff_request",
}


class ToolSelector:
    """Selects allowed MCP tools for the next LLM call.

    It exposes supported read tools and maps plan-controlled writes directly.
    """

    def select(
        self,
        plan: IntentPlan,
        backend_context: BackendContext | None,
        conversation_context: ConversationContext | None,
        mcp_config: McpRemoteConfig | None,
    ) -> ToolPlan:
        configured = list(mcp_config.allowed_tools) if mcp_config is not None and mcp_config.enabled else []
        if not configured:
            return ToolPlan(reason="mcp_disabled_or_no_tools")

        supported_read_tools = self._supported_read_tools(configured)
        write_tools = self._write_tools(plan, configured)
        bootstrap_tool = self._bootstrap_tool(configured, backend_context)

        allowed_tools = list(dict.fromkeys([*supported_read_tools, *write_tools]))
        return ToolPlan(
            allowed_tools=allowed_tools,
            read_tools=supported_read_tools,
            write_tools=write_tools,
            bootstrap_tool=bootstrap_tool,
            reason=f"intent={plan.intent};action={plan.action};tools_exposed=true",
        )

    def _supported_read_tools(self, configured: list[str]) -> list[str]:
        return self._intersect(SUPPORTED_READ_TOOLS, configured)

    def _intersect(self, desired: list[str], configured: list[str]) -> list[str]:
        configured_set = set(configured)
        return [tool for tool in dict.fromkeys(desired) if tool in configured_set]

    def _write_tools(
        self,
        plan: IntentPlan,
        configured: list[str],
    ) -> list[str]:
        tool_name = WRITE_TOOL_BY_PLAN.get((plan.intent, plan.action))
        if tool_name is None or tool_name not in configured:
            return []

        return [tool_name]

    def _bootstrap_tool(self, configured: list[str], backend_context: BackendContext | None) -> str | None:
        if "contact_context" not in configured:
            return None
        if backend_context is None:
            return None
        if self._has_sufficient_contact_context(backend_context.contact_context):
            return None
        if not self._has_contact_identifier(backend_context.contact):
            return None

        return "contact_context"

    def _has_contact_identifier(self, contact: Any) -> bool:
        if contact is None:
            return False

        phone = getattr(contact, "phone", None)
        email = getattr(contact, "email", None)
        return self._is_non_empty_string(phone) or self._is_non_empty_string(email)

    def _has_sufficient_contact_context(self, contact_context: dict[str, Any] | None) -> bool:
        if not isinstance(contact_context, dict) or contact_context == {}:
            return False

        contact = contact_context.get("contact")
        if isinstance(contact, dict):
            for key in ("name", "phone", "email", "id"):
                value = contact.get(key)
                if self._is_non_empty_string(value):
                    return True

        for key in ("name", "phone", "email", "timezone", "branch", "summary"):
            value = contact_context.get(key)
            if self._is_non_empty_string(value):
                return True

        appointments = contact_context.get("appointments")
        if isinstance(appointments, dict):
            next_item = appointments.get("next")
            items = appointments.get("items")
            if next_item not in (None, {}, []):
                return True
            if isinstance(items, list) and items != []:
                return True
        if isinstance(appointments, list) and appointments != []:
            return True

        return False

    def _is_non_empty_string(self, value: Any) -> bool:
        return isinstance(value, str) and value.strip() != ""
