import json

from app.services.agent_orchestration.planning.intent_planner import IntentPlannerService


def test_planning_result_parses_select_offered_slot_json():
    planner = IntentPlannerService()
    result = planner.parse_planning_result(
        json.dumps(
            {
                "schema_version": "1.0",
                "domain": "appointment",
                "intent": "select_offered_slot",
                "action_candidate": "prepare_booking_confirmation",
                "confidence": 0.91,
                "entities": {
                    "service_name": "Láser cuerpo entero",
                    "owner_name": "María Gutiérrez",
                    "date": "2026-06-16",
                    "time": "16:45",
                },
                "context_request": {
                    "include_conversation_history": True,
                    "conversation_history_level": "recent",
                    "include_customer_context": "basic",
                    "include_appointment_context": True,
                    "include_offered_slots": True,
                },
                "tool_request": {
                    "lookup_tools": ["appointment_availability"],
                    "write_tools": ["appointment_confirm"],
                    "blocked_tools": [],
                },
                "risk_flags": {
                    "ambiguous_reference": False,
                    "missing_required_data": False,
                    "low_confidence": False,
                },
                "clarification": {
                    "needed": False,
                    "missing_fields": [],
                },
                "reason": "exact slot selection",
            },
            ensure_ascii=False,
        )
    )

    assert result.schema_version == "1.0"
    assert result.domain == "appointment"
    assert result.intent == "select_offered_slot"
    assert result.action_candidate == "prepare_booking_confirmation"
    assert result.confidence == 0.91
    assert result.entities.owner_name == "María Gutiérrez"
    assert result.tool_request.lookup_tools == ["appointment_availability"]
    assert result.tool_request.write_tools == ["appointment_confirm"]
    assert result.clarification.needed is False


def test_planning_result_invalid_json_falls_back_to_unknown():
    planner = IntentPlannerService()

    result = planner.parse_planning_result("{not-json}")

    assert result.intent == "unknown"
    assert result.action_candidate == "no_action"
    assert result.clarification.needed is True
    assert result.risk_flags.low_confidence is True


def test_planning_result_empty_dict_falls_back_to_unknown():
    planner = IntentPlannerService()

    result = planner.parse_planning_result({})

    assert result.intent == "unknown"
    assert result.action_candidate == "no_action"
    assert result.clarification.needed is True
