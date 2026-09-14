import json
from types import SimpleNamespace

from app.services.agent_orchestration.schemas import IntentPlan, LLMFinalResponse
from app.services.agent_orchestration.write_integrity_guard import WriteIntegrityGuard


def invitation_plan() -> IntentPlan:
    return IntentPlan(
        domain="appointment",
        intent="request_booking_invitation",
        action="create_booking_invitation",
        confidence=0.95,
        needs_tools=True,
    )


def llm_result_for_invitation(output: dict):
    trace = SimpleNamespace(
        type="mcp_call",
        tool_name="appointment_booking_invitation",
        output=json.dumps(output),
        status="completed",
        error_code=None,
    )
    return SimpleNamespace(tool_traces=[trace])


def test_booking_invitation_failure_overrides_false_success():
    output = {
        "ok": False,
        "created": False,
        "booking_url": None,
        "invitation": {},
        "message": "Invitación de reserva creada.",
        "raw_summary": {
            "source": "crm",
            "status": "pending",
        },
    }

    final = LLMFinalResponse(
        reply="He creado la invitación. En breve recibirás el enlace.",
        domain="appointment",
        intent="request_booking_invitation",
        action="completed",
        data_to_save={
            "booking_url": "https://inventado.example/reserva",
        },
    )

    guarded = WriteIntegrityGuard().apply(
        final,
        invitation_plan(),
        llm_result_for_invitation(output),
    )

    assert guarded.action == "appointment_failed"
    assert guarded.needs_human is False
    assert guarded.required_next_action is None
    assert guarded.reply == (
        "No he podido generar el enlace de reserva ahora mismo. "
        "Puedo intentarlo de nuevo o ayudarte a buscar un horario."
    )
    assert guarded.structured_data.appointment.booking_invitation == output
    assert "booking_url" not in guarded.data_to_save


def test_booking_invitation_failure_also_overrides_answer_directly():
    output = {
        "ok": False,
        "created": False,
        "booking_url": None,
    }

    final = LLMFinalResponse(
        reply="He creado el enlace.",
        domain="appointment",
        intent="request_booking_invitation",
        action="answer_directly",
    )

    guarded = WriteIntegrityGuard().apply(
        final,
        invitation_plan(),
        llm_result_for_invitation(output),
    )

    assert guarded.action == "appointment_failed"
    assert guarded.structured_data.appointment.booking_invitation == output


def test_booking_invitation_success_preserves_response_and_persists_tool_result():
    output = {
        "ok": True,
        "created": True,
        "booking_url": "https://example.test/book/abc",
        "invitation": {
            "id": "inv-1",
        },
    }

    final = LLMFinalResponse(
        reply="Aquí tienes tu enlace: https://example.test/book/abc",
        domain="appointment",
        intent="request_booking_invitation",
        action="completed",
    )

    guarded = WriteIntegrityGuard().apply(
        final,
        invitation_plan(),
        llm_result_for_invitation(output),
    )

    assert guarded.action == "completed"
    assert guarded.reply == final.reply
    assert guarded.structured_data.appointment.booking_invitation == output


def test_booking_invitation_without_tool_call_does_not_force_failure():
    final = LLMFinalResponse(
        reply="Necesito un dato más para generar el enlace.",
        domain="appointment",
        intent="request_booking_invitation",
        action="ask_clarification",
    )

    guarded = WriteIntegrityGuard().apply(
        final,
        invitation_plan(),
        SimpleNamespace(tool_traces=[]),
    )

    assert guarded.action == "ask_clarification"
    assert guarded.reply == final.reply


def test_booking_invitation_completed_without_tool_evidence_is_blocked():
    final = LLMFinalResponse(
        reply="He creado el enlace.",
        domain="appointment",
        intent="request_booking_invitation",
        action="completed",
        data_to_save={
            "booking_url": "https://inventado.example/reserva",
        },
    )

    guarded = WriteIntegrityGuard().apply(
        final,
        invitation_plan(),
        SimpleNamespace(tool_traces=[]),
    )

    assert guarded.action == "appointment_failed"
    assert guarded.needs_human is False
    assert guarded.structured_data.appointment.booking_invitation is None
    assert "booking_url" not in guarded.data_to_save
