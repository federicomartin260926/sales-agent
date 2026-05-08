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


class LLMResponseResult(BaseModel):
    model_config = ConfigDict(extra="ignore")

    provider: str
    model: str | None = None
    content: str
    response_id: str | None = None
    raw_payload: Any | None = None
    tool_traces: list[LLMToolTrace] = Field(default_factory=list)
