from app.services.agent_orchestration.planning.schemas import LLMPlanningResult
from app.services.agent_orchestration.tool_policy.tool_policy_service import ToolPolicyService


def test_tool_policy_allows_booking_confirmation_declaratively_for_exact_slot_selection():
    planning = LLMPlanningResult.model_validate(
        {
            "domain": "appointment",
            "intent": "select_offered_slot",
            "action_candidate": "prepare_booking_confirmation",
            "confidence": 0.95,
            "tool_request": {
                "lookup_tools": ["appointment_availability"],
                "write_tools": ["appointment_confirm"],
            },
            "entities": {
                "service_id": "service-uuid",
                "owner_id": "owner-maria-uuid",
            },
        }
    )

    decision = ToolPolicyService().evaluate(planning)

    assert decision.lookup_tools_enabled == ["appointment_availability"]
    assert decision.write_tools_requested == ["appointment_confirm"]
    assert decision.write_tools_enabled == ["appointment_confirm"]
    assert decision.write_tools_blocked == []


def test_tool_policy_allows_reschedule_only_for_explicit_reschedule_with_appointment_id():
    planning = LLMPlanningResult.model_validate(
        {
            "domain": "appointment",
            "intent": "request_reschedule",
            "action_candidate": "prepare_reschedule",
            "confidence": 0.9,
            "entities": {
                "appointment_id": "appointment-123",
            },
            "tool_request": {
                "lookup_tools": ["appointment_events"],
                "write_tools": ["appointment_reschedule"],
            },
        }
    )

    decision = ToolPolicyService().evaluate(planning)

    assert decision.lookup_tools_enabled == ["appointment_events"]
    assert decision.write_tools_enabled == ["appointment_reschedule"]
    assert decision.write_tools_blocked == []


def test_tool_policy_blocks_write_tools_when_clarification_is_needed():
    planning = LLMPlanningResult.model_validate(
        {
            "domain": "appointment",
            "intent": "select_offered_slot",
            "action_candidate": "ask_clarification",
            "confidence": 0.55,
            "clarification": {
                "needed": True,
                "missing_fields": ["owner_name"],
            },
            "risk_flags": {
                "ambiguous_reference": True,
            },
            "tool_request": {
                "lookup_tools": ["appointment_availability"],
                "write_tools": ["appointment_confirm", "appointment_cancel", "appointment_reschedule"],
            },
        }
    )

    decision = ToolPolicyService().evaluate(planning)

    assert decision.lookup_tools_enabled == ["appointment_availability"]
    assert decision.write_tools_enabled == []
    assert decision.write_tools_blocked == ["appointment_confirm", "appointment_cancel", "appointment_reschedule"]
    assert decision.reason == "blocked_by_ambiguous_reference_or_clarification"
