from __future__ import annotations

from dataclasses import dataclass
from typing import Any


@dataclass(frozen=True)
class CustomerDecision:
    kind: str
    reason: str
    message: str | None = None
    data_fields: tuple[str, ...] = ()
    evidence_refs: tuple[str, ...] = ()


READ_APPOINTMENT_TOOLS = {"contact_context", "appointment_events"}


class CustomerSimulator:
    """Advances a scenario using structured evidence, never reply text."""

    def decide(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        allow_writes: bool = False,
    ) -> CustomerDecision:
        if not turns:
            return CustomerDecision(
                kind="message",
                reason="scenario_initial_message",
                message=str(scenario["initial_message"]),
                data_fields=tuple(scenario.get("initial_data_fields", [])),
            )

        scenario_type = str(scenario.get("scenario_type", "appointment"))

        if scenario_type == "appointment" and scenario.get("mode") == "live":
            live_decision = self._live_decision(scenario, turns, allow_writes)
            if live_decision is not None:
                return live_decision

        if scenario_type != "appointment":
            return self._generic_decision(
                scenario,
                turns,
                allow_writes=allow_writes,
            )

        selected_slot = self._latest_structured_value(turns, "selected_slot")
        existing_appointment = self._latest_structured_value(turns, "existing_appointment")
        if existing_appointment is None:
            appointments = self._reliable_appointments(turns)
            if len(appointments) == 1:
                existing_appointment = appointments[0][0]

        last = turns[-1]
        action = self._clean(last.get("action"))
        ready_action = str(scenario["ready_action"])
        requires_selected_slot = bool(scenario["requires_selected_slot"])
        requires_existing_appointment = bool(scenario["requires_existing_appointment"])
        ready_data = (
            (not requires_selected_slot or selected_slot is not None)
            and (not requires_existing_appointment or existing_appointment is not None)
        )

        if action == ready_action and ready_data:
            if scenario.get("mode") == "live":
                if not allow_writes:
                    return CustomerDecision(
                        kind="live_write_not_enabled",
                        reason="live_write_not_enabled",
                        evidence_refs=tuple(last.get("artifact_refs", [])),
                    )
                return CustomerDecision(
                    kind="write_confirmation",
                    reason="confirm_live_write",
                    message=str(scenario["confirmation_message"]),
                    data_fields=("explicit_write_confirmation",),
                    evidence_refs=tuple(last.get("artifact_refs", [])),
                )
            terminal_state = str(scenario.get("terminal_state", "ready_to_write"))
            return CustomerDecision(
                kind=terminal_state,
                reason=f"structured_action={ready_action}; required structured evidence is present",
                evidence_refs=tuple(last.get("artifact_refs", [])),
            )

        if scenario.get("expects_no_availability") and self._explicit_no_availability(turns):
            return CustomerDecision(
                kind="completed",
                reason="expected_no_availability",
                evidence_refs=tuple(last.get("artifact_refs", [])),
            )

        if (
            requires_existing_appointment
            and existing_appointment is None
            and self._explicit_missing_appointment(turns)
        ):
            return CustomerDecision(
                kind="warn",
                reason="missing_precondition",
                evidence_refs=tuple(last.get("artifact_refs", [])),
            )

        follow_up = self._next_declarative_message(scenario, turns, action)
        if follow_up is not None:
            field, message = follow_up
            return CustomerDecision(
                kind="message",
                reason=f"provide_declarative_scenario_data:{field}",
                message=message,
                data_fields=(field,),
            )

        offered_slots = self._reliable_slots(turns)
        if requires_selected_slot and selected_slot is None and offered_slots:
            slot, source = self._select_slot(offered_slots, scenario.get("preferred_slot_strategy", "first"))
            message = self._slot_selection_message(slot)
            if message is None:
                return CustomerDecision(
                    kind="warn",
                    reason="A reliable slot exists, but it has no structured identifier or exact time usable by the customer simulator.",
                    evidence_refs=(source,),
                )
            return CustomerDecision(
                kind="message",
                reason="select_reliable_slot",
                message=message,
                data_fields=("selected_slot_reference",),
                evidence_refs=(source,),
            )

        if requires_existing_appointment and existing_appointment is None:
            appointments = self._reliable_appointments(turns)
            if appointments:
                appointment, source = appointments[0]
                message = self._appointment_selection_message(appointment)
                if message is not None:
                    return CustomerDecision(
                        kind="message",
                        reason="select_reliable_existing_appointment",
                        message=message,
                        data_fields=("appointment_reference",),
                        evidence_refs=(source,),
                    )

        clarification = self._next_customer_datum(scenario, turns, action)
        if clarification is not None:
            field, value = clarification
            return CustomerDecision(
                kind="message",
                reason=f"provide_explicit_scenario_data:{field}",
                message=self._datum_message(field, value),
                data_fields=(field,),
            )

        return CustomerDecision(
            kind="warn",
            reason="insufficient_structured_evidence",
            evidence_refs=tuple(last.get("artifact_refs", [])),
        )

    def _generic_decision(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        allow_writes: bool,
    ) -> CustomerDecision:
        """
        Advance non-appointment scenarios from structured planner/tool evidence.

        This branch intentionally does not inspect assistant reply text.
        """

        last = turns[-1]
        action = self._clean(last.get("action"))
        ready_action = self._clean(scenario.get("ready_action"))
        expected_write = self._clean(scenario.get("expected_write_tool"))

        write_calls = [
            call
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
            if call.get("tool_name") == expected_write
        ]

        if expected_write is not None and write_calls:
            write_call = write_calls[-1]
            verify_tool = self._clean(scenario.get("verify_with_tool"))

            if verify_tool is None:
                return CustomerDecision(
                    kind="completed",
                    reason=f"structured_tool={expected_write}",
                    evidence_refs=tuple(write_call.get("evidence_refs", [])),
                )

            write_step = write_call.get("step")
            verification_calls = [
                call
                for turn in turns
                for call in turn.get("executed_mcp_calls", [])
                if call.get("tool_name") == verify_tool
                and isinstance(call.get("step"), int)
                and isinstance(write_step, int)
                and call.get("step") > write_step
            ]

            if verification_calls:
                return CustomerDecision(
                    kind="completed",
                    reason=f"structured_tool={verify_tool}",
                    evidence_refs=tuple(
                        verification_calls[-1].get("evidence_refs", [])
                    ),
                )

            verification_sent = any(
                turn.get("customer_decision") == "verification_message"
                for turn in turns
            )
            verification_message = scenario.get("verification_message")

            if not verification_sent and verification_message:
                return CustomerDecision(
                    kind="verification_message",
                    reason=f"verify_live_write:{verify_tool}",
                    message=str(verification_message),
                    data_fields=("post_write_verification",),
                    evidence_refs=tuple(write_call.get("evidence_refs", [])),
                )

            return CustomerDecision(
                kind="warn",
                reason="post_write_verification_missing",
                evidence_refs=tuple(write_call.get("evidence_refs", [])),
            )

        # Scenario-defined customer follow-ups are deterministic test input,
        # not semantic interpretation of the assistant response.
        followups = scenario.get("follow_up_messages", [])
        if isinstance(followups, list):
            already_sent = sum(
                1
                for turn in turns
                if str(turn.get("customer_decision_reason", "")).startswith(
                    "scripted_follow_up:"
                )
            )
            if already_sent < len(followups):
                item = followups[already_sent]
                if isinstance(item, str):
                    message = item
                    field = f"follow_up_{already_sent + 1}"
                elif isinstance(item, dict):
                    message = str(item.get("message") or "")
                    field = str(
                        item.get("field")
                        or f"follow_up_{already_sent + 1}"
                    )
                else:
                    message = ""
                    field = f"follow_up_{already_sent + 1}"

                if message:
                    return CustomerDecision(
                        kind="message",
                        reason=f"scripted_follow_up:{field}",
                        message=message,
                        data_fields=(field,),
                        evidence_refs=tuple(last.get("artifact_refs", [])),
                    )

        # Read-only scenarios finish when their declared terminal action is
        # observed after the scripted conversation has been exhausted.
        if expected_write is None:
            terminal_action = self._clean(
                scenario.get("terminal_action") or ready_action
            )
            if terminal_action is not None and action == terminal_action:
                return CustomerDecision(
                    kind="completed",
                    reason=f"structured_action={terminal_action}",
                    evidence_refs=tuple(last.get("artifact_refs", [])),
                )

            terminal_tool = self._clean(scenario.get("terminal_tool"))
            if terminal_tool is not None and self._tool_seen(turns, terminal_tool):
                return CustomerDecision(
                    kind="completed",
                    reason=f"structured_tool={terminal_tool}",
                    evidence_refs=tuple(last.get("artifact_refs", [])),
                )

            return CustomerDecision(
                kind="warn",
                reason="insufficient_structured_evidence",
                evidence_refs=tuple(last.get("artifact_refs", [])),
            )

        # Write scenarios stop at the write boundary in dry-run.
        if ready_action is not None and action == ready_action:
            if scenario.get("write_occurs_on_ready_turn"):
                return CustomerDecision(
                    kind="warn",
                    reason="expected_write_missing_on_ready_turn",
                    evidence_refs=tuple(last.get("artifact_refs", [])),
                )

            if scenario.get("mode") == "live":
                if not allow_writes:
                    return CustomerDecision(
                        kind="live_write_not_enabled",
                        reason="live_write_not_enabled",
                        evidence_refs=tuple(last.get("artifact_refs", [])),
                    )

                confirmation_message = scenario.get("confirmation_message")
                if not confirmation_message:
                    return CustomerDecision(
                        kind="warn",
                        reason="live_confirmation_message_missing",
                        evidence_refs=tuple(last.get("artifact_refs", [])),
                    )

                return CustomerDecision(
                    kind="write_confirmation",
                    reason="confirm_live_write",
                    message=str(confirmation_message),
                    data_fields=("explicit_write_confirmation",),
                    evidence_refs=tuple(last.get("artifact_refs", [])),
                )

            return CustomerDecision(
                kind=str(scenario.get("terminal_state", "ready_to_write")),
                reason=f"structured_action={ready_action}",
                evidence_refs=tuple(last.get("artifact_refs", [])),
            )

        return CustomerDecision(
            kind="warn",
            reason="insufficient_structured_evidence",
            evidence_refs=tuple(last.get("artifact_refs", [])),
        )

    def _tool_seen(
        self,
        turns: list[dict[str, Any]],
        tool_name: str,
    ) -> bool:
        return any(
            call.get("tool_name") == tool_name
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
        )

    def _live_decision(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        allow_writes: bool,
    ) -> CustomerDecision | None:
        expected_write = self._clean(scenario.get("expected_write_tool"))
        if expected_write is None:
            return None
        write_calls = [
            call
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
            if call.get("tool_name") == expected_write
        ]
        if not write_calls:
            return None

        latest_write = write_calls[-1]
        write_step = latest_write.get("step")
        if not self._write_succeeded(latest_write, expected_write):
            return CustomerDecision(
                kind="completed",
                reason="live_write_failed",
                evidence_refs=tuple(latest_write.get("evidence_refs", [])),
            )

        verify_tool = self._clean(scenario.get("verify_with_tool"))
        verification_seen = any(
            call.get("tool_name") == verify_tool and self._step_after(call.get("step"), write_step)
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
        )
        if verification_seen:
            return CustomerDecision(
                kind="completed",
                reason="live_flow_complete",
                evidence_refs=tuple(turns[-1].get("artifact_refs", [])),
            )
        if not allow_writes:
            return CustomerDecision(kind="completed", reason="live_write_gate_lost")
        return CustomerDecision(
            kind="verification_message",
            reason="verify_live_write",
            message=str(scenario["verification_message"]),
            data_fields=("crm_post_write_verification",),
            evidence_refs=tuple(latest_write.get("evidence_refs", [])),
        )

    def _write_succeeded(self, call: dict[str, Any], tool_name: str) -> bool:
        if self._clean(call.get("status")) not in {"completed", "success", "succeeded"}:
            return False
        if self._clean(call.get("error_code")) is not None:
            return False
        output = call.get("decoded_output")
        if not isinstance(output, dict) or output.get("ok") is not True:
            return False
        if tool_name == "appointment_confirm":
            return output.get("confirmed") is True or output.get("appointment_confirmed") is True
        return False

    def _step_after(self, candidate: Any, reference: Any) -> bool:
        return isinstance(candidate, int) and isinstance(reference, int) and candidate > reference

    def _next_customer_datum(
        self, scenario: dict[str, Any], turns: list[dict[str, Any]], action: str | None
    ) -> tuple[str, str] | None:
        if action not in {"ask_clarification", "ask_question"}:
            return None
        already_sent = {
            str(field)
            for turn in turns
            for field in turn.get("customer_data_fields", [])
        }
        entities = turns[-1].get("intent_plan", {}).get("entities", {})
        if not isinstance(entities, dict):
            entities = {}
        order = (
            "service_name",
            "preferred_day",
            "time_of_day",
            "owner_name",
            "contact_name",
            "contact_phone",
            "appointment_reference",
        )
        customer_data = scenario.get("customer_data", {})
        for field in order:
            value = customer_data.get(field)
            if field in already_sent or not isinstance(value, str) or not value.strip():
                continue
            entity_field = {
                "preferred_day": "date",
                "time_of_day": "time_of_day",
            }.get(field, field)
            if self._clean(entities.get(entity_field)) is not None:
                continue
            return field, value.strip()
        return None

    def _next_declarative_message(
        self, scenario: dict[str, Any], turns: list[dict[str, Any]], action: str | None
    ) -> tuple[str, str] | None:
        already_sent = {
            str(field)
            for turn in turns
            for field in turn.get("customer_data_fields", [])
        }
        executed_tools = {
            str(call.get("tool_name"))
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
        }
        selected_count = len(self._latest_selected_service_ids(turns))
        for item in scenario.get("customer_messages", []):
            if not isinstance(item, dict):
                continue
            field = self._clean(item.get("field"))
            message = self._clean(item.get("message"))
            if field is None or message is None or field in already_sent:
                continue
            when_actions = item.get("when_actions")
            if isinstance(when_actions, list) and action not in when_actions:
                continue
            after_tools = item.get("after_tools")
            if isinstance(after_tools, list) and not set(map(str, after_tools)).issubset(executed_tools):
                continue
            minimum = item.get("minimum_selected_service_count")
            if isinstance(minimum, int) and selected_count < minimum:
                continue
            return field, message
        return None

    def _latest_selected_service_ids(self, turns: list[dict[str, Any]]) -> list[str]:
        for turn in reversed(turns):
            services = turn.get("structured_data", {}).get("services", {})
            if not isinstance(services, dict):
                continue
            selected_many = services.get("selected_services")
            if isinstance(selected_many, list) and selected_many:
                return [
                    service_id
                    for item in selected_many
                    if isinstance(item, dict)
                    for service_id in [self._clean(item.get("id") or item.get("service_id"))]
                    if service_id is not None
                ]
            selected_one = services.get("selected_service")
            if isinstance(selected_one, dict):
                service_id = self._clean(selected_one.get("id") or selected_one.get("service_id"))
                if service_id is not None:
                    return [service_id]
        return []

    def _reliable_slots(self, turns: list[dict[str, Any]]) -> list[tuple[dict[str, Any], str]]:
        slots: list[tuple[dict[str, Any], str]] = []
        for turn in turns:
            step = turn.get("step")
            appointment = turn.get("structured_data", {}).get("appointment", {})
            if isinstance(appointment, dict):
                for slot in appointment.get("offered_slots", []) or []:
                    if isinstance(slot, dict):
                        slots.append((slot, f"turn:{step}:structured_data.appointment.offered_slots"))
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") != "appointment_availability":
                    continue
                for slot in self._walk_records(call.get("decoded_output"), record_kind="slot"):
                    slots.append((slot, f"turn:{step}:mcp:appointment_availability"))
        return self._deduplicate_records(slots, "slot")

    def _reliable_appointments(self, turns: list[dict[str, Any]]) -> list[tuple[dict[str, Any], str]]:
        appointments: list[tuple[dict[str, Any], str]] = []
        for turn in turns:
            step = turn.get("step")
            appointment_data = turn.get("structured_data", {}).get("appointment", {})
            if isinstance(appointment_data, dict):
                for key in ("existing_appointment", "existing_appointments"):
                    value = appointment_data.get(key)
                    candidates = value if isinstance(value, list) else [value]
                    for candidate in candidates:
                        if isinstance(candidate, dict) and self._appointment_id(candidate) is not None:
                            appointments.append((candidate, f"turn:{step}:structured_data.appointment.{key}"))
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") not in READ_APPOINTMENT_TOOLS:
                    continue
                for candidate in self._walk_records(call.get("decoded_output"), record_kind="appointment"):
                    appointments.append((candidate, f"turn:{step}:mcp:{call.get('tool_name')}"))
        return self._deduplicate_records(appointments, "appointment")

    def _latest_structured_value(self, turns: list[dict[str, Any]], key: str) -> dict[str, Any] | None:
        for turn in reversed(turns):
            appointment = turn.get("structured_data", {}).get("appointment", {})
            value = appointment.get(key) if isinstance(appointment, dict) else None
            if isinstance(value, dict) and value:
                return value
        return None

    def _select_slot(
        self, slots: list[tuple[dict[str, Any], str]], strategy: str
    ) -> tuple[dict[str, Any], str]:
        if strategy == "last":
            return slots[-1]
        return slots[0]

    def _slot_selection_message(self, slot: dict[str, Any]) -> str | None:
        slot_id = self._clean(slot.get("id") or slot.get("slot_id") or slot.get("slotId"))
        start = self._clean(slot.get("start") or slot.get("start_at") or slot.get("startAt"))
        end = self._clean(slot.get("end") or slot.get("end_at") or slot.get("endAt"))
        if start is not None and end is not None:
            return f"Elijo el horario con inicio exacto {start} y fin exacto {end}."
        if start is not None:
            return f"Elijo el horario con inicio exacto {start}."
        if slot_id is not None:
            return f"Elijo el horario con identificador exacto {slot_id}."
        return None

    def _appointment_selection_message(self, appointment: dict[str, Any]) -> str | None:
        start = self._clean(appointment.get("start") or appointment.get("start_at") or appointment.get("startAt"))
        appointment_id = self._appointment_id(appointment)
        if start is not None:
            return f"Me refiero a la cita que empieza exactamente el {start}."
        if appointment_id is not None:
            return f"Me refiero a la cita con identificador exacto {appointment_id}."
        return None

    def _datum_message(self, field: str, value: str) -> str:
        labels = {
            "service_name": "El servicio que quiero es",
            "preferred_day": "Mi preferencia de día es",
            "time_of_day": "Mi preferencia horaria es",
            "owner_name": "Quiero que me atienda",
            "contact_name": "Mi nombre es",
            "contact_phone": "Mi teléfono es",
            "appointment_reference": "La cita a la que me refiero es",
        }
        return f"{labels[field]} {value}."

    def _walk_records(self, value: Any, record_kind: str) -> list[dict[str, Any]]:
        records: list[dict[str, Any]] = []
        if isinstance(value, list):
            for item in value:
                records.extend(self._walk_records(item, record_kind))
        elif isinstance(value, dict):
            is_record = self._slot_key(value) is not None if record_kind == "slot" else self._appointment_id(value) is not None
            if is_record:
                records.append(value)
            for child in value.values():
                if isinstance(child, (dict, list)):
                    records.extend(self._walk_records(child, record_kind))
        return records

    def _deduplicate_records(
        self, records: list[tuple[dict[str, Any], str]], record_kind: str
    ) -> list[tuple[dict[str, Any], str]]:
        seen: set[tuple[str, ...]] = set()
        result: list[tuple[dict[str, Any], str]] = []
        for record, source in records:
            if record_kind == "slot":
                key = self._slot_key(record)
            else:
                identifier = self._appointment_id(record)
                key = (identifier,) if identifier is not None else None
            if key is None or key in seen:
                continue
            seen.add(key)
            result.append((record, source))
        return result

    def _slot_key(self, slot: dict[str, Any]) -> tuple[str, ...] | None:
        slot_id = self._clean(slot.get("id") or slot.get("slot_id") or slot.get("slotId"))
        start = self._clean(slot.get("start") or slot.get("start_at") or slot.get("startAt"))
        end = self._clean(slot.get("end") or slot.get("end_at") or slot.get("endAt"))
        if start is None:
            return None
        if slot_id is not None:
            return ("id", slot_id, "start", start)
        return ("time", start, end or "")

    def _appointment_id(self, appointment: dict[str, Any]) -> str | None:
        identifier = self._clean(
            appointment.get("id")
            or appointment.get("appointment_id")
            or appointment.get("appointmentId")
        )

        start = self._clean(
            appointment.get("start")
            or appointment.get("start_at")
            or appointment.get("startAt")
        )
        return identifier if identifier is not None and start is not None else None

    def _explicit_missing_appointment(self, turns: list[dict[str, Any]]) -> bool:
        for turn in turns:
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") not in READ_APPOINTMENT_TOOLS:
                    continue
                if self._read_output_explicitly_empty(call.get("decoded_output")):
                    return True
        return False

    def _read_output_explicitly_empty(self, output: Any) -> bool:
        if not isinstance(output, dict):
            return False
        if output.get("count") == 0:
            return True
        for key in ("appointments", "existing_appointments", "items", "slots"):
            if key in output and output.get(key) == []:
                return True
        contact_context = output.get("contact_context")
        return isinstance(contact_context, dict) and self._read_output_explicitly_empty(contact_context)

    def _explicit_no_availability(self, turns: list[dict[str, Any]]) -> bool:
        calls = [
            call
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
            if call.get("tool_name") == "appointment_availability"
        ]
        if not calls:
            return False
        latest = calls[-1].get("decoded_output")
        if self._walk_records(latest, record_kind="slot"):
            return False
        return self._read_output_explicitly_empty(latest)

    def _clean(self, value: Any) -> str | None:
        return value.strip() if isinstance(value, str) and value.strip() else None
