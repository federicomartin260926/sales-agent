from __future__ import annotations

from datetime import datetime, timezone

from collections import Counter
from typing import Any


APPOINTMENT_WRITE_TOOLS = {
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "appointment_booking_invitation",
}

CRM_WRITE_TOOLS = {
    "crm_contact_submit",
}

HANDOFF_WRITE_TOOLS = {
    "handoff_request",
}

WRITE_TOOLS = (
    APPOINTMENT_WRITE_TOOLS
    | CRM_WRITE_TOOLS
    | HANDOFF_WRITE_TOOLS
)

AGENDA_TOOLS = APPOINTMENT_WRITE_TOOLS | {
    "appointment_availability",
    "appointment_events",
}
SUCCESS_TOOL_BY_ACTION = {
    "appointment_confirmed": "appointment_confirm",
    "appointment_rescheduled": "appointment_reschedule",
    "appointment_cancelled": "appointment_cancel",
}
RESULT_CONTRACT_BY_TOOL = {
    "appointment_booking_invitation": ("booking_invitation", ("created",)),
    "appointment_confirm": ("booking_result", ("confirmed", "appointment_confirmed")),
    "appointment_reschedule": ("reschedule_result", ("rescheduled",)),
    "appointment_cancel": ("cancel_result", ("cancelled",)),
    "crm_contact_submit": ("crm_contact_submit_result", ("submitted",)),
    "handoff_request": ("handoff_result", ("handoff_requested",)),
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
    "service_id_without_provenance": "Resolve every service through reliable structured MCP evidence before agenda use.",
    "service_ids_incompatible": "Use the complete expected service set without additions, losses or duplicates.",
    "service_selection_lost": "Preserve the complete structured service selection across turns unless the customer replaces one explicitly.",
    "duplicate_service_ids": "Deduplicate the structured service selection and agenda arguments.",
    "multiservice_derived_timing": "Leave combined duration and buffers to CRM/downstream for multi-service visits.",
    "booking_url_without_provenance": "Only persist the literal booking_url returned by appointment_booking_invitation.",
    "booking_url_mismatch": "Copy booking_url literally from the downstream MCP result without normalization.",
    "booking_invitation_contract_failed": "Require ok=true, created=true and a non-empty downstream booking_url before claiming success.",
    "timezone_mismatch": "Use the latest valid contact_context timezone literally in subsequent agenda calls.",
    "appointment_events_not_used": "Use appointment_events for an explicit existing-appointment verification.",
    "availability_used_for_verification": "Do not use appointment_availability to verify an existing appointment.",
    "expected_no_availability": "No slots were returned; keep the flow recoverable without inventing a slot or executing a write.",
    "dry_run_write_precondition": "Run this scenario only against a controlled transport that can provide recorded write-tool evidence without a real downstream write.",
    "live_write_not_enabled": "Set SA_E2E_ALLOW_WRITES=1 only after reviewing the live scenario and its isolated test data.",
    "live_write_before_confirmation": "Allow the expected write only on the explicit simulator confirmation turn.",
    "live_confirm_action_mismatch": "Require the planner confirm_action declared by the live scenario on the write turn.",
    "live_write_failed": "Inspect the real appointment_confirm trace and downstream result; do not retry automatically.",
    "live_expected_write_missing": "Inspect confirm intent/action gating and the real MCP traces without resending confirmation automatically.",
    "live_appointment_id_missing": "Require the canonical appointment.id from the successful downstream confirmation output.",
    "crm_post_write_read_missing": "Execute appointment_events in a later turn before accepting the live booking result.",
    "crm_post_write_verification_failed": "Reconcile the potentially orphaned CRM write manually; do not retry appointment_confirm.",
    "live_cleanup_not_implemented": "Keep cleanup SKIPPED until a separately gated, verified cancellation flow is implemented.",
}


def finding(
    severity: str,
    code: str,
    message: str,
    step: int | None,
    evidence_refs: list[str] | tuple[str, ...] | None = None,
    evidence: dict[str, Any] | None = None,
) -> dict[str, Any]:
    return {
        "severity": severity,
        "code": code,
        "message": message,
        "step": step,
        "evidence_refs": list(dict.fromkeys(evidence_refs or [])),
        "recommendation": RECOMMENDATIONS.get(code, "Review the cited structured evidence."),
        "evidence": evidence or {},
    }


class Evaluator:
    def safety_findings(
        self,
        scenario: dict[str, Any],
        turn: dict[str, Any],
        write_enabled: bool = False,
    ) -> list[dict[str, Any]]:
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

        writes = [
            call
            for call in turn.get("executed_mcp_calls", [])
            if call.get("tool_name") in WRITE_TOOLS
        ]
        scenario_type = str(scenario.get("scenario_type", "appointment"))
        ready_action = self._clean(scenario.get("ready_action"))
        turn_actions = {
            self._clean(turn.get("action")),
            self._clean(turn.get("intent_plan", {}).get("action")),
        }

        for call in writes:
            live_expected_write = False

            if (
                scenario.get("mode") == "live"
                and write_enabled
                and call.get("tool_name") == scenario.get("expected_write_tool")
            ):
                if scenario_type == "appointment":
                    if scenario.get("write_occurs_on_ready_turn"):
                        live_expected_write = (
                            ready_action is not None
                            and ready_action in turn_actions
                        )
                    else:
                        live_expected_write = (
                            turn.get("customer_decision") == "write_confirmation"
                        )
                elif (
                    scenario_type in {"contact_submit", "handoff"}
                    and scenario.get("write_occurs_on_ready_turn")
                    and ready_action is not None
                    and ready_action in turn_actions
                ):
                    live_expected_write = True

            if not live_expected_write:
                code = (
                    "live_write_before_confirmation"
                    if scenario.get("mode") == "live" and write_enabled
                    else "write_executed_in_dry_run"
                )
                findings.append(
                    finding(
                        "critical",
                        code,
                        (
                            f"Real MCP write {call.get('tool_name')} executed "
                            "outside the authorized live write turn."
                        ),
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
        scenario_type = str(scenario.get("scenario_type", "appointment"))
        if (
            scenario_type == "appointment"
            and not scenario.get("write_occurs_on_ready_turn")
            and ready_action is not None
            and ready_action in actions
        ):
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
        write_enabled: bool = False,
    ) -> dict[str, Any]:
        findings = list(immediate_findings)
        scenario_type = str(scenario.get("scenario_type", "appointment"))

        for turn in turns:
            findings.extend(self.safety_findings(scenario, turn, write_enabled=write_enabled))
            findings.extend(self._artifact_findings(turn))

        all_calls = [call for turn in turns for call in turn.get("executed_mcp_calls", [])]
        writes = [call for call in all_calls if call.get("tool_name") in WRITE_TOOLS]
        if len(writes) > 1:
            findings.append(
                finding(
                    "critical",
                    "multiple_writes",
                    f"Scenario contains {len(writes)} real MCP writes.",
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

        findings.extend(self._service_contract_findings(scenario, turns))

        if scenario_type == "appointment":
            findings.extend(self._slot_provenance_findings(turns))
            findings.extend(self._appointment_provenance_findings(turns))
            findings.extend(self._booking_url_findings(scenario, turns))
            findings.extend(self._timezone_findings(turns))
            findings.extend(
                self._appointment_verification_findings(
                    scenario,
                    all_calls,
                    turns,
                )
            )

        expected_service_found = scenario.get("expected_service_search_found")
        if expected_service_found is not None:
            service_calls = [
                call
                for call in all_calls
                if call.get("tool_name") == "services_search"
            ]
            if service_calls:
                call = service_calls[-1]
                output = call.get("decoded_output")
                actual_found = (
                    output.get("found")
                    if isinstance(output, dict)
                    else None
                )
                if actual_found is not bool(expected_service_found):
                    findings.append(
                        finding(
                            "error",
                            "service_search_result_mismatch",
                            (
                                "Expected services_search found="
                                f"{bool(expected_service_found)}, "
                                f"got {actual_found!r}."
                            ),
                            call.get("step"),
                            call.get("evidence_refs", []),
                        )
                    )

        findings.extend(self._read_tool_findings(scenario, all_calls, turns))

        phases: dict[str, str] | None = None
        live_evidence: dict[str, Any] | None = None
        if scenario.get("mode") == "live":
            if scenario_type == "contact_submit":
                live_findings, phases, live_evidence = (
                    self._contact_submit_live_evaluation(
                        scenario,
                        turns,
                        ready_to_write,
                        stop_reason,
                        writes,
                        write_enabled,
                    )
                )
                findings.extend(live_findings)
            elif scenario_type == "handoff":
                live_findings, phases, live_evidence = (
                    self._handoff_live_evaluation(
                        scenario,
                        turns,
                        ready_to_write,
                        stop_reason,
                        writes,
                        write_enabled,
                    )
                )
                findings.extend(live_findings)
            elif scenario_type == "appointment":
                live_findings, phases, live_evidence = self._live_evaluation(
                    scenario,
                    turns,
                    ready_to_write,
                    stop_reason,
                    writes,
                    write_enabled,
                )
                findings.extend(live_findings)

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
            elif stop_reason == "expected_no_availability":
                findings.append(
                    finding(
                        "info",
                        "expected_no_availability",
                        "Joint availability returned no reliable slots and the scenario stopped without a write.",
                        turns[-1].get("step") if turns else None,
                    )
                )
            elif stop_reason == "dry_run_write_precondition":
                findings.append(
                    finding(
                        "warning",
                        "dry_run_write_precondition",
                        "The live invitation turn was not sent because it could execute a real MCP write.",
                        None,
                    )
                )
            elif stop_reason == "live_write_not_enabled":
                pass
            elif stop_reason.startswith(
                ("structured_action=", "structured_tool=")
            ):
                pass
            else:
                findings.append(
                    finding(
                        "warning",
                        "inconclusive_conversation",
                        f"Scenario stopped without ready_to_write: {stop_reason}.",
                        None,
                    )
                )

        if ready_to_write and not (scenario.get("mode") == "live" and write_enabled):
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
        elif "warning" in severities or (
            not ready_to_write
            and stop_reason not in {"expected_no_availability"}
            and not stop_reason.startswith(
                ("structured_action=", "structured_tool=")
            )
        ):
            result = "WARN"
        else:
            result = "PASS"

        evaluation = {
            "scenario": scenario.get("name"),
            "result": result,
            "ready_to_write": ready_to_write,
            "stop_reason": stop_reason,
            "turns": len(turns),
            "writes_executed": len(writes),
            "tools_executed": dict(sorted(Counter(call.get("tool_name") for call in all_calls).items())),
            "findings": findings,
            "multiservice_evidence": self._multiservice_evidence(scenario, turns),
        }
        if phases is not None:
            evaluation["phases"] = phases
            evaluation["live_evidence"] = live_evidence or {}
        return evaluation

    def _live_evaluation(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        ready_to_write: bool,
        stop_reason: str,
        writes: list[dict[str, Any]],
        write_enabled: bool,
    ) -> tuple[list[dict[str, Any]], dict[str, str], dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        phases = {
            "conversation": "SKIPPED",
            "write": "SKIPPED",
            "crm_verification": "SKIPPED",
            "cleanup": "SKIPPED",
        }
        evidence: dict[str, Any] = {
            "write_mcp_call": None,
            "write_output": None,
            "appointment_id": None,
            "selected_slot": self._latest_selected_slot(turns),
            "service_ids": (
                self._selected_service_ids(turns[-1])
                if turns
                else []
            ),
            "timezone": self._latest_agenda_timezone(turns),
            "verification_mcp_call": None,
            "verification_match": False,
            "cleanup_call": None,
            "cleanup_result": "SKIPPED",
        }

        if not write_enabled or stop_reason == "live_write_not_enabled":
            findings.append(
                finding(
                    "warning",
                    "live_write_not_enabled",
                    (
                        "Live appointment scenario reached its write boundary "
                        "but write opt-in is disabled."
                    ),
                    turns[-1].get("step") if turns else None,
                    turns[-1].get("artifact_refs", []) if turns else [],
                )
            )
            return findings, phases, evidence

        expected_tool = self._clean(scenario.get("expected_write_tool"))
        expected_writes = [
            call
            for call in writes
            if call.get("tool_name") == expected_tool
        ]

        if len(expected_writes) != 1 or len(writes) != 1:
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    (
                        "live_expected_write_missing"
                        if not expected_writes
                        else "multiple_writes"
                    ),
                    (
                        f"Live appointment scenario requires exactly one "
                        f"{expected_tool} call; observed "
                        f"{len(expected_writes)} expected and "
                        f"{len(writes)} total writes."
                    ),
                    writes[-1].get("step") if writes else None,
                    [
                        ref
                        for call in writes
                        for ref in call.get("evidence_refs", [])
                    ],
                )
            )
            return findings, phases, evidence

        write_call = expected_writes[0]
        output = write_call.get("decoded_output")
        evidence["write_mcp_call"] = self._minimal_call_evidence(write_call)
        evidence["write_output"] = output

        arguments = write_call.get("arguments")
        if isinstance(arguments, dict):
            evidence["service_ids"] = self._argument_service_ids(arguments)
            evidence["timezone"] = self._clean(arguments.get("timezone"))

        write_turn = self._turn_by_step(turns, write_call.get("step"))
        observed_actions: set[str | None] = set()
        if isinstance(write_turn, dict):
            observed_actions = {
                self._clean(write_turn.get("action")),
                self._clean(
                    write_turn.get("intent_plan", {}).get("action")
                ),
            }

        direct_write = bool(scenario.get("write_occurs_on_ready_turn"))

        if direct_write:
            expected_action = self._clean(scenario.get("ready_action"))
            authorization_valid = (
                expected_action is not None
                and expected_action in observed_actions
            )
        else:
            expected_action = self._clean(scenario.get("confirm_action"))
            planner_action = (
                self._clean(write_turn.get("intent_plan", {}).get("action"))
                if isinstance(write_turn, dict)
                else None
            )
            authorization_valid = planner_action == expected_action

        if not authorization_valid:
            phases["conversation"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "live_confirm_action_mismatch",
                    (
                        "Appointment write executed without the expected "
                        f"structured authorization action "
                        f"{expected_action!r}."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {
                        "observed_actions": sorted(
                            action
                            for action in observed_actions
                            if action is not None
                        )
                    },
                )
            )
        else:
            phases["conversation"] = "PASS"

        if self._write_outcome(write_call) != "success":
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "error",
                    "live_write_failed",
                    (
                        f"{expected_tool} did not return a verifiable "
                        "successful result."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {"write_output": output},
                )
            )
            return findings, phases, evidence

        if expected_tool == "appointment_booking_invitation":
            booking_url = (
                self._clean(output.get("booking_url"))
                if isinstance(output, dict)
                else None
            )
            if booking_url is None:
                phases["write"] = "FAIL"
                findings.append(
                    finding(
                        "error",
                        "booking_invitation_contract_failed",
                        (
                            "Successful appointment_booking_invitation "
                            "did not return a non-empty booking_url."
                        ),
                        write_call.get("step"),
                        write_call.get("evidence_refs", []),
                        {"write_output": output},
                    )
                )
                return findings, phases, evidence

        phases["write"] = "PASS"

        # Booking invitation has no CRM read-back contract today.
        verify_tool = self._clean(scenario.get("verify_with_tool"))
        if verify_tool is None:
            return findings, phases, evidence

        # Resolve canonical appointment id from output first and arguments
        # second. Reschedule/cancel normally carry appointment_id in args.
        appointment_id = None

        if isinstance(output, dict):
            appointment_id = self._clean(
                output.get("appointment_id")
                or output.get("appointmentId")
            )

            if appointment_id is None:
                for key in ("appointment", "event"):
                    candidate = output.get(key)
                    if isinstance(candidate, dict):
                        appointment_id = self._clean(
                            candidate.get("id")
                            or candidate.get("appointment_id")
                            or candidate.get("appointmentId")
                        )
                        if appointment_id is not None:
                            break

        if appointment_id is None and isinstance(arguments, dict):
            appointment_id = self._clean(
                arguments.get("appointment_id")
                or arguments.get("appointmentId")
            )

        if (
            appointment_id is None
            and expected_tool == "appointment_confirm"
        ):
            appointment_id = self._appointment_id_from_confirm_output(output)

        evidence["appointment_id"] = appointment_id

        if appointment_id is None:
            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "live_appointment_id_missing",
                    (
                        f"Successful {expected_tool} did not expose a "
                        "canonical appointment id in output or arguments."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                )
            )
            return findings, phases, evidence

        verification_calls = [
            call
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
            if call.get("tool_name") == verify_tool
            and self._step_after(
                call.get("step"),
                write_call.get("step"),
            )
        ]

        if not verification_calls:
            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "crm_post_write_read_missing",
                    (
                        f"No {verify_tool} read was executed after the "
                        f"successful {expected_tool} write."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                )
            )
            return findings, phases, evidence

        verification_call = verification_calls[-1]
        evidence["verification_mcp_call"] = (
            self._minimal_call_evidence(verification_call)
        )

        verification_items = self._walk_records(
            verification_call.get("decoded_output"),
            "appointment",
        )

        matched = next(
            (
                item
                for item in verification_items
                if self._appointment_id(item) == appointment_id
            ),
            None,
        )

        if expected_tool == "appointment_cancel":
            # Depending on CRM/read semantics, a cancelled appointment may
            # disappear from active events or remain with cancelled status.
            if matched is None:
                evidence["verification_match"] = True
                phases["crm_verification"] = "PASS"
                return findings, phases, evidence

            cancelled_status = self._clean(
                matched.get("status")
                or matched.get("state")
            )
            if cancelled_status in {"cancelled", "canceled"}:
                evidence["verification_match"] = True
                phases["crm_verification"] = "PASS"
                return findings, phases, evidence

            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "crm_post_write_verification_failed",
                    (
                        "Cancelled appointment is still returned as an "
                        "active/non-cancelled event."
                    ),
                    verification_call.get("step"),
                    verification_call.get("evidence_refs", []),
                    {
                        "appointment_id": appointment_id,
                        "observed_status": cancelled_status,
                    },
                )
            )
            return findings, phases, evidence

        evidence["verification_match"] = matched is not None

        if matched is None:
            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "crm_post_write_verification_failed",
                    (
                        f"CRM verification did not return appointment "
                        f"{appointment_id} after {expected_tool}."
                    ),
                    verification_call.get("step"),
                    verification_call.get("evidence_refs", []),
                    {
                        "appointment_id": appointment_id,
                        "observed_appointment_ids": [
                            self._appointment_id(item)
                            for item in verification_items
                        ],
                    },
                )
            )
            return findings, phases, evidence

        # For confirm/reschedule, when the write arguments expose an exact
        # new start, verify it as well.
        expected_start = None
        if isinstance(arguments, dict):
            expected_start = self._clean(
                arguments.get("start")
                or arguments.get("start_at")
                or arguments.get("startAt")
            )

        if expected_start is not None:
            observed_start = self._clean(
                matched.get("start")
                or matched.get("start_at")
                or matched.get("startAt")
            )

            if not self._same_instant(observed_start, expected_start):
                phases["crm_verification"] = "FAIL"
                findings.append(
                    finding(
                        "critical",
                        "crm_post_write_verification_failed",
                        (
                            "CRM returned the expected appointment id but "
                            "with a different start time."
                        ),
                        verification_call.get("step"),
                        verification_call.get("evidence_refs", []),
                        {
                            "appointment_id": appointment_id,
                            "expected_start": expected_start,
                            "observed_start": observed_start,
                        },
                    )
                )
                return findings, phases, evidence

        phases["crm_verification"] = "PASS"
        return findings, phases, evidence

    def _handoff_live_evaluation(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        ready_to_write: bool,
        stop_reason: str,
        writes: list[dict[str, Any]],
        write_enabled: bool,
    ) -> tuple[list[dict[str, Any]], dict[str, str], dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        phases = {
            "conversation": "SKIPPED",
            "write": "SKIPPED",
            "crm_verification": "SKIPPED",
            "cleanup": "SKIPPED",
        }
        evidence: dict[str, Any] = {
            "write_mcp_call": None,
            "write_output": None,
        }

        if not write_enabled or stop_reason == "live_write_not_enabled":
            findings.append(
                finding(
                    "warning",
                    "live_write_not_enabled",
                    (
                        "Live handoff scenario was blocked before the first "
                        "agent request because write opt-in is disabled."
                    ),
                    None,
                )
            )
            return findings, phases, evidence

        expected_writes = [
            call
            for call in writes
            if call.get("tool_name") == "handoff_request"
        ]

        if len(expected_writes) != 1 or len(writes) != 1:
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    (
                        "live_expected_write_missing"
                        if not expected_writes
                        else "multiple_writes"
                    ),
                    (
                        "Live handoff requires exactly one handoff_request "
                        f"call; observed {len(expected_writes)} expected "
                        f"and {len(writes)} total writes."
                    ),
                    writes[-1].get("step") if writes else None,
                    [
                        ref
                        for call in writes
                        for ref in call.get("evidence_refs", [])
                    ],
                )
            )
            return findings, phases, evidence

        write_call = expected_writes[0]
        evidence["write_mcp_call"] = self._minimal_call_evidence(write_call)
        evidence["write_output"] = write_call.get("decoded_output")

        write_turn = self._turn_by_step(turns, write_call.get("step"))
        observed_actions = set()

        if isinstance(write_turn, dict):
            observed_actions = {
                self._clean(write_turn.get("action")),
                self._clean(
                    write_turn.get("intent_plan", {}).get("action")
                ),
            }

        expected_action = self._clean(scenario.get("ready_action"))

        if expected_action is None or expected_action not in observed_actions:
            phases["conversation"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "handoff_action_mismatch",
                    (
                        "handoff_request executed without the expected "
                        f"structured action {expected_action!r}."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {
                        "observed_actions": sorted(
                            action
                            for action in observed_actions
                            if action is not None
                        )
                    },
                )
            )
        else:
            phases["conversation"] = "PASS"

        output = write_call.get("decoded_output")

        if self._write_outcome(write_call) != "success":
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "error",
                    "live_write_failed",
                    "handoff_request did not return a verifiable successful result.",
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {"write_output": output},
                )
            )
            return findings, phases, evidence

        if (
            not isinstance(output, dict)
            or output.get("status") != "accepted"
            or output.get("handoff_requested") is not True
        ):
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "error",
                    "handoff_result_not_accepted",
                    (
                        "handoff_request must return ok=true, "
                        "handoff_requested=true and status='accepted'."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {"write_output": output},
                )
            )
            return findings, phases, evidence

        phases["write"] = "PASS"
        return findings, phases, evidence

    def _contact_submit_live_evaluation(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
        ready_to_write: bool,
        stop_reason: str,
        writes: list[dict[str, Any]],
        write_enabled: bool,
    ) -> tuple[list[dict[str, Any]], dict[str, str], dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        phases = {
            "conversation": "SKIPPED",
            "write": "SKIPPED",
            "crm_verification": "SKIPPED",
            "cleanup": "SKIPPED",
        }
        evidence: dict[str, Any] = {
            "pre_write_contact_context": None,
            "write_mcp_call": None,
            "write_output": None,
            "post_write_contact_context": None,
            "pre_write_found": None,
            "post_write_found": None,
        }

        if not write_enabled or stop_reason == "live_write_not_enabled":
            findings.append(
                finding(
                    "warning",
                    "live_write_not_enabled",
                    (
                        "Live contact-submit scenario was blocked before "
                        "the first agent request because write opt-in is disabled."
                    ),
                    None,
                )
            )
            return findings, phases, evidence

        expected_tool = "crm_contact_submit"
        expected_writes = [
            call
            for call in writes
            if call.get("tool_name") == expected_tool
        ]

        if len(expected_writes) != 1 or len(writes) != 1:
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    (
                        "live_expected_write_missing"
                        if not expected_writes
                        else "multiple_writes"
                    ),
                    (
                        "Live contact-submit requires exactly one "
                        f"{expected_tool} call; observed "
                        f"{len(expected_writes)} expected and "
                        f"{len(writes)} total writes."
                    ),
                    writes[-1].get("step") if writes else None,
                    [
                        ref
                        for call in writes
                        for ref in call.get("evidence_refs", [])
                    ],
                )
            )
            return findings, phases, evidence

        write_call = expected_writes[0]
        evidence["write_mcp_call"] = self._minimal_call_evidence(write_call)
        evidence["write_output"] = write_call.get("decoded_output")

        ordered_calls = [
            call
            for turn in turns
            for call in turn.get("executed_mcp_calls", [])
        ]
        write_position = next(
            (
                index
                for index, call in enumerate(ordered_calls)
                if call is write_call
            ),
            None,
        )

        if write_position is None:
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "live_write_trace_missing",
                    "crm_contact_submit exists in writes but not in ordered tool traces.",
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                )
            )
            return findings, phases, evidence

        pre_context_calls = [
            call
            for call in ordered_calls[:write_position]
            if call.get("tool_name") == "contact_context"
        ]

        if pre_context_calls:
            pre_call = pre_context_calls[-1]
            evidence["pre_write_contact_context"] = (
                self._minimal_call_evidence(pre_call)
            )
            evidence["pre_write_found"] = self._contact_context_found(
                pre_call.get("decoded_output")
            )

        if "expected_pre_write_contact_found" in scenario:
            expected_pre_found = bool(
                scenario.get("expected_pre_write_contact_found")
            )
            actual_pre_found = evidence["pre_write_found"]

            if actual_pre_found is not expected_pre_found:
                phases["conversation"] = "FAIL"
                findings.append(
                    finding(
                        "critical",
                        "pre_write_contact_context_mismatch",
                        (
                            "Expected a pre-write contact_context result "
                            f"found={expected_pre_found}, got "
                            f"{actual_pre_found!r}."
                        ),
                        write_call.get("step"),
                        write_call.get("evidence_refs", []),
                        {
                            "expected_found": expected_pre_found,
                            "actual_found": actual_pre_found,
                        },
                    )
                )

        write_turn = self._turn_by_step(turns, write_call.get("step"))
        observed_actions = set()
        if isinstance(write_turn, dict):
            observed_actions = {
                self._clean(write_turn.get("action")),
                self._clean(
                    write_turn.get("intent_plan", {}).get("action")
                ),
            }

        expected_action = self._clean(scenario.get("ready_action"))
        if expected_action is None or expected_action not in observed_actions:
            phases["conversation"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "contact_submit_action_mismatch",
                    (
                        "crm_contact_submit executed without the expected "
                        f"structured action {expected_action!r}."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {
                        "observed_actions": sorted(
                            action
                            for action in observed_actions
                            if action is not None
                        )
                    },
                )
            )
        else:
            phases["conversation"] = "PASS"

        output = write_call.get("decoded_output")
        outcome = self._write_outcome(write_call)

        if outcome != "success":
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "error",
                    "live_write_failed",
                    (
                        "crm_contact_submit did not return a verifiable "
                        "successful result."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {"write_output": output},
                )
            )
            return findings, phases, evidence

        if not isinstance(output, dict) or output.get("status") != "accepted":
            phases["write"] = "FAIL"
            findings.append(
                finding(
                    "error",
                    "contact_submit_status_not_accepted",
                    (
                        "crm_contact_submit succeeded structurally but "
                        "status is not 'accepted'."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                    {"write_output": output},
                )
            )
            return findings, phases, evidence

        phases["write"] = "PASS"

        post_context_calls = [
            call
            for call in ordered_calls[write_position + 1 :]
            if call.get("tool_name") == "contact_context"
        ]

        if not post_context_calls:
            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "crm_post_write_read_missing",
                    (
                        "No contact_context read was executed after "
                        "crm_contact_submit."
                    ),
                    write_call.get("step"),
                    write_call.get("evidence_refs", []),
                )
            )
            return findings, phases, evidence

        post_call = post_context_calls[-1]
        post_found = self._contact_context_found(
            post_call.get("decoded_output")
        )
        evidence["post_write_contact_context"] = (
            self._minimal_call_evidence(post_call)
        )
        evidence["post_write_found"] = post_found

        if post_found is not True:
            phases["crm_verification"] = "FAIL"
            findings.append(
                finding(
                    "critical",
                    "crm_post_write_verification_failed",
                    (
                        "Post-write contact_context did not confirm "
                        "found=true for the submitted contact."
                    ),
                    post_call.get("step"),
                    post_call.get("evidence_refs", []),
                )
            )
        else:
            phases["crm_verification"] = "PASS"

        return findings, phases, evidence

    def _contact_context_found(self, output: Any) -> bool | None:
        if not isinstance(output, dict):
            return None

        found = output.get("found")
        if isinstance(found, bool):
            return found

        contact = output.get("contact")
        if isinstance(contact, dict):
            nested_found = contact.get("found")
            if isinstance(nested_found, bool):
                return nested_found

        return None

    def _appointment_id_from_confirm_output(self, output: Any) -> str | None:
        if not isinstance(output, dict):
            return None
        appointment = output.get("appointment")
        if not isinstance(appointment, dict):
            return None
        return self._clean(appointment.get("id"))

    def _latest_selected_slot(self, turns: list[dict[str, Any]]) -> dict[str, Any] | None:
        for turn in reversed(turns):
            appointment = turn.get("structured_data", {}).get("appointment", {})
            slot = appointment.get("selected_slot") if isinstance(appointment, dict) else None
            if isinstance(slot, dict) and slot:
                return slot
        return None

    def _latest_agenda_timezone(self, turns: list[dict[str, Any]]) -> str | None:
        for turn in reversed(turns):
            for call in reversed(turn.get("executed_mcp_calls", [])):
                if call.get("tool_name") not in AGENDA_TOOLS:
                    continue
                arguments = call.get("arguments")
                if isinstance(arguments, dict):
                    timezone = self._clean(arguments.get("timezone"))
                    if timezone is not None:
                        return timezone
        return None

    def _minimal_call_evidence(self, call: dict[str, Any]) -> dict[str, Any]:
        arguments = call.get("arguments")
        output = call.get("decoded_output")
        argument_summary = {
            key: arguments.get(key)
            for key in (
                "tenant_id",
                "start_at",
                "end_at",
                "date_from",
                "date_to",
                "timezone",
                "service_id",
                "service_ids",
                "owner_id",
                "status",
            )
            if isinstance(arguments, dict) and arguments.get(key) is not None
        }
        output_summary = {
            key: output.get(key)
            for key in ("ok", "confirmed", "appointment_confirmed", "found", "count", "error_code")
            if isinstance(output, dict) and output.get(key) is not None
        }
        return {
            "step": call.get("step"),
            "type": call.get("type"),
            "tool_name": call.get("tool_name"),
            "status": call.get("status"),
            "error_code": call.get("error_code"),
            "arguments": argument_summary,
            "output": output_summary,
        }

    def _step_after(self, candidate: Any, reference: Any) -> bool:
        return isinstance(candidate, int) and isinstance(reference, int) and candidate > reference

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

    def _service_contract_findings(
        self,
        scenario: dict[str, Any],
        turns: list[dict[str, Any]],
    ) -> list[dict[str, Any]]:
        if not turns:
            return []

        findings: list[dict[str, Any]] = []
        provenance: dict[str, str] = {}
        previous_selection: list[str] = []
        expected_count = scenario.get("expected_service_count")
        last_agenda_ids: list[str] = []

        # Fixture UUIDs are not stable. Resolve scenario service-name
        # contracts from the real services_search evidence of this run.
        resolved_by_name: dict[str, set[str]] = {}

        def collect_named_services(value: Any) -> None:
            if isinstance(value, list):
                for item in value:
                    collect_named_services(item)
                return

            if not isinstance(value, dict):
                return

            service_id = self._clean(
                value.get("id")
                or value.get("service_id")
                or value.get("serviceId")
            )
            service_name = self._clean(value.get("name"))

            if service_id is not None and service_name is not None:
                resolved_by_name.setdefault(
                    service_name.casefold(),
                    set(),
                ).add(service_id)

            for child in value.values():
                if isinstance(child, (dict, list)):
                    collect_named_services(child)

        for turn in turns:
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") == "services_search":
                    collect_named_services(call.get("decoded_output"))

        def resolve_names(field: str) -> tuple[list[str], list[str], list[str]]:
            names = self._string_list(scenario.get(field))
            ids: list[str] = []
            unresolved: list[str] = []

            for name in names:
                matches = sorted(resolved_by_name.get(name.casefold(), set()))
                if len(matches) == 1:
                    ids.append(matches[0])
                else:
                    unresolved.append(name)

            return names, ids, unresolved

        expected_names, expected_name_ids, unresolved_expected = resolve_names(
            "expected_service_names"
        )
        required_names, required_name_ids, unresolved_required = resolve_names(
            "required_service_names"
        )
        forbidden_names, forbidden_name_ids, unresolved_forbidden = resolve_names(
            "forbidden_final_service_names"
        )
        allowed_removed_names, allowed_removed_name_ids, _ = resolve_names(
            "allowed_removed_service_names"
        )

        expected_ids = list(
            dict.fromkeys(
                self._string_list(scenario.get("expected_service_ids"))
                + expected_name_ids
            )
        )
        required_ids = list(
            dict.fromkeys(
                self._string_list(scenario.get("required_service_ids"))
                + required_name_ids
            )
        )
        forbidden_ids = list(
            dict.fromkeys(
                self._string_list(
                    scenario.get("forbidden_final_service_ids")
                )
                + forbidden_name_ids
            )
        )
        allowed_removed = set(
            self._string_list(scenario.get("allowed_removed_service_ids"))
            + allowed_removed_name_ids
        )

        unresolved_contract = list(
            dict.fromkeys(
                unresolved_expected
                + unresolved_required
                + unresolved_forbidden
            )
        )
        if unresolved_contract:
            findings.append(
                finding(
                    "error",
                    "service_ids_incompatible",
                    (
                        "Scenario service-name contract could not be resolved "
                        "from real services_search evidence."
                    ),
                    turns[-1].get("step"),
                    turns[-1].get("artifact_refs", []),
                    {
                        "unresolved_service_names": unresolved_contract,
                        "resolved_service_names": {
                            name: sorted(ids)
                            for name, ids in resolved_by_name.items()
                        },
                    },
                )
            )

        for turn in turns:
            step = turn.get("step")

            for call in turn.get("executed_mcp_calls", []):
                tool_name = call.get("tool_name")

                if tool_name in {
                    "services_search",
                    "contact_context",
                    "appointment_events",
                }:
                    for service_id in self._service_ids_from_output(
                        call.get("decoded_output")
                    ):
                        provenance.setdefault(
                            service_id,
                            f"turn:{step}:mcp:{tool_name}",
                        )

                if tool_name not in AGENDA_TOOLS:
                    continue

                arguments = call.get("arguments")
                if not isinstance(arguments, dict):
                    continue

                call_ids = self._argument_service_ids(arguments)
                if call_ids:
                    last_agenda_ids = call_ids

                findings.extend(
                    self._service_id_list_findings(
                        call_ids,
                        provenance,
                        step,
                        call.get("evidence_refs", []),
                        f"MCP arguments for {tool_name}",
                    )
                )

                if len(call_ids) > 1:
                    forbidden_timing = [
                        key
                        for key in (
                            "duration_minutes",
                            "duration_total",
                            "total_duration",
                            "buffer_before_minutes",
                            "buffer_after_minutes",
                            "buffer_minutes",
                        )
                        if arguments.get(key) is not None
                    ]

                    if forbidden_timing:
                        findings.append(
                            finding(
                                "error",
                                "multiservice_derived_timing",
                                (
                                    f"Multi-service {tool_name} carries "
                                    "timing fields owned by downstream: "
                                    + ", ".join(forbidden_timing)
                                    + "."
                                ),
                                step,
                                call.get("evidence_refs", []),
                                {
                                    "service_ids": call_ids,
                                    "timing_fields": forbidden_timing,
                                },
                            )
                        )

            selection = self._selected_service_ids(turn)

            if selection:
                findings.extend(
                    self._service_id_list_findings(
                        selection,
                        provenance,
                        step,
                        turn.get("artifact_refs", []),
                        "structured_data.services",
                    )
                )

                removed = (
                    set(previous_selection)
                    .difference(selection)
                    .difference(allowed_removed)
                )

                if removed:
                    findings.append(
                        finding(
                            "error",
                            "service_selection_lost",
                            (
                                "Previously selected service IDs disappeared "
                                "without a declared replacement: "
                                f"{sorted(removed)}."
                            ),
                            step,
                            turn.get("artifact_refs", []),
                            {
                                "previous_service_ids": previous_selection,
                                "observed_service_ids": selection,
                                "lost_service_ids": sorted(removed),
                                "allowed_removed_service_names": (
                                    allowed_removed_names
                                ),
                            },
                        )
                    )

                previous_selection = selection

            elif (
                previous_selection
                and turn.get("customer_decision") != "verification_message"
                and self._turn_uses_appointment_domain(turn)
            ):
                findings.append(
                    finding(
                        "error",
                        "service_selection_lost",
                        (
                            "The appointment turn no longer persists the "
                            "previously resolved structured service selection."
                        ),
                        step,
                        turn.get("artifact_refs", []),
                        {
                            "previous_service_ids": previous_selection,
                            "observed_service_ids": [],
                        },
                    )
                )

        observed = previous_selection or last_agenda_ids

        if expected_count is not None and len(observed) != int(expected_count):
            findings.append(
                finding(
                    "error",
                    "service_ids_incompatible",
                    (
                        f"Expected {expected_count} final service IDs, "
                        f"observed {len(observed)}."
                    ),
                    turns[-1].get("step"),
                    turns[-1].get("artifact_refs", []),
                    {
                        "expected_service_count": expected_count,
                        "observed_service_ids": observed,
                    },
                )
            )

        if expected_ids and set(observed) != set(expected_ids):
            findings.append(
                finding(
                    "error",
                    "service_ids_incompatible",
                    "The final service set differs from the scenario contract.",
                    turns[-1].get("step"),
                    turns[-1].get("artifact_refs", []),
                    {
                        "expected_service_names": expected_names,
                        "expected_service_ids": expected_ids,
                        "observed_service_ids": observed,
                    },
                )
            )

        missing_required = sorted(set(required_ids).difference(observed))
        forbidden_observed = sorted(set(forbidden_ids).intersection(observed))

        if missing_required or forbidden_observed:
            findings.append(
                finding(
                    "error",
                    "service_ids_incompatible",
                    (
                        "The final service selection violates "
                        "required/replaced service constraints."
                    ),
                    turns[-1].get("step"),
                    turns[-1].get("artifact_refs", []),
                    {
                        "required_service_names": required_names,
                        "required_service_ids": required_ids,
                        "forbidden_service_names": forbidden_names,
                        "forbidden_service_ids": forbidden_ids,
                        "observed_service_ids": observed,
                    },
                )
            )

        if (
            expected_ids
            and last_agenda_ids
            and set(last_agenda_ids) != set(expected_ids)
        ):
            findings.append(
                finding(
                    "error",
                    "service_ids_incompatible",
                    (
                        "The latest agenda call did not use the complete "
                        "expected service set."
                    ),
                    turns[-1].get("step"),
                    evidence={
                        "expected_service_names": expected_names,
                        "expected_service_ids": expected_ids,
                        "agenda_service_ids": last_agenda_ids,
                    },
                )
            )

        return findings

    def _service_id_list_findings(
        self,
        service_ids: list[str],
        provenance: dict[str, str],
        step: Any,
        refs: list[str],
        location: str,
    ) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        duplicates = sorted(service_id for service_id, count in Counter(service_ids).items() if count > 1)
        if duplicates:
            findings.append(
                finding(
                    "error",
                    "duplicate_service_ids",
                    f"{location} contains duplicate service IDs: {duplicates}.",
                    step,
                    refs,
                    {"observed_service_ids": service_ids, "duplicate_service_ids": duplicates},
                )
            )
        missing = sorted(set(service_ids).difference(provenance))
        if missing:
            findings.append(
                finding(
                    "error",
                    "service_id_without_provenance",
                    f"{location} contains service IDs without prior or same-turn MCP provenance: {missing}.",
                    step,
                    refs,
                    {
                        "observed_service_ids": service_ids,
                        "missing_provenance": missing,
                        "service_provenance": provenance,
                    },
                )
            )
        return findings

    def _booking_url_findings(
        self, scenario: dict[str, Any], turns: list[dict[str, Any]]
    ) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        latest_mcp_url: str | None = None
        invitation_call_seen = False
        for turn in turns:
            step = turn.get("step")
            calls = [
                call
                for call in turn.get("executed_mcp_calls", [])
                if call.get("tool_name") == "appointment_booking_invitation"
            ]
            for call in calls:
                invitation_call_seen = True
                output = call.get("decoded_output")
                mcp_url = self._clean(output.get("booking_url")) if isinstance(output, dict) else None
                if mcp_url is not None:
                    latest_mcp_url = mcp_url
                succeeded = (
                    isinstance(output, dict)
                    and output.get("ok") is True
                    and output.get("created") is True
                    and mcp_url is not None
                )
                if not succeeded and self._claims_invitation_success(turn):
                    findings.append(
                        finding(
                            "error",
                            "booking_invitation_contract_failed",
                            "Structured state claims invitation success without ok=true, created=true and a non-empty downstream booking_url.",
                            step,
                            call.get("evidence_refs", []),
                            {"mcp_output": output},
                        )
                    )

            invitation = self._booking_invitation(turn)
            final_url = self._clean(invitation.get("booking_url")) if invitation else None
            if final_url is not None and latest_mcp_url is None:
                findings.append(
                    finding(
                        "critical",
                        "booking_url_without_provenance",
                        "structured_data contains booking_url without real MCP invitation provenance.",
                        step,
                        turn.get("artifact_refs", []),
                        {"booking_url_final": final_url, "booking_url_mcp": None},
                    )
                )
            elif final_url is not None and final_url != latest_mcp_url:
                findings.append(
                    finding(
                        "error",
                        "booking_url_mismatch",
                        "The final booking_url differs literally from downstream MCP output.",
                        step,
                        turn.get("artifact_refs", []),
                        {"booking_url_mcp": latest_mcp_url, "booking_url_final": final_url},
                    )
                )
        if scenario.get("requires_booking_url") and turns and not invitation_call_seen:
            findings.append(
                finding(
                    "warning",
                    "dry_run_write_precondition",
                    "booking_url cannot be proven without controlled appointment_booking_invitation output.",
                    None,
                )
            )
        return findings

    def _timezone_findings(self, turns: list[dict[str, Any]]) -> list[dict[str, Any]]:
        findings: list[dict[str, Any]] = []
        contact_timezone: str | None = None
        contact_source: str | None = None
        for turn in turns:
            step = turn.get("step")
            for call in turn.get("executed_mcp_calls", []):
                if call.get("tool_name") == "contact_context":
                    timezone, source = self._contact_timezone(call.get("decoded_output"))
                    if timezone is not None:
                        contact_timezone = timezone
                        contact_source = source
                    continue
                if call.get("tool_name") not in AGENDA_TOOLS or contact_timezone is None:
                    continue
                arguments = call.get("arguments")
                used = self._clean(arguments.get("timezone")) if isinstance(arguments, dict) else None
                if used is not None and used != contact_timezone:
                    findings.append(
                        finding(
                            "error",
                            "timezone_mismatch",
                            f"Agenda call uses timezone {used!r}, but latest contact_context returned {contact_timezone!r}.",
                            step,
                            call.get("evidence_refs", []),
                            {
                                "timezone_contact_context": contact_timezone,
                                "timezone_source": contact_source,
                                "timezone_mcp_call": used,
                                "tool_name": call.get("tool_name"),
                            },
                        )
                    )
        return findings

    def _appointment_verification_findings(
        self, scenario: dict[str, Any], calls: list[dict[str, Any]], turns: list[dict[str, Any]]
    ) -> list[dict[str, Any]]:
        if not scenario.get("requires_appointment_events"):
            return []
        names = [call.get("tool_name") for call in calls]
        refs = [
            ref
            for turn in turns
            for ref in turn.get("artifact_refs", [])
            if ref.endswith("03-final-request.json")
        ]
        findings: list[dict[str, Any]] = []
        if "appointment_events" not in names:
            findings.append(
                finding(
                    "warning",
                    "appointment_events_not_used",
                    "Explicit existing-appointment verification did not execute appointment_events.",
                    None,
                    refs,
                )
            )
        if "appointment_availability" in names:
            findings.append(
                finding(
                    "error",
                    "availability_used_for_verification",
                    "appointment_availability was used during an explicit existing-appointment verification scenario.",
                    None,
                    refs,
                )
            )
        return findings

    def _multiservice_evidence(
        self, scenario: dict[str, Any], turns: list[dict[str, Any]]
    ) -> dict[str, Any]:
        provenance: dict[str, str] = {}
        agenda_calls: list[dict[str, Any]] = []
        contact_timezone: str | None = None
        timezone_source: str | None = None
        booking_url_mcp: str | None = None
        booking_url_final: str | None = None
        for turn in turns:
            step = turn.get("step")
            for call in turn.get("executed_mcp_calls", []):
                tool_name = call.get("tool_name")
                if tool_name in {"services_search", "contact_context", "appointment_events"}:
                    for service_id in self._service_ids_from_output(call.get("decoded_output")):
                        provenance.setdefault(service_id, f"turn:{step}:mcp:{tool_name}")
                if tool_name == "contact_context":
                    timezone, source = self._contact_timezone(call.get("decoded_output"))
                    if timezone is not None:
                        contact_timezone, timezone_source = timezone, source
                if tool_name in AGENDA_TOOLS:
                    arguments = call.get("arguments")
                    agenda_calls.append(
                        {
                            "step": step,
                            "tool_name": tool_name,
                            "service_ids": self._argument_service_ids(arguments) if isinstance(arguments, dict) else [],
                            "timezone": self._clean(arguments.get("timezone")) if isinstance(arguments, dict) else None,
                        }
                    )
                if tool_name == "appointment_booking_invitation" and isinstance(call.get("decoded_output"), dict):
                    booking_url_mcp = self._clean(call["decoded_output"].get("booking_url"))
            invitation = self._booking_invitation(turn)
            if invitation:
                booking_url_final = self._clean(invitation.get("booking_url"))
        return {
            "expected_service_ids": self._string_list(scenario.get("expected_service_ids")),
            "observed_service_ids": self._selected_service_ids(turns[-1]) if turns else [],
            "service_provenance": provenance,
            "agenda_calls": agenda_calls,
            "booking_url_mcp": booking_url_mcp,
            "booking_url_final": booking_url_final,
            "timezone_contact_context": contact_timezone,
            "timezone_source": timezone_source,
        }

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
        if not requires_slot and not scenario.get("expects_no_availability"):
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

    def _same_instant(
        self,
        left: str | None,
        right: str | None,
    ) -> bool:
        if left is None or right is None:
            return False
        if left == right:
            return True

        try:
            left_dt = datetime.fromisoformat(
                left.replace("Z", "+00:00")
            )
            right_dt = datetime.fromisoformat(
                right.replace("Z", "+00:00")
            )
        except ValueError:
            return False

        if left_dt.tzinfo is None or right_dt.tzinfo is None:
            return False

        return (
            left_dt.astimezone(timezone.utc)
            == right_dt.astimezone(timezone.utc)
        )

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

    def _selected_service_ids(self, turn: dict[str, Any]) -> list[str]:
        services = turn.get("structured_data", {}).get("services", {})
        if not isinstance(services, dict):
            return []
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
            return [service_id] if service_id is not None else []
        return []

    def _argument_service_ids(self, arguments: dict[str, Any]) -> list[str]:
        plural = self._string_list(arguments.get("service_ids"))
        if plural:
            return plural
        singular = self._clean(arguments.get("service_id") or arguments.get("service_ref"))
        return [singular] if singular is not None else []

    def _service_ids_from_output(self, output: Any) -> list[str]:
        result: list[str] = []
        if isinstance(output, list):
            for item in output:
                result.extend(self._service_ids_from_output(item))
        elif isinstance(output, dict):
            looks_like_service = any(
                key in output
                for key in ("duration_minutes", "is_bookable", "bookable", "service_id", "serviceId")
            )
            if looks_like_service:
                service_id = self._clean(
                    output.get("service_id") or output.get("serviceId") or output.get("id")
                )
                if service_id is not None:
                    result.append(service_id)
            result.extend(self._string_list(output.get("service_ids")))
            result.extend(self._string_list(output.get("serviceIds")))
            services = output.get("services")
            if isinstance(services, list):
                for service in services:
                    if not isinstance(service, dict):
                        continue
                    service_id = self._clean(
                        service.get("id") or service.get("service_id") or service.get("serviceId")
                    )
                    if service_id is not None:
                        result.append(service_id)
            for child in output.values():
                if isinstance(child, (dict, list)):
                    result.extend(self._service_ids_from_output(child))
        return list(dict.fromkeys(result))

    def _contact_timezone(self, output: Any) -> tuple[str | None, str | None]:
        if not isinstance(output, dict):
            return None, None
        nested = output.get("contact_context")
        source = nested if isinstance(nested, dict) else output
        return self._clean(source.get("timezone")), self._clean(source.get("timezone_source")) or "contact_context"

    def _booking_invitation(self, turn: dict[str, Any]) -> dict[str, Any] | None:
        appointment = turn.get("structured_data", {}).get("appointment", {})
        value = appointment.get("booking_invitation") if isinstance(appointment, dict) else None
        return value if isinstance(value, dict) else None

    def _claims_invitation_success(self, turn: dict[str, Any]) -> bool:
        invitation = self._booking_invitation(turn)
        return bool(
            turn.get("action") == "completed"
            or (
                invitation
                and (invitation.get("ok") is True or invitation.get("created") is True)
            )
        )

    def _turn_uses_appointment_domain(self, turn: dict[str, Any]) -> bool:
        return turn.get("intent") in {
            "request_availability",
            "select_offered_slot",
            "request_booking_confirmation",
            "request_booking_invitation",
        } or turn.get("intent_plan", {}).get("domain") == "appointment"

    def _string_list(self, value: Any) -> list[str]:
        if not isinstance(value, list):
            return []
        return [clean for item in value for clean in [self._clean(item)] if clean is not None]

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
