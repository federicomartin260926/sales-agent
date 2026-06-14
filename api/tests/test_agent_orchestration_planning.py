import json

from app.services.agent_orchestration.planning.intent_planner import IntentPlannerService
from app.services.agent_orchestration.planning.prompts import build_planning_system_prompt


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


def test_planning_result_parses_json_inside_markdown_fence():
    planner = IntentPlannerService()
    raw = """```json
{
  "schema_version": "1.0",
  "domain": "appointment",
  "intent": "select_offered_slot",
  "action_candidate": "prepare_booking_confirmation",
  "confidence": 0.89
}
```"""

    result = planner.parse_planning_result(raw)

    assert result.intent == "select_offered_slot"
    assert result.action_candidate == "prepare_booking_confirmation"
    assert result.confidence == 0.89


def test_planning_result_parses_json_embedded_in_text():
    planner = IntentPlannerService()
    raw = "Respuesta: {\"schema_version\":\"1.0\",\"domain\":\"appointment\",\"intent\":\"request_availability\",\"action_candidate\":\"get_availability\",\"confidence\":0.77} Gracias."

    result = planner.parse_planning_result(raw)

    assert result.intent == "request_availability"
    assert result.action_candidate == "get_availability"
    assert result.confidence == 0.77


def test_planning_result_invalid_json_falls_back_to_unknown():
    planner = IntentPlannerService()

    result = planner.parse_planning_result("{not-json}")

    assert result.intent == "unknown"
    assert result.action_candidate == "no_action"
    assert result.clarification.needed is True


def test_planning_result_validation_failure_exposes_safe_diagnostics():
    planner = IntentPlannerService()

    result, diagnostics = planner.parse_planning_result_with_diagnostics(
        json.dumps(
            {
                "schema_version": "1.0",
                "domain": "appointment",
                "intent": "select_offered_slot",
                "action_candidate": "prepare_booking_confirmation",
                "confidence": 0.5,
                "entities": {
                    "owner_name": "María Gutiérrez",
                },
                "risk_flags": {
                    "ambiguous_reference": False,
                    "missing_required_data": False,
                    "low_confidence": False,
                    "explicit_booking_intent": True,
                },
                "unexpected_field": "oops",
            },
            ensure_ascii=False,
        )
    )

    assert result.intent == "unknown"
    assert diagnostics is not None
    assert diagnostics.error_type == "pydantic_validation_error"
    assert diagnostics.error_message
    assert diagnostics.raw_content_preview is not None
    assert "María Gutiérrez" in diagnostics.raw_content_preview
    assert diagnostics.validation_errors
    assert result.risk_flags.low_confidence is True


def test_planning_result_empty_dict_falls_back_to_unknown():
    planner = IntentPlannerService()

    result = planner.parse_planning_result({})

    assert result.intent == "unknown"
    assert result.action_candidate == "no_action"
    assert result.clarification.needed is True


def test_planning_system_prompt_is_explicit_about_contract_and_service_intents():
    prompt = build_planning_system_prompt()

    assert "domain" in prompt
    assert "catalog" in prompt
    assert "ask_product_or_service_info" in prompt
    assert "action_candidate" in prompt
    assert "search_catalog" in prompt
    assert "entities.service_name" in prompt
    assert "No uses valores traducidos" in prompt
