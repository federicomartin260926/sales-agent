from __future__ import annotations

from typing import Any

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, field_validator


class LLMToolTrace(BaseModel):
    model_config = ConfigDict(extra="ignore")

    type: str | None = None
    server_label: str | None = Field(default=None, validation_alias=AliasChoices("server_label", "serverLabel"))
    tool_name: str | None = Field(default=None, validation_alias=AliasChoices("tool_name", "toolName", "name"))
    arguments: dict[str, Any] = Field(default_factory=dict)
    output: Any | None = None
    status: str | None = None
    raw: dict[str, Any] = Field(default_factory=dict)

    @field_validator("arguments", mode="before")
    @classmethod
    def normalize_arguments(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        return {}

    @field_validator("raw", mode="before")
    @classmethod
    def normalize_raw(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        return {}


class LLMUsage(BaseModel):
    model_config = ConfigDict(extra="ignore")

    provider: str | None = None
    model: str | None = None
    input_tokens: int | None = None
    output_tokens: int | None = None
    cached_tokens: int | None = None
    total_tokens: int | None = None
    prompt_tokens: int | None = None
    completion_tokens: int | None = None
    estimated_cost: float | None = None


class McpToolTrace(LLMToolTrace):
    pass


class McpRemoteConfig(BaseModel):
    model_config = ConfigDict(extra="ignore")

    enabled: bool = False
    server_label: str | None = Field(default=None, validation_alias=AliasChoices("server_label", "serverLabel"))
    server_url: str | None = Field(default=None, validation_alias=AliasChoices("server_url", "serverUrl", "webhook_url", "webhookUrl"))
    auth_type: str | None = Field(default=None, validation_alias=AliasChoices("auth_type", "authType"))
    bearer_token: str | None = Field(default=None, validation_alias=AliasChoices("bearer_token", "bearerToken"))
    allowed_tools: list[str] = Field(default_factory=list, validation_alias=AliasChoices("allowed_tools", "allowedTools"))
    require_approval: str | None = Field(default=None, validation_alias=AliasChoices("require_approval", "requireApproval"))
    timeout_seconds: int | None = Field(default=None, validation_alias=AliasChoices("timeout_seconds", "timeoutSeconds"))
    config: dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    type: str | None = None
    tool_id: str | None = Field(default=None, validation_alias=AliasChoices("tool_id", "toolId"))
    tenant_id: str | None = Field(default=None, validation_alias=AliasChoices("tenant_id", "tenantId"))
    error_code: str | None = Field(default=None, validation_alias=AliasChoices("error_code", "errorCode"))
    error_message: str | None = Field(default=None, validation_alias=AliasChoices("error_message", "errorMessage"))

    @field_validator("allowed_tools", mode="before")
    @classmethod
    def normalize_allowed_tools(cls, value: Any) -> list[str]:
        if not isinstance(value, list):
            return []

        tools: list[str] = []
        for item in value:
            if isinstance(item, str) and item.strip() != "":
                tools.append(item.strip())

        return list(dict.fromkeys(tools))

    @field_validator("config", mode="before")
    @classmethod
    def normalize_config(cls, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        return {}


class BackendAiUsagePolicy(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant_id: str | None = Field(default=None, validation_alias=AliasChoices("tenant_id", "tenantId"))
    exists: bool = False
    ai_enabled: bool = Field(default=True, validation_alias=AliasChoices("ai_enabled", "aiEnabled"))
    monthly_cost_limit_eur: float | None = Field(default=None, validation_alias=AliasChoices("monthly_cost_limit_eur", "monthlyCostLimitEur"))
    daily_cost_limit_eur: float | None = Field(default=None, validation_alias=AliasChoices("daily_cost_limit_eur", "dailyCostLimitEur"))
    default_model: str | None = Field(default=None, validation_alias=AliasChoices("default_model", "defaultModel"))
    fallback_model: str | None = Field(default=None, validation_alias=AliasChoices("fallback_model", "fallbackModel"))
    limit_action: str = Field(default="handoff_human", validation_alias=AliasChoices("limit_action", "limitAction"))
    created_at: str | None = Field(default=None, validation_alias=AliasChoices("created_at", "createdAt"))
    updated_at: str | None = Field(default=None, validation_alias=AliasChoices("updated_at", "updatedAt"))


class BackendAiUsageWindow(BaseModel):
    model_config = ConfigDict(extra="ignore")

    estimated_cost_eur: float = 0.0
    input_tokens: int = 0
    output_tokens: int = 0
    cached_tokens: int = 0
    total_tokens: int = 0


class BackendAiUsageSnapshot(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant_id: str | None = Field(default=None, validation_alias=AliasChoices("tenant_id", "tenantId"))
    daily: BackendAiUsageWindow = Field(default_factory=BackendAiUsageWindow)
    monthly: BackendAiUsageWindow = Field(default_factory=BackendAiUsageWindow)


class BackendAiUsageEventPayload(BaseModel):
    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    tenant_id: str = Field(alias="tenant_id")
    conversation_id: str | None = Field(default=None, alias="conversation_id")
    conversation_message_id: str | None = Field(default=None, alias="conversation_message_id")
    provider: str | None = Field(default=None, alias="provider")
    model: str | None = Field(default=None, alias="model")
    response_id: str | None = Field(default=None, alias="response_id")
    input_tokens: int | None = Field(default=None, alias="input_tokens")
    output_tokens: int | None = Field(default=None, alias="output_tokens")
    cached_tokens: int | None = Field(default=None, alias="cached_tokens")
    total_tokens: int | None = Field(default=None, alias="total_tokens")
    estimated_cost: float | None = Field(default=None, alias="estimated_cost")
    latency_ms: int | None = Field(default=None, alias="latency_ms")


class LLMResponseResult(BaseModel):
    model_config = ConfigDict(extra="ignore")

    provider: str
    model: str | None = None
    content: str
    response_id: str | None = None
    usage: LLMUsage | None = None
    estimated_cost: float | None = None
    raw_payload: Any | None = None
    tool_traces: list[LLMToolTrace] = Field(default_factory=list)
