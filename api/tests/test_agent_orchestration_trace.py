from app.services.agent_orchestration.debug.orchestration_trace import OrchestrationTrace
from app.services.agent_orchestration.debug.trace_sanitizer import sanitize_value


def test_trace_sanitizer_redacts_sensitive_fields():
    payload = {
        "Authorization": "Bearer secret",
        "bearer_token": "abc123",
        "downstream_authorization": "downstream-secret",
        "nested": {
            "password": "secret-pass",
            "token": "token-value",
            "safe": "ok",
        },
    }

    sanitized = sanitize_value(payload)

    assert sanitized["Authorization"] == "***REDACTED***"
    assert sanitized["bearer_token"] == "***REDACTED***"
    assert sanitized["downstream_authorization"] == "***REDACTED***"
    assert sanitized["nested"]["password"] == "***REDACTED***"
    assert sanitized["nested"]["token"] == "***REDACTED***"
    assert sanitized["nested"]["safe"] == "ok"


def test_orchestration_trace_adds_sanitized_steps():
    trace = OrchestrationTrace(
        tenant_id="tenant-1",
        conversation_id="conversation-1",
        external_conversation_id="ext-1",
        inbound_message="Hola",
    )

    trace.add_step(
        step_type="planning",
        input_context_keys=["conversation", "conversation", "contact"],
        enabled_tools=["appointment_availability"],
        output={
            "Authorization": "Bearer secret",
            "ok": True,
        },
        latency_ms=42,
    )

    safe = trace.to_safe_dict()

    assert safe["trace_id"]
    assert safe["steps"][0]["step_type"] == "planning"
    assert safe["steps"][0]["input_context_keys"] == ["conversation", "contact"]
    assert safe["steps"][0]["enabled_tools"] == ["appointment_availability"]
    assert safe["steps"][0]["output"]["Authorization"] == "***REDACTED***"
    assert safe["steps"][0]["latency_ms"] == 42
