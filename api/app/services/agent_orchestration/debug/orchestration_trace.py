from __future__ import annotations

from typing import Any
from uuid import uuid4

from pydantic import BaseModel, ConfigDict, Field

from app.services.agent_orchestration.debug.trace_sanitizer import sanitize_value


class OrchestrationStep(BaseModel):
    model_config = ConfigDict(extra="forbid")

    step_type: str
    input_context_keys: list[str] = Field(default_factory=list)
    enabled_tools: list[str] = Field(default_factory=list)
    output: Any | None = None
    latency_ms: int | None = None
    error: str | None = None


class OrchestrationTrace(BaseModel):
    model_config = ConfigDict(extra="forbid")

    trace_id: str = Field(default_factory=lambda: str(uuid4()))
    tenant_id: str | None = None
    conversation_id: str | None = None
    external_conversation_id: str | None = None
    inbound_message: str | None = None
    steps: list[OrchestrationStep] = Field(default_factory=list)

    def add_step(
        self,
        *,
        step_type: str,
        input_context_keys: list[str] | None = None,
        enabled_tools: list[str] | None = None,
        output: Any | None = None,
        latency_ms: int | None = None,
        error: str | None = None,
    ) -> OrchestrationStep:
        step = OrchestrationStep(
            step_type=step_type,
            input_context_keys=list(dict.fromkeys(input_context_keys or [])),
            enabled_tools=list(dict.fromkeys(enabled_tools or [])),
            output=output,
            latency_ms=latency_ms,
            error=error,
        )
        self.steps.append(step)
        return step

    def to_safe_dict(self) -> dict[str, Any]:
        data = self.model_dump()
        data["inbound_message"] = sanitize_value(data.get("inbound_message"))
        data["steps"] = [sanitize_value(step) for step in data.get("steps", [])]
        return sanitize_value(data)
