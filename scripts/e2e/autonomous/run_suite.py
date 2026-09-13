from __future__ import annotations

import argparse
import json
import sys
import traceback
from collections import Counter
from pathlib import Path
from typing import Any

from run_scenario import DEFAULT_RESULTS_DIR, make_run_id, run_scenario, utc_now


SCENARIOS = ("booking", "reschedule", "cancel")


def render_suite_report(report: dict[str, Any]) -> str:
    summary = report["summary"]
    lines = [
        "# Sales Agent autonomous E2E suite",
        "",
        f"SCENARIOS: {summary['SCENARIOS']}",
        f"PASS: {summary['PASS']}",
        f"WARN: {summary['WARN']}",
        f"FAIL: {summary['FAIL']}",
        "",
        "## Scenarios",
        "",
    ]
    for item in report["scenarios"]:
        lines.extend(
            [
                f"### {item['scenario']}",
                "",
                f"- Result: {item['result']}",
                f"- Ready to write: {str(item['ready_to_write']).lower()}",
                f"- Turns: {item['turns']}",
                f"- Writes executed: {item['writes_executed']}",
                f"- Report: `{item['report']}`",
                "- Findings:",
            ]
        )
        if item["findings"]:
            for finding in item["findings"]:
                lines.append(f"  - [{finding['severity'].upper()}] `{finding['code']}`: {finding['message']}")
                lines.append(f"    - Recommendation: {finding['recommendation']}")
        else:
            lines.append("  - None")
        technical_error = item.get("technical_error")
        if isinstance(technical_error, dict):
            lines.extend(
                [
                    "- Technical error:",
                    f"  - Type: `{technical_error.get('type')}`",
                    f"  - Message: {technical_error.get('message')}",
                    "  - Traceback:",
                    "",
                    "```text",
                    str(technical_error.get("traceback", "")).rstrip(),
                    "```",
                ]
            )
        lines.append("")
    return "\n".join(lines)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Run booking, reschedule and cancel sequentially in dry-run mode. "
            "Exit codes: 0=no FAIL, 1=functional/technical scenario FAIL, 2=global runner failure."
        )
    )
    parser.add_argument("--output-dir", type=Path, help="Suite result directory")
    parser.add_argument("--api-url", help="Agent respond URL")
    parser.add_argument("--context-dir", type=Path, help="Host context artifact directory")
    parser.add_argument("--timeout", type=float, default=180.0, help="HTTP timeout per turn")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    run_id = make_run_id("suite")
    suite_dir = args.output_dir or DEFAULT_RESULTS_DIR / run_id
    try:
        suite_dir.mkdir(parents=True, exist_ok=True)
    except Exception as exc:
        print(f"Global runner failure: {exc.__class__.__name__}: {exc}", file=sys.stderr)
        return 2
    results: list[dict[str, Any]] = []
    for scenario in SCENARIOS:
        scenario_dir = suite_dir / scenario
        try:
            evaluation, scenario_dir = run_scenario(
                scenario_name=scenario,
                output_dir=scenario_dir,
                api_url=args.api_url,
                context_dir=args.context_dir,
                timeout=args.timeout,
            )
            result = {
                "scenario": scenario,
                "result": evaluation["result"],
                "ready_to_write": evaluation["ready_to_write"],
                "turns": evaluation["turns"],
                "writes_executed": evaluation["writes_executed"],
                "tools_executed": evaluation["tools_executed"],
                "findings": evaluation["findings"],
                "conversation_id": evaluation["metadata"]["external_conversation_id"],
                "report": str(scenario_dir / "report.md"),
                "technical_error": None,
            }
        except Exception as exc:
            trace = "".join(traceback.format_exception(exc, limit=8))
            technical_error = {
                "type": exc.__class__.__name__,
                "message": str(exc),
                "traceback": trace,
            }
            result = {
                "scenario": scenario,
                "result": "FAIL",
                "ready_to_write": False,
                "turns": 0,
                "writes_executed": None,
                "tools_executed": {},
                "findings": [
                    {
                        "severity": "critical",
                        "code": "scenario_technical_failure",
                        "message": f"{technical_error['type']}: {technical_error['message']}",
                        "step": None,
                        "evidence_refs": [],
                        "recommendation": "Inspect the recorded traceback and restore the scenario runner.",
                    }
                ],
                "conversation_id": None,
                "report": None,
                "technical_error": technical_error,
            }
        results.append(result)
        print(
            f"{scenario}: {result['result']} turns={result['turns']} "
            f"ready_to_write={str(result['ready_to_write']).lower()} "
            f"writes={result['writes_executed']}"
        )

    counts = Counter(item["result"] for item in results)
    report = {
        "run_id": run_id,
        "timestamp": utc_now(),
        "dry_run": True,
        "summary": {
            "SCENARIOS": len(results),
            "PASS": counts["PASS"],
            "WARN": counts["WARN"],
            "FAIL": counts["FAIL"],
        },
        "scenarios": results,
    }
    try:
        (suite_dir / "suite-report.json").write_text(
            json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        (suite_dir / "suite-report.md").write_text(render_suite_report(report), encoding="utf-8")
    except Exception as exc:
        print(f"Global report failure: {exc.__class__.__name__}: {exc}", file=sys.stderr)
        return 2
    print(f"SCENARIOS: {len(results)}")
    print(f"PASS: {counts['PASS']}")
    print(f"WARN: {counts['WARN']}")
    print(f"FAIL: {counts['FAIL']}")
    print(f"OUTPUT_DIR: {suite_dir}")
    return 1 if counts["FAIL"] else 0


if __name__ == "__main__":
    sys.exit(main())
