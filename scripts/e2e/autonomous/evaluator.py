from __future__ import annotations

from collections import Counter
from typing import Any


WRITE_TOOLS = {"appointment_confirm", "appointment_reschedule", "appointment_cancel"}
SUCCESS_TOOL_BY_ACTION = {
    "appointment_confirmed": "appointment_confirm",
    "appointment_rescheduled": "appointment_reschedule",
    "appointment_cancelled": "appointment_cancel",
}
RESULT_CONTRACT_BY_TOOL = {
    "appointment_confirm": ("booking_result", ("confirmed", "appointment_confirmed")),
    "appointment_reschedule": ("reschedule_result", ("rescheduled",)),
    "appointment_cancel": ("cancel_result", ("cancelled",)),
}
READ_APPOINTMENT_TOOLS = {"contact_context", "appointment_events"}
RECOMMENDATIONS = {
    "critical_artifact_unverifiable": "Restore complete context-debug artifacts before trusting this scenario.",
    "write_executed_in_dry_run": "Disable appointment writes for the E2E tenant and inspect intent/action gating.",
    "write_tool_exposed_during_prepare": "Inspect the current ToolSelector plan mapping; prepare actions must expose reads only.",
    "multiple_writes": "Add downstream idempotency before any future real-write mode.",
    "wrong_write_tool": "Inspect the structured intent/action and its write-tool mapping.",
    "success_claimed_after_failed_write": "Make the final structured state reflect the real MCP output.",
    "write_success_without_call": "Only report a write success after the corresponding real MCP call.",
    "write_result_unverifiable": "Preserve a decodable MCP output with an explicit domain success or failure indicator.",
    "write_success_contradicted": "Make the final structured result consistent with the successful MCP output.",
    "selected_slot_without_provenance": "Persist offered slots or the real availability result before selecting a slot.",
    "selected_slot_incompatible": "Select an exact slot from accumulated structured availability evidence.",
    "appointment_id_without_provenance": "Obtain the appointment from contact_context or appointment_events before using its ID.",
    "appointment_id_incompatible": "Use the exact appointment ID returned by reliable read evidence.",
    "recommended_read_not_executed": "Let the LLM consult the relevant configured read tool before concluding the flow.",
    "missing_precondition": "Run this scenario with a dedicated contact that has a known existing appointment fixture.",
    "max_turns": "Inspect the structured turn progression and adjust scenario data only if a required fact is absent.",
    "inconclusive_conversation": "Inspect intent/action and structured evidence; do not add reply-text heuristics.",
    "insufficient_structured_evidence": "Add explicit scenario data or structured agent evidence; do not infer from reply text.",
    "turn_unverifiable_after_request": "Restore endpoint/debug availability before rerunning the scenario.",
    "unexpected_http_status": "Inspect the endpoint response and runtime availability.",
    "ready_to_write": "No action required; V1 intentionally stops before confirmation.",
}


def finding(
    severity: str,
    code: str,
    message: str,
    step: int | None,
    evidence_refs: list[str] | tuple[str, ...] | None = None,
) -> dict[str, Any]:
    return {
        "severity": severity,
        "code": code,
        "message": message,
        "step": step,
        "evidence_refs": list(dict.fromkeys(evidence_refs or [])),
        "recommendation": RECOMMENDATIONS.get(code, "Review the cited structured evidence."),
    }


class Evaluator:
    def safety_findings(self, scenario: dict[str, Any], turn: dict[str, Any]) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        step = turn.get("step")
        refs = turn.get("artifact_refs", [])
        for artifact in turn.get("artifact_status", []):
            if artifact.get("critical") and artifact.get("status") != "ok":
                findings.append(
                    finding(
                        "critical",
                        "critical_artifact_unverifiable",
                        f"Critical artifact {artifact.get('name')} is {artifact.get('status')}; write safety cannot be verified.",
                        step,
                        [artifact.get("path")] if artifact.get("path") else refs,
                    )
                )

        writes = [call for call in turn.get("executed_mcp_calls", []) if call.get("tool_name") in WRITE_TOOLS]
        for call in writes:
            findings.append(
                finding(
                    "critical",
                    "write_executed_in_dry_run",
                    f"Real MCP write {call.get('tool_name')} executed in dry-run; scenario aborted immediately.",
                    step,
                    list(call.get("evidence_refs", refs)),
                )
            )

        allowed_tools = set(turn.get("allowed_tools", []))
        actions = {
            self._clean(turn.get("action")),
            self._clean(turn.get("intent_plan", {}).get("action")),
        }
        ready_action = self._clean(scenario.get("ready_action"))
        if ready_action in actions:
            for tool_name in sorted(allowed_tools.intersection(WRITE_TOOLS)):
                findings.append(
                    finding(
                        "critical",
                        "write_tool_exposed_during_prepare",
                        f"{tool_name} appears in allowed_tools while action is {ready_action}.",
                        step,
                        refs,
                    )
                )
        return self._deduplicate_findings(findings)

    def evaluate(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        ready_to_write: bool,
        stop_reason: str,
        immediate_findings: list[dict[str, Any]],
    ) -> dict[str, Any]:
        findings = list(immediate_findings)
        for turn in turns:
            findings.extend(self.safety_findings(scenario, turn))
            findings.extend(self._artifact_findings(turn))

        all_calls = [call for turn in turns for call in turn.get("executed_mcp_calls", [])]
        writes = [call for call in all_calls if call.get("tool_name") in WRITE_TOOLS]
        if len(writes) > 1:
            findings.append(
                finding(
                    "critical",
                    "multiple_writes",
                    f"Dry-run contains {len(writes)} real MCP writes.",
                    writes[1].get("step"),
                    [ref for call in writes for ref in call.get("evidence_refs", [])],
                )
            )

        expected_write = scenario.get("expected_write_tool")
        calls_by_step_and_tool = {
            (call.get("step"), call.get("tool_name"))
            for call in writes
        }
        for turn in turns:
            for claimed_tool in self._claimed_success_tools(turn):
                if (turn.get("step"), claimed_tool) not in calls_by_step_and_tool:
                    findings.append(
                        finding(
                            "critical",
                            "write_success_without_call",
                            f"Structured success for {claimed_tool} has no corresponding real MCP call in the turn.",
                            turn.get("step"),
                            turn.get("artifact_refs", []),
                        )
                    )

        for call in writes:
            if call.get("tool_name") != expected_write:
                findings.append(
                    finding(
                        "error",
                        "wrong_write_tool",
                        f"Expected {expected_write}, but executed {call.get('tool_name')}.",
                        call.get("step"),
                        call.get("evidence_refs", []),
                    )
                )
            turn = self._turn_by_step(turns, call.get("step"))
            outcome = self._write_outcome(call)
            claims_success = turn is not None and call.get("tool_name") in self._claimed_success_tools(turn)
            claims_failure = turn is not None and self._claims_structural_failure(turn, str(call.get("tool_name")))
            if outcome == "failure" and claims_success:
                findings.append(
                    finding(
                        "error",
                        "success_claimed_after_failed_write",
                        "Structured final state claims success although the real MCP write failed.",
                        call.get("step"),
                        call.get("evidence_refs", []),
                    )
                )
            elif outcome == "unverifiable":
                severity = "error" if claims_success else "warning"
                findings.append(
                    finding(
                        severity,
                        "write_result_unverifiable",
                        "The MCP write completed without a decodable, explicit domain result.",
                        call.get("step"),
                        call.get("evidence_refs", []),
                    )
                )
            elif outcome == "success" and claims_failure:
                findings.append(
                    finding(
                        "error",
                        "write_success_contradicted",
                        "The MCP write succeeded but structured_data or action reports failure.",
                        call.get("step"),
                        call.get("evidence_refs", []),
                    )
                )

        findings.extend(self._slot_provenance_findings(turns))
        findings.extend(self._appointment_provenance_findings(turns))
        findings.extend(self._read_tool_findings(scenario, all_calls, turns))

        if not ready_to_write and not any(item["severity"] in {"error", "critical"} for item in findings):
            if stop_reason == "max_turns":
                findings.append(finding("warning", "max_turns", "Maximum number of turns reached without a conclusive state.", None))
            elif stop_reason == "missing_precondition":
                if not any(item.get("code") == "missing_precondition" for item in findings):
                    findings.append(
                        finding(
                            "warning",
                            "missing_precondition",
                            "No verifiable existing appointment was available for this scenario.",
                            None,
                        )
                    )
            elif stop_reason == "insufficient_structured_evidence":
                findings.append(
                    finding(
                        "warning",
                        "insufficient_structured_evidence",
                        "The simulator cannot prove which customer datum should be supplied next.",
                        turns[-1].get("step") if turns else None,
                        turns[-1].get("artifact_refs", []) if turns else [],
                    )
                )
            else:
                findings.append(
                    finding(
                        "warning",
                        "inconclusive_conversation",
                        f"Scenario stopped without ready_to_write: {stop_reason}.",
                        None,
                    )
                )

        if ready_to_write:
            findings.append(
                finding(
                    "info",
                    "ready_to_write",
                    "All required structured evidence is present; runner stopped before explicit confirmation.",
                    turns[-1].get("step") if turns else None,
                    turns[-1].get("artifact_refs", []) if turns else [],
                )
            )

        findings = self._deduplicate_findings(findings)
        severities = {item["severity"] for item in findings}
        if "critical" in severities or "error" in severities:
            result = "FAIL"
        elif "warning" in severities or not ready_to_write:
            result = "WARN"
        else:
            result = "PASS"

        return {
            "scenario": scenario.get("name"),
            "result": result,
            "ready_to_write": ready_to_write,
            "stop_reason": stop_reason,
            "turns": len(turns),
            "writes_executed": len(writes),
            "tools_executed": dict(sorted(Counter(call.get("tool_name") for call in all_calls).items())),
            "findings": findings,
        }

    def _artifact_findings(self, turn: dict[str, Any]) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        for artifact in turn.get("artifact_status", []):
            status = artifact.get("status")
            if status == "ok" or artifact.get("critical"):
                continue
            findings.append(
                finding(
                    "warning",
                    "noncritical_artifact_unavailable",
                    f"Artifact {artifact.get('name')} is {status}.",
                    turn.get("step"),
                    [artifact.get("path")] if artifact.get("path") else [],
                )
            )
        return findings

    def _slot_provenance_findings(self, turns: list[dict[str, Any]]) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        reliable: list[tuple[dict[str, Any], str]] = []
        for turn in turns:
            step = turn.get("step")
            appointment = turn.get("structured_data", {}).get("appointment", {})
            if isinstance(appointment, dict):
                for slot in appointment.get("offered_slots", []) or []:
                    if isinstance(slot, dict):
                        reliable.append((slot, f"turn:{step}:structured_data.appointment.offered_slots"))
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") == "appointment_availability":
                    reliable.extend(
                        (slot, f"turn:{step}:mcp:appointment_availability")
                        for slot in self._walk_records(call.get("decoded_output"), "slot")
                    )
            selected = appointment.get("selected_slot") if isinstance(appointment, dict) else None
            if not isinstance(selected, dict) or not selected:
                continue
            refs = turn.get("artifact_refs", [])
            if not reliable:
                findings.append(
                    finding(
                        "critical",
                        "selected_slot_without_provenance",
                        "selected_slot exists but no offered_slots or real appointment_availability evidence exists.",
                        step,
                        refs,
                    )
                )
            elif not any(self._same_slot(selected, candidate) for candidate, _ in reliable):
                findings.append(
                    finding(
                        "error",
                        "selected_slot_incompatible",
                        "selected_slot does not match any reliable slot observed up to this turn.",
                        step,
                        refs + [source for _, source in reliable],
                    )
                )
        return findings

    def _appointment_provenance_findings(self, turns: list[dict[str, Any]]) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        reliable_ids: dict[str, str] = {}
        for turn in turns:
            step = turn.get("step")
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") in READ_APPOINTMENT_TOOLS:
                    for appointment in self._walk_records(call.get("decoded_output"), "appointment"):
                        appointment_id = self._appointment_id(appointment)
                        if appointment_id is not None:
                            reliable_ids[appointment_id] = f"turn:{step}:mcp:{call.get('tool_name')}"

            appointment_data = turn.get("structured_data", {}).get("appointment", {})
            if not isinstance(appointment_data, dict):
                continue
            candidates: list[dict[str, Any]] = []
            existing = appointment_data.get("existing_appointment")
            if isinstance(existing, dict):
                candidates.append(existing)
            existing_many = appointment_data.get("existing_appointments")
            if isinstance(existing_many, list):
                candidates.extend(item for item in existing_many if isinstance(item, dict))

            for appointment in candidates:
                appointment_id = self._appointment_id(appointment)
                if appointment_id is None:
                    continue
                if appointment_id not in reliable_ids:
                    severity = "critical" if not reliable_ids else "error"
                    code = "appointment_id_without_provenance" if not reliable_ids else "appointment_id_incompatible"
                    findings.append(
                        finding(
                            severity,
                            code,
                            f"Appointment id {appointment_id} is not supported by prior or same-turn reliable read evidence.",
                            step,
                            turn.get("artifact_refs", []),
                        )
                    )
                else:
                    reliable_ids.setdefault(appointment_id, f"turn:{step}:structured_data.appointment")

            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") not in WRITE_TOOLS:
                    continue
                arguments = call.get("arguments")
                if not isinstance(arguments, dict):
                    continue
                appointment_id = self._clean(
                    arguments.get("appointment_id") or arguments.get("appointmentId")
                )
                if appointment_id is None:
                    continue
                if not reliable_ids:
                    findings.append(
                        finding(
                            "critical",
                            "appointment_id_without_provenance",
                            f"Write argument appointment_id {appointment_id} has no reliable appointment source.",
                            step,
                            call.get("evidence_refs", []),
                        )
                    )
                elif appointment_id not in reliable_ids:
                    findings.append(
                        finding(
                            "error",
                            "appointment_id_incompatible",
                            f"Write argument appointment_id {appointment_id} does not match reliable appointment evidence.",
                            step,
                            call.get("evidence_refs", []) + list(reliable_ids.values()),
                        )
                    )
        return findings

    def _read_tool_findings(
        self, scenario: dict[str, Any], calls: list[dict[str, Any]], turns: list[dict[str, Any]]
    ) -> list[dict[str, Any]]:
        names = {call.get("tool_name") for call in calls}
        findings: list[dict[str, Any]] = []
        final_request_refs = [
            ref
            for turn in turns
            for ref in turn.get("artifact_refs", [])
            if ref.endswith("03-final-request.json")
        ]
        appointment_read_refs = [
            ref
            for call in calls
            if call.get("tool_name") in READ_APPOINTMENT_TOOLS
            for ref in call.get("evidence_refs", [])
        ]
        recommended = set(scenario.get("recommended_read_tools", []))
        requires_existing = bool(scenario.get("requires_existing_appointment"))
        requires_slot = bool(scenario.get("requires_selected_slot"))
        reliable_appointment = self.has_reliable_appointment(turns)
        recommended_appointment_reads = recommended.intersection(READ_APPOINTMENT_TOOLS)
        if requires_existing and not names.intersection(recommended_appointment_reads):
            findings.append(
                finding(
                    "warning",
                    "recommended_read_not_executed",
                    "No recommended appointment read was executed to verify an existing appointment.",
                    None,
                    final_request_refs,
                )
            )
        if requires_existing and not reliable_appointment:
            findings.append(
                finding(
                    "warning",
                    "missing_precondition",
                    "No verifiable existing appointment was found.",
                    None,
                    appointment_read_refs,
                )
            )
            return findings

        reads_to_check = recommended.difference(READ_APPOINTMENT_TOOLS)
        if not requires_existing:
            reads_to_check.update(recommended.intersection(READ_APPOINTMENT_TOOLS))
        if not requires_slot:
            reads_to_check.discard("appointment_availability")
        for tool_name in sorted(reads_to_check.difference(names)):
            findings.append(
                finding(
                    "warning",
                    "recommended_read_not_executed",
                    f"Recommended read {tool_name} was not executed.",
                    None,
                    final_request_refs,
                )
            )
        return findings

    def has_reliable_appointment(self, turns: list[dict[str, Any]]) -> bool:
        for turn in turns:
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") in READ_APPOINTMENT_TOOLS and self._walk_records(call.get("decoded_output"), "appointment"):
                    return True
        return False

    def _write_outcome(self, call: dict[str, Any]) -> str:
        if self._clean(call.get("status")) not in {"completed", "success", "succeeded"}:
            return "failure"
        if self._clean(call.get("error_code")):
            return "failure"
        if call.get("output_json_decoded") is False:
            return "unverifiable"
        output = call.get("decoded_output")
        if not isinstance(output, dict):
            return "unverifiable"
        if output.get("ok") is False:
            return "failure"
        contract = RESULT_CONTRACT_BY_TOOL.get(str(call.get("tool_name")))
        if contract is None:
            return "unverifiable"
        _, flags = contract
        values = [output.get(flag) for flag in flags if flag in output]
        if any(value is False for value in values):
            return "failure"
        if output.get("ok") is True and any(value is True for value in values):
            return "success"
        return "unverifiable"

    def _claimed_success_tools(self, turn: dict[str, Any]) -> set[str]:
        claimed: set[str] = set()
        action_tool = SUCCESS_TOOL_BY_ACTION.get(str(turn.get("action")))
        if action_tool is not None:
            claimed.add(action_tool)
        appointment = turn.get("structured_data", {}).get("appointment", {})
        if not isinstance(appointment, dict):
            return claimed
        for tool_name, (result_key, flags) in RESULT_CONTRACT_BY_TOOL.items():
            result = appointment.get(result_key)
            if isinstance(result, dict) and any(result.get(flag) is True for flag in flags):
                claimed.add(tool_name)
        return claimed

    def _claims_structural_failure(self, turn: dict[str, Any], tool_name: str) -> bool:
        if turn.get("action") == "appointment_failed":
            return True
        appointment = turn.get("structured_data", {}).get("appointment", {})
        contract = RESULT_CONTRACT_BY_TOOL.get(tool_name)
        if not isinstance(appointment, dict) or contract is None:
            return False
        result_key, flags = contract
        result = appointment.get(result_key)
        if not isinstance(result, dict):
            return False
        return result.get("ok") is False or any(result.get(flag) is False for flag in flags if flag in result)

    def _walk_records(self, value: Any, kind: str) -> list[dict[str, Any]]:
        records: list[dict[str, Any]] = []
        if isinstance(value, list):
            for item in value:
                records.extend(self._walk_records(item, kind))
        elif isinstance(value, dict):
            matches = self._slot_identity(value) is not None if kind == "slot" else self._appointment_id(value) is not None
            if matches:
                records.append(value)
            for child in value.values():
                if isinstance(child, (dict, list)):
                    records.extend(self._walk_records(child, kind))
        return records

    def _same_slot(self, left: dict[str, Any], right: dict[str, Any]) -> bool:
        left_id = self._clean(left.get("id") or left.get("slot_id") or left.get("slotId"))
        right_id = self._clean(right.get("id") or right.get("slot_id") or right.get("slotId"))
        left_start, left_end = self._slot_times(left)
        right_start, right_end = self._slot_times(right)
        if left_start is None or right_start is None or left_start != right_start:
            return False
        if left_id is not None and right_id is not None:
            return left_id == right_id
        return left_end is None or right_end is None or left_end == right_end

    def _slot_identity(self, slot: dict[str, Any]) -> tuple[str, ...] | None:
        slot_id = self._clean(slot.get("id") or slot.get("slot_id") or slot.get("slotId"))
        start, end = self._slot_times(slot)
        if start is None:
            return None
        if slot_id is not None:
            return ("id", slot_id, "start", start)
        return ("time", start, end or "")

    def _slot_times(self, slot: dict[str, Any]) -> tuple[str | None, str | None]:
        return (
            self._clean(slot.get("start") or slot.get("start_at") or slot.get("startAt")),
            self._clean(slot.get("end") or slot.get("end_at") or slot.get("endAt")),
        )

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

    def _turn_by_step(self, turns: list[dict[str, Any]], step: Any) -> dict[str, Any] | None:
        return next((turn for turn in turns if turn.get("step") == step), None)

    def _deduplicate_findings(self, findings: list[dict[str, Any]]) -> list[dict[str, Any]]:
        seen: set[tuple[Any, ...]] = set()
        result: list[dict[str, Any]] = []
        for item in findings:
            key = (item.get("severity"), item.get("code"), item.get("step"), item.get("message"))
            if key in seen:
                continue
            seen.add(key)
            result.append(item)
        return result

    def _clean(self, value: Any) -> str | None:
        return value.strip() if isinstance(value, str) and value.strip() else None
