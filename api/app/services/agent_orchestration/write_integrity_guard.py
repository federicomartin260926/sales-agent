from __future__ import annotations

import json
from typing import Any

from app.services.agent_orchestration.schemas import LLMFinalResponse


class WriteIntegrityGuard:
    """Post-write integrity guard for externally observable confirmations.

    This guard does not plan conversations, gate tools, or interpret free text.
    Its only responsibility is to avoid marking an external write as completed
    unless there is direct tool evidence that the write actually succeeded.
    """

    def apply(
        self,
        final: LLMFinalResponse,
        primary: LLMFinalResponse,
        authorized_write_tool: str | None,
        llm_result: Any | None,
    ) -> LLMFinalResponse:
        if authorized_write_tool == "appointment_booking_invitation":
            final = self._guard_booking_invitation(final, llm_result)

        baseline_booking_prepare = (
            primary.intent == "request_booking_confirmation"
            and primary.action == "prepare_booking_confirmation"
        )
        if not baseline_booking_prepare and authorized_write_tool != "appointment_confirm":
            return final

        if self._appointment_confirm_succeeded(llm_result):
            return final

        guarded_structured_data = self._clean_booking_confirmation_structured_data(final.structured_data)
        guarded_data_to_save = self._clean_booking_confirmation_data_to_save(final.data_to_save)

        success_actions = {"completed"}
        if authorized_write_tool == "appointment_confirm":
            success_actions.add("appointment_confirmed")

        if final.action not in success_actions:
            return final.model_copy(
                update={
                    "structured_data": guarded_structured_data,
                    "data_to_save": guarded_data_to_save,
                }
            )

        return final.model_copy(
            update={
                "reply": "Todavía no puedo confirmar la cita. Puedo revisar otro horario o volver a comprobar la disponibilidad.",
                "action": "appointment_failed",
                "needs_human": False,
                "required_next_action": None,
                "structured_data": guarded_structured_data,
                "data_to_save": guarded_data_to_save,
            }
        )

    def _guard_booking_invitation(
        self,
        final: LLMFinalResponse,
        llm_result: Any | None,
    ) -> LLMFinalResponse:
        trace, parsed_output = self._latest_mcp_call_output(
            llm_result,
            "appointment_booking_invitation",
        )

        guarded_structured_data = (
            final.structured_data.model_copy(deep=True)
            if hasattr(final.structured_data, "model_copy")
            else final.structured_data
        )

        if hasattr(guarded_structured_data, "appointment"):
            appointment = guarded_structured_data.appointment
            if hasattr(appointment, "booking_invitation"):
                appointment.booking_invitation = (
                    dict(parsed_output)
                    if isinstance(parsed_output, dict)
                    else None
                )

        guarded_data_to_save = dict(final.data_to_save or {})
        guarded_data_to_save.pop("booking_invitation", None)
        guarded_data_to_save.pop("booking_url", None)

        if trace is None and final.action != "completed":
            return final

        if self._booking_invitation_succeeded(parsed_output):
            return final.model_copy(
                update={
                    "structured_data": guarded_structured_data,
                    "data_to_save": guarded_data_to_save,
                }
            )

        return final.model_copy(
            update={
                "reply": "No he podido generar el enlace de reserva ahora mismo. Puedo intentarlo de nuevo o ayudarte a buscar un horario.",
                "action": "appointment_failed",
                "needs_human": False,
                "required_next_action": None,
                "structured_data": guarded_structured_data,
                "data_to_save": guarded_data_to_save,
            }
        )

    def _booking_invitation_succeeded(self, parsed_output: Any) -> bool:
        if not isinstance(parsed_output, dict):
            return False

        booking_url = self._clean(parsed_output.get("booking_url"))
        return (
            self._is_truthy(parsed_output.get("ok"))
            and self._is_truthy(parsed_output.get("created"))
            and booking_url is not None
        )

    def _appointment_confirm_succeeded(self, llm_result: Any | None) -> bool:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "appointment_confirm")
        if trace is None or not isinstance(parsed_output, dict):
            return False

        ok = self._is_truthy(parsed_output.get("ok"))
        confirmed = self._is_confirmed_truthy(parsed_output.get("confirmed")) or self._is_confirmed_truthy(parsed_output.get("appointment_confirmed"))
        return ok and confirmed

    def _clean_booking_confirmation_structured_data(self, structured_data: Any) -> Any:
        guarded_structured_data = structured_data.model_copy(deep=True) if hasattr(structured_data, "model_copy") else structured_data
        if hasattr(guarded_structured_data, "appointment"):
            appointment = guarded_structured_data.appointment
            if hasattr(appointment, "booking_result"):
                appointment.booking_result = None
        return guarded_structured_data

    def _clean_booking_confirmation_data_to_save(self, data_to_save: Any) -> dict[str, Any]:
        guarded_data_to_save = dict(data_to_save or {})
        for key in (
            "booking_result",
            "booking_url",
            "appointment_confirm_post_processed",
            "appointment_confirmed",
            "appointment_confirm_status",
            "appointment_confirm_error_code",
            "appointment_id",
            "appointment_start_at",
            "appointment_end_at",
            "appointment_old_start_at",
            "appointment_old_end_at",
            "appointment_new_start_at",
            "appointment_new_end_at",
        ):
            guarded_data_to_save.pop(key, None)
        return guarded_data_to_save

    def _latest_mcp_call_output(self, llm_result: Any | None, tool_name: str) -> tuple[Any | None, dict[str, Any] | None]:
        if llm_result is None:
            return None, None

        tool_traces = getattr(llm_result, "tool_traces", [])
        if not isinstance(tool_traces, list) or tool_traces == []:
            return None, None

        for trace in reversed(tool_traces):
            trace_type = self._clean(getattr(trace, "type", None))
            candidate_tool_name = self._clean(getattr(trace, "tool_name", None))
            if trace_type != "mcp_call" or candidate_tool_name != tool_name:
                continue

            parsed_output = self._tool_trace_output_dict(trace)
            if parsed_output is None and self._clean(getattr(trace, "status", None)) is None and self._clean(getattr(trace, "error_code", None)) is None:
                return trace, None

            return trace, parsed_output

        return None, None

    def _tool_trace_output_dict(self, trace: Any) -> dict[str, Any] | None:
        output = getattr(trace, "output", None)
        if isinstance(output, dict):
            return output
        if isinstance(output, str):
            try:
                parsed_output = json.loads(output)
            except Exception:
                return None
            if isinstance(parsed_output, dict):
                return parsed_output
        return None

    def _is_truthy(self, value: Any) -> bool:
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)):
            return value == 1
        if isinstance(value, str):
            normalized = value.strip().lower()
            return normalized in {"1", "true", "yes"}
        return False

    def _is_confirmed_truthy(self, value: Any) -> bool:
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)):
            return value == 1
        if isinstance(value, str):
            normalized = value.strip().lower()
            return normalized in {"1", "true", "yes", "confirmed"}
        return False

    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None
