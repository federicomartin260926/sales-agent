from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from customer_simulator import CustomerDecision, CustomerSimulator
from evaluator import Evaluator, WRITE_TOOLS, finding


SCRIPT_DIR = Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parents[2]
SCENARIO_DIR = SCRIPT_DIR / "scenarios"
DEFAULT_CONTEXT_DIR = REPO_ROOT / "var/sa-llm/context"
DEFAULT_RESULTS_DIR = REPO_ROOT / "var/sa-llm/autonomous-tests"
DEFAULT_CONTAINER_CONTEXT_PREFIX = "/app/var/sa-llm/context"


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat()


def make_run_id(prefix: str = "run") -> str:
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    return f"{prefix}-{stamp}-{uuid.uuid4().hex[:8]}"


def load_scenario(name_or_path: str) -> tuple[dict[str, Any], Path, str]:
    candidate = Path(name_or_path)
    if not candidate.suffix:
        candidate = SCENARIO_DIR / f"{name_or_path}.json"
    elif not candidate.is_absolute():
        candidate = (Path.cwd() / candidate).resolve()
    raw = candidate.read_bytes()
    scenario = json.loads(raw.decode("utf-8"))
    required = {
        "name",
        "goal",
        "defaults",
        "initial_message",
        "max_turns",
        "customer_data",
        "preferred_slot_strategy",
        "expected_write_tool",
        "ready_action",
        "requires_selected_slot",
        "requires_existing_appointment",
        "recommended_read_tools",
        "preconditions",
        "documented_invariants",
    }
    missing = sorted(required.difference(scenario))
    if missing:
        raise ValueError(f"Scenario {candidate} is missing fields: {', '.join(missing)}")
    if scenario["expected_write_tool"] not in WRITE_TOOLS:
        raise ValueError(f"Unsupported expected_write_tool: {scenario['expected_write_tool']}")
    mode = scenario.get("mode", "dry_run")
    if mode not in {"dry_run", "live"}:
        raise ValueError(f"Unsupported scenario mode: {mode}")
    if mode == "live":
        live_required = {
            "confirm_action",
            "confirmation_message",
            "verification_message",
            "verify_with_tool",
            "cleanup_with_tool",
            "cleanup_required",
        }
        live_missing = sorted(live_required.difference(scenario))
        if live_missing:
            raise ValueError(f"Live scenario {candidate} is missing fields: {', '.join(live_missing)}")
    return scenario, candidate, hashlib.sha256(raw).hexdigest()


def git_metadata() -> dict[str, Any]:
    commit = subprocess.run(
        ["git", "rev-parse", "HEAD"], cwd=REPO_ROOT, text=True, capture_output=True, check=False
    ).stdout.strip()
    status = subprocess.run(
        ["git", "status", "--porcelain"], cwd=REPO_ROOT, text=True, capture_output=True, check=False
    ).stdout
    return {"commit": commit or None, "clean": status == "", "status": status.splitlines()}


def post_turn(api_url: str, token: str, payload: dict[str, Any], timeout: float) -> tuple[int, str, dict[str, Any]]:
    request = urllib.request.Request(
        api_url,
        data=json.dumps(payload, ensure_ascii=False).encode("utf-8"),
        headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = response.status
            raw = response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        status = exc.code
        raw = exc.read().decode("utf-8", errors="replace")
    parsed = json.loads(raw)
    if not isinstance(parsed, dict):
        raise ValueError("Agent response must be a JSON object")
    return status, raw, parsed


def build_payload(
    scenario: dict[str, Any], conversation_id: str, message_id: str, message: str
) -> dict[str, Any]:
    defaults = scenario["defaults"]
    payload: dict[str, Any] = {
        "tenant_id": defaults["tenant_id"],
        "channel_type": defaults.get("channel_type", "whatsapp"),
        "external_channel_id": defaults.get("external_channel_id", "test-whatsapp-e2e"),
        "external_conversation_id": conversation_id,
        "message": {"id": message_id, "type": "text", "text": message},
        "contact": {
            "phone": defaults.get("contact_phone", ""),
            "name": defaults.get("contact_name", ""),
        },
    }
    if defaults.get("entrypoint_ref"):
        payload["entrypoint_ref"] = defaults["entrypoint_ref"]
    return payload


def resolve_debug_path(
    reported: str,
    context_dir: Path,
    container_prefix: str,
    internal_conversation_id: str | None,
    message_id: str,
) -> Path:
    reported_path = Path(reported)
    if reported_path.exists():
        return reported_path
    prefix = container_prefix.rstrip("/")
    if reported == prefix or reported.startswith(prefix + "/"):
        relative = reported[len(prefix) :].lstrip("/")
        return context_dir / relative
    if internal_conversation_id:
        return context_dir / internal_conversation_id / message_id / reported_path.name
    return context_dir / message_id / reported_path.name


def read_artifacts(
    response: dict[str, Any], context_dir: Path, container_prefix: str, message_id: str
) -> tuple[dict[str, Any], list[dict[str, Any]], list[str]]:
    data = response.get("data_to_save") or {}
    internal_conversation_id = data.get("conversation_id")
    reported_files = ((data.get("llm_context_debug") or {}).get("files") or [])
    by_name = {Path(item).name: item for item in reported_files if isinstance(item, str)}
    public_tool_plan = data.get("tool_plan")
    public_traces = data.get("mcp_tool_traces")
    specs = {
        "02-intent-response.json": False,
        "03-final-request.json": not (
            isinstance(public_tool_plan, dict) and isinstance(public_tool_plan.get("allowed_tools"), list)
        ),
        "04-final-response.json": not isinstance(public_traces, list),
    }
    loaded: dict[str, Any] = {}
    statuses: list[dict[str, Any]] = []
    refs: list[str] = []
    for name, critical in specs.items():
        reported = by_name.get(name, name)
        path = resolve_debug_path(
            reported, context_dir, container_prefix, internal_conversation_id, message_id
        )
        refs.append(str(path))
        status = {"name": name, "path": str(path), "critical": critical, "status": "ok"}
        try:
            loaded[name] = json.loads(path.read_text(encoding="utf-8"))
        except FileNotFoundError:
            status["status"] = "missing"
        except (json.JSONDecodeError, UnicodeDecodeError):
            status["status"] = "invalid_json"
        except OSError as exc:
            status["status"] = "unreadable"
            status["error_type"] = exc.__class__.__name__
        statuses.append(status)
    return loaded, statuses, refs


def decode_mcp_output(output: Any) -> tuple[Any, bool]:
    if not isinstance(output, str):
        return output, True
    try:
        return json.loads(output), True
    except json.JSONDecodeError:
        return output, False


def normalize_turn(
    step: int,
    message: str,
    decision: CustomerDecision,
    status: int,
    response: dict[str, Any],
    artifacts: dict[str, Any],
    artifact_status: list[dict[str, Any]],
    artifact_refs: list[str],
) -> dict[str, Any]:
    data = response.get("data_to_save") or {}
    intent_artifact = artifacts.get("02-intent-response.json") or {}
    request_artifact = artifacts.get("03-final-request.json") or {}
    final_artifact = artifacts.get("04-final-response.json") or {}
    intent_plan = intent_artifact.get("intent_plan") or data.get("intent_plan") or {}
    request_context = request_artifact.get("request_context") or {}
    tool_plan = request_context.get("tool_plan") or data.get("tool_plan") or {}
    final_response = final_artifact.get("final_response") or {}
    structured_data = final_response.get("structured_data") or data.get("structured_data") or {}
    traces = final_artifact.get("tool_traces")
    if not isinstance(traces, list):
        traces = data.get("mcp_tool_traces") or []
    calls: list[dict[str, Any]] = []
    for trace in traces:
        if not isinstance(trace, dict) or trace.get("type") != "mcp_call":
            continue
        decoded, decoded_ok = decode_mcp_output(trace.get("output"))
        calls.append(
            {
                "step": step,
                "type": trace.get("type"),
                "tool_name": trace.get("tool_name"),
                "status": trace.get("status"),
                "error_code": trace.get("error_code"),
                "arguments": trace.get("arguments"),
                "decoded_output": decoded,
                "output_json_decoded": decoded_ok,
                "evidence_refs": [ref for ref in artifact_refs if ref.endswith("04-final-response.json")],
            }
        )
    return {
        "step": step,
        "timestamp": utc_now(),
        "http_status": status,
        "user_message": message,
        "customer_decision": decision.kind,
        "customer_decision_reason": decision.reason,
        "customer_data_fields": list(decision.data_fields),
        "customer_evidence_refs": list(decision.evidence_refs),
        "reply": response.get("reply", ""),
        "intent": response.get("intent") or final_response.get("intent"),
        "action": response.get("action") or final_response.get("action"),
        "intent_plan": intent_plan,
        "allowed_tools": tool_plan.get("allowed_tools") or request_context.get("mcp_allowed_tools") or [],
        "executed_mcp_calls": calls,
        "structured_data": structured_data,
        "artifact_refs": artifact_refs,
        "artifact_status": artifact_status,
        "findings": [],
    }


def determine_missing_precondition(scenario: dict[str, Any], turns: list[dict[str, Any]], evaluator: Evaluator) -> bool:
    if not scenario.get("requires_existing_appointment"):
        return False
    read_seen = any(
        call.get("tool_name") in {"contact_context", "appointment_events"}
        for turn in turns
        for call in turn.get("executed_mcp_calls", [])
    )
    return read_seen and not evaluator.has_reliable_appointment(turns)


def render_scenario_report(
    metadata: dict[str, Any], evaluation: dict[str, Any], turns: list[dict[str, Any]], output_dir: Path
) -> str:
    lines = [
        f"# Scenario: {evaluation['scenario']}",
        "",
        f"- Result: {evaluation['result']}",
        f"- Ready to write: {str(evaluation['ready_to_write']).lower()}",
        f"- Stop reason: {evaluation['stop_reason']}",
        f"- Turns: {evaluation['turns']}",
        f"- Writes executed: {evaluation['writes_executed']}",
        f"- Conversation ID: `{metadata['external_conversation_id']}`",
        f"- Tenant ID: `{metadata['tenant_id']}`",
        f"- Git commit: `{metadata['git']['commit']}`",
        f"- Git clean: {str(metadata['git']['clean']).lower()}",
        f"- Scenario SHA-256: `{metadata['scenario_sha256']}`",
        f"- Transcript: `{output_dir / 'transcript.json'}`",
        f"- Evaluation: `{output_dir / 'evaluation.json'}`",
        "",
        "## Tools executed",
        "",
    ]
    if evaluation["tools_executed"]:
        lines.extend(f"- `{name}`: {count}" for name, count in evaluation["tools_executed"].items())
    else:
        lines.append("- None")
    lines.extend(["", "## Findings", ""])
    if not evaluation["findings"]:
        lines.append("- None")
    for item in evaluation["findings"]:
        step = f" step={item['step']}" if item.get("step") is not None else ""
        lines.append(f"- [{item['severity'].upper()}] `{item['code']}`{step}: {item['message']}")
        for ref in item.get("evidence_refs", []):
            lines.append(f"  - Evidence: `{ref}`")
        lines.append(f"  - Recommendation: {item['recommendation']}")
        if item.get("evidence"):
            lines.append(
                f"  - Structured evidence: `{json.dumps(item['evidence'], ensure_ascii=False, sort_keys=True)}`"
            )
        if item.get("severity") not in {"error", "critical"} or item.get("step") is None:
            continue
        turn = next((candidate for candidate in turns if candidate.get("step") == item["step"]), None)
        if turn is None:
            continue
        calls = [
            f"{call.get('tool_name')}[{call.get('status') or 'unknown'}]"
            for call in turn.get("executed_mcp_calls", [])
        ]
        lines.extend(
            [
                "  - Failing turn:",
                f"    - User message: {json.dumps(turn.get('user_message', ''), ensure_ascii=False)}",
                f"    - Intent/action: `{turn.get('intent')}` / `{turn.get('action')}`",
                f"    - Allowed tools: `{json.dumps(turn.get('allowed_tools', []), ensure_ascii=False)}`",
                f"    - Real MCP calls: `{json.dumps(calls, ensure_ascii=False)}`",
            ]
        )
    if evaluation.get("multiservice_evidence"):
        lines.extend(
            [
                "",
                "## Multi-service evidence",
                "",
                "```json",
                json.dumps(evaluation["multiservice_evidence"], ensure_ascii=False, indent=2),
                "```",
            ]
        )
    if evaluation.get("phases"):
        lines.extend(["", "## Live phases", ""])
        lines.extend(
            f"- {name}: {result}"
            for name, result in evaluation["phases"].items()
        )
    if evaluation.get("live_evidence"):
        lines.extend(
            [
                "",
                "## Live evidence",
                "",
                "```json",
                json.dumps(evaluation["live_evidence"], ensure_ascii=False, indent=2),
                "```",
            ]
        )
    return "\n".join(lines) + "\n"


def run_scenario(
    scenario_name: str,
    output_dir: Path | None = None,
    conversation_id: str | None = None,
    max_turns_override: int | None = None,
    api_url: str | None = None,
    context_dir: Path | None = None,
    container_context_prefix: str | None = None,
    timeout: float = 180.0,
) -> tuple[dict[str, Any], Path]:
    scenario, scenario_path, scenario_hash = load_scenario(scenario_name)
    defaults = scenario["defaults"]
    mode = str(scenario.get("mode", "dry_run"))
    writes_enabled = mode == "live" and os.environ.get("SA_E2E_ALLOW_WRITES") == "1"
    external_conversation_id = conversation_id or f"{defaults['conversation_prefix']}-{make_run_id('conv')}"
    max_turns = max_turns_override or int(scenario["max_turns"])
    api_url = api_url or os.environ.get("SA_E2E_API_URL") or f"http://localhost:{os.environ.get('API_PORT', '8000')}/agent/respond"
    token = os.environ.get("SALES_AGENT_BEARER_TOKEN", "sales-agent-bearer-token")
    context_dir = (context_dir or Path(os.environ.get("SA_E2E_CONTEXT_DIR", DEFAULT_CONTEXT_DIR))).resolve()
    container_context_prefix = container_context_prefix or os.environ.get(
        "SA_E2E_CONTAINER_CONTEXT_PREFIX", DEFAULT_CONTAINER_CONTEXT_PREFIX
    )
    if output_dir is None:
        output_dir = DEFAULT_RESULTS_DIR / make_run_id(str(scenario["name"])) / str(scenario["name"])
    output_dir.mkdir(parents=True, exist_ok=True)

    metadata = {
        "timestamp": utc_now(),
        "scenario": scenario["name"],
        "scenario_version": scenario.get("version"),
        "scenario_path": str(scenario_path),
        "scenario_sha256": scenario_hash,
        "external_conversation_id": external_conversation_id,
        "internal_conversation_id": None,
        "tenant_id": defaults["tenant_id"],
        "api_url": api_url,
        "context_dir": str(context_dir),
        "container_context_prefix": container_context_prefix,
        "mode": mode,
        "dry_run": not writes_enabled,
        "writes_enabled": writes_enabled,
        "git": git_metadata(),
    }
    turns: list[dict[str, Any]] = []
    simulator = CustomerSimulator()
    evaluator = Evaluator()
    immediate_findings: list[dict[str, Any]] = []
    decision = simulator.decide(scenario, turns, allow_writes=writes_enabled)
    ready_to_write = False
    stop_reason = "unknown"

    if scenario.get("skip_live_reason"):
        decision = CustomerDecision(
            kind="completed",
            reason=str(scenario["skip_live_reason"]),
        )

    for step in range(1, max_turns + 1):
        if decision.kind == "live_write_not_enabled":
            ready_to_write = True
            stop_reason = decision.reason
            break
        if decision.kind not in {"message", "write_confirmation", "verification_message"} or decision.message is None:
            ready_to_write = decision.kind == "ready_to_write"
            stop_reason = decision.reason
            break
        if decision.kind == "write_confirmation" and not writes_enabled:
            ready_to_write = True
            stop_reason = "live_write_not_enabled"
            break
        message_id = f"{external_conversation_id}-{step:02d}-{uuid.uuid4().hex[:12]}"
        payload = build_payload(scenario, external_conversation_id, message_id, decision.message)
        try:
            status, _raw, response = post_turn(api_url, token, payload, timeout)
        except Exception as exc:
            immediate_findings.append(
                finding(
                    "critical",
                    "turn_unverifiable_after_request",
                    f"Agent request did not yield verifiable structured evidence: {exc.__class__.__name__}: {exc}",
                    step,
                )
            )
            stop_reason = "request_failed"
            break
        artifacts, artifact_status, artifact_refs = read_artifacts(
            response, context_dir, container_context_prefix, message_id
        )
        turn = normalize_turn(
            step, decision.message, decision, status, response, artifacts, artifact_status, artifact_refs
        )
        metadata["internal_conversation_id"] = (response.get("data_to_save") or {}).get("conversation_id")
        safety = evaluator.safety_findings(scenario, turn, write_enabled=writes_enabled)
        turn["findings"] = safety
        turns.append(turn)
        if status < 200 or status >= 300:
            immediate_findings.append(
                finding("error", "unexpected_http_status", f"Agent returned HTTP {status}.", step, artifact_refs)
            )
            stop_reason = "http_error"
            break
        if any(item["severity"] == "critical" for item in safety):
            immediate_findings.extend(safety)
            stop_reason = "critical_safety_failure"
            break
        write_calls = [
            call
            for candidate in turns
            for call in candidate.get("executed_mcp_calls", [])
            if call.get("tool_name") in WRITE_TOOLS
        ]
        if writes_enabled and len(write_calls) > 1:
            immediate_findings.append(
                finding(
                    "critical",
                    "multiple_writes",
                    f"Live scenario executed {len(write_calls)} MCP writes; aborted immediately.",
                    step,
                    [ref for call in write_calls for ref in call.get("evidence_refs", [])],
                )
            )
            stop_reason = "critical_safety_failure"
            break
        decision = simulator.decide(scenario, turns, allow_writes=writes_enabled)
        if decision.kind in {"write_confirmation", "live_write_not_enabled"}:
            ready_to_write = True
        if decision.kind == "live_write_not_enabled":
            stop_reason = decision.reason
            break
        if decision.kind == "ready_to_write":
            ready_to_write = True
            stop_reason = decision.reason
            break
        if decision.kind == "completed":
            stop_reason = decision.reason
            break
        if decision.kind == "warn":
            stop_reason = (
                "missing_precondition"
                if determine_missing_precondition(scenario, turns, evaluator)
                else decision.reason
            )
            break
    else:
        stop_reason = "max_turns"

    if not ready_to_write and stop_reason == "simulator_inconclusive" and determine_missing_precondition(scenario, turns, evaluator):
        stop_reason = "missing_precondition"

    evaluation = evaluator.evaluate(
        scenario,
        turns,
        ready_to_write,
        stop_reason,
        immediate_findings,
        write_enabled=writes_enabled,
    )
    transcript = {"metadata": metadata, "scenario": scenario, "turns": turns}
    evaluation_document = {"metadata": metadata, **evaluation}
    (output_dir / "transcript.json").write_text(
        json.dumps(transcript, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (output_dir / "evaluation.json").write_text(
        json.dumps(evaluation_document, ensure_ascii=False, indent=2), encoding="utf-8"
    )
    (output_dir / "report.md").write_text(
        render_scenario_report(metadata, evaluation, turns, output_dir), encoding="utf-8"
    )
    return evaluation_document, output_dir


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run one autonomous Sales Agent E2E scenario in dry-run mode.")
    parser.add_argument("--scenario", required=True, help="booking, reschedule, cancel, or a scenario JSON path")
    parser.add_argument("--conversation-id", help="Explicit external conversation ID")
    parser.add_argument("--max-turns", type=int, help="Override scenario max_turns")
    parser.add_argument("--output-dir", type=Path, help="Scenario result directory")
    parser.add_argument("--api-url", help="Agent respond URL (default: SA_E2E_API_URL or localhost API_PORT)")
    parser.add_argument("--context-dir", type=Path, help="Host context artifact directory")
    parser.add_argument("--timeout", type=float, default=180.0, help="HTTP timeout per turn")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    evaluation, output_dir = run_scenario(
        scenario_name=args.scenario,
        output_dir=args.output_dir,
        conversation_id=args.conversation_id,
        max_turns_override=args.max_turns,
        api_url=args.api_url,
        context_dir=args.context_dir,
        timeout=args.timeout,
    )
    print(f"SCENARIO: {evaluation['scenario']}")
    print(f"RESULT: {evaluation['result']}")
    print(f"READY_TO_WRITE: {str(evaluation['ready_to_write']).lower()}")
    print(f"WRITES_EXECUTED: {evaluation['writes_executed']}")
    print(f"OUTPUT_DIR: {output_dir}")
    return 1 if evaluation["result"] == "FAIL" else 0


if __name__ == "__main__":
    sys.exit(main())
