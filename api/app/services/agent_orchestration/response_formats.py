from __future__ import annotations

from typing import Any


def build_request_availability_response_format() -> dict[str, Any]:
    def nullable_string() -> dict[str, Any]:
        return {"anyOf": [{"type": "string"}, {"type": "null"}]}

    def closed_object(properties: dict[str, Any]) -> dict[str, Any]:
        return {
            "type": "object",
            "properties": properties,
            "required": list(properties.keys()),
            "additionalProperties": False,
        }

    return {
        "type": "json_schema",
        "name": "request_availability_response",
        "strict": True,
        "schema": closed_object(
            {
                "reply": {"type": "string"},
                "domain": {"type": "string"},
                "intent": {"type": "string"},
                "action": {"type": "string"},
                "needs_human": {"type": "boolean"},
                "score": {"type": "number"},
                "structured_data": closed_object(
                    {
                        "appointment": closed_object(
                            {
                                "offered_slots": {
                                    "type": "array",
                                    "minItems": 1,
                                    "items": build_appointment_slot_schema(),
                                }
                            }
                        )
                    }
                ),
                "next_expected": {
                    "anyOf": [
                        {
                            "type": "object",
                            "properties": {
                                "kind": {"type": "string"},
                                "description": nullable_string(),
                            },
                            "required": ["kind", "description"],
                            "additionalProperties": False,
                        },
                        {"type": "null"},
                    ]
                },
                "required_next_action": {
                    "anyOf": [
                        {"type": "string"},
                        {"type": "null"},
                    ]
                },
            }
        ),
    }


def build_select_offered_slot_response_format() -> dict[str, Any]:
    def nullable_string() -> dict[str, Any]:
        return {"anyOf": [{"type": "string"}, {"type": "null"}]}

    def closed_object(properties: dict[str, Any]) -> dict[str, Any]:
        return {
            "type": "object",
            "properties": properties,
            "required": list(properties.keys()),
            "additionalProperties": False,
        }

    return {
        "type": "json_schema",
        "name": "select_offered_slot_response",
        "strict": True,
        "schema": closed_object(
            {
                "reply": {"type": "string"},
                "domain": {"type": "string"},
                "intent": {"type": "string"},
                "action": {"type": "string"},
                "needs_human": {"type": "boolean"},
                "score": {"type": "number"},
                "structured_data": closed_object(
                    {
                        "appointment": closed_object(
                            {
                                "selected_slot": build_appointment_slot_schema(),
                            }
                        )
                    }
                ),
                "next_expected": {
                    "anyOf": [
                        {
                            "type": "object",
                            "properties": {
                                "kind": {"type": "string"},
                                "description": nullable_string(),
                            },
                            "required": ["kind", "description"],
                            "additionalProperties": False,
                        },
                        {"type": "null"},
                    ]
                },
                "required_next_action": {
                    "anyOf": [
                        {"type": "string"},
                        {"type": "null"},
                    ]
                },
            }
        ),
    }


def build_appointment_slot_schema() -> dict[str, Any]:
    def nullable_string() -> dict[str, Any]:
        return {"anyOf": [{"type": "string"}, {"type": "null"}]}

    slot_properties = {
        "start": {"type": "string"},
        "end": {"type": "string"},
        "timezone": nullable_string(),
        "service_id": nullable_string(),
        "service_name": nullable_string(),
        "service_ref": nullable_string(),
        "owner_id": nullable_string(),
        "owner_name": nullable_string(),
        "owner_ref": nullable_string(),
        "display_time": nullable_string(),
        "slot_label": nullable_string(),
    }

    return {
        "type": "object",
        "properties": slot_properties,
        "required": list(slot_properties.keys()),
        "additionalProperties": False,
    }


def build_existing_appointment_schema() -> dict[str, Any]:
    def nullable_string() -> dict[str, Any]:
        return {"anyOf": [{"type": "string"}, {"type": "null"}]}

    appointment_properties = {
        "id": {"type": "string"},
        "start": {"type": "string"},
        "end": {"type": "string"},
        "timezone": {"type": "string"},
        "title": nullable_string(),
        "status": nullable_string(),
        "owner_id": nullable_string(),
        "owner_name": nullable_string(),
        "service_id": nullable_string(),
        "service_name": nullable_string(),
    }

    return {
        "type": "object",
        "properties": appointment_properties,
        "required": list(appointment_properties.keys()),
        "additionalProperties": False,
    }


def build_request_cancel_response_format() -> dict[str, Any]:
    def nullable_string() -> dict[str, Any]:
        return {"anyOf": [{"type": "string"}, {"type": "null"}]}

    def closed_object(properties: dict[str, Any]) -> dict[str, Any]:
        return {
            "type": "object",
            "properties": properties,
            "required": list(properties.keys()),
            "additionalProperties": False,
        }

    return {
        "type": "json_schema",
        "name": "request_cancel_response",
        "strict": True,
        "schema": closed_object(
            {
                "reply": {"type": "string"},
                "domain": {"type": "string"},
                "intent": {"type": "string"},
                "action": {"type": "string"},
                "needs_human": {"type": "boolean"},
                "score": {"type": "number"},
                "structured_data": closed_object(
                    {
                        "appointment": closed_object(
                            {
                                "existing_appointment": {
                                    "anyOf": [
                                        build_existing_appointment_schema(),
                                        {"type": "null"},
                                    ]
                                },
                                "existing_appointments": {
                                    "type": "array",
                                    "items": build_existing_appointment_schema(),
                                },
                            }
                        )
                    }
                ),
                "next_expected": {
                    "anyOf": [
                        {
                            "type": "object",
                            "properties": {
                                "kind": {"type": "string"},
                                "description": nullable_string(),
                            },
                            "required": ["kind", "description"],
                            "additionalProperties": False,
                        },
                        {"type": "null"},
                    ]
                },
                "required_next_action": {
                    "anyOf": [
                        {"type": "string"},
                        {"type": "null"},
                    ]
                },
            }
        ),
    }
