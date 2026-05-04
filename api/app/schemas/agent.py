from typing import Any

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, model_validator


class Contact(BaseModel):
    model_config = ConfigDict(populate_by_name=True, extra="ignore")

    phone: str = Field(validation_alias=AliasChoices("phone", "wa_id", "from"), min_length=1)
    name: str | None = None

    @model_validator(mode="before")
    @classmethod
    def normalize_contact_payload(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        if "phone" not in normalized:
            normalized["phone"] = normalized.get("wa_id") or normalized.get("from")

        profile = normalized.get("profile")
        if normalized.get("name") is None and isinstance(profile, dict):
            normalized["name"] = profile.get("name")

        return normalized

    @model_validator(mode="after")
    def normalize_contact_values(self) -> "Contact":
        self.phone = self.phone.strip()
        if self.name is not None:
            self.name = self.name.strip() or None

        return self


class Message(BaseModel):
    model_config = ConfigDict(populate_by_name=True, extra="ignore")

    id: str | None = None
    type: str = "text"
    text: str = Field(validation_alias=AliasChoices("text", "body"), min_length=1)
    timestamp: str | None = None

    @model_validator(mode="before")
    @classmethod
    def normalize_message_payload(cls, data: Any) -> Any:
        if isinstance(data, str):
            return {"text": data}

        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        if "text" not in normalized:
            normalized["text"] = normalized.get("body") or ""

        if "type" not in normalized:
            normalized["type"] = "text"

        return normalized

    @model_validator(mode="after")
    def normalize_message_values(self) -> "Message":
        self.text = self.text.strip()
        self.type = self.type.strip() or "text"
        if self.id is not None:
            self.id = self.id.strip() or None
        if self.timestamp is not None:
            self.timestamp = self.timestamp.strip() or None

        return self


class Conversation(BaseModel):
    model_config = ConfigDict(extra="ignore")

    last_messages: list[str] = Field(default_factory=list)

    @model_validator(mode="after")
    def normalize_conversation_values(self) -> "Conversation":
        self.last_messages = [message.strip() for message in self.last_messages if isinstance(message, str) and message.strip() != ""]
        return self


class AgentRequest(BaseModel):
    model_config = ConfigDict(extra="ignore")

    tenant_id: str | None = Field(default=None, validation_alias=AliasChoices("tenant_id", "tenantId"))
    channel_type: str | None = Field(default=None, validation_alias=AliasChoices("channel_type", "channel"))
    external_channel_id: str | None = Field(default=None, validation_alias=AliasChoices("external_channel_id", "phone_number_id"))
    entrypoint_ref: str | None = Field(default=None, validation_alias=AliasChoices("entrypoint_ref", "entrypointRef", "ref"))
    message: Message
    contact: Contact
    conversation: Conversation = Field(default_factory=Conversation)
    raw_event: Any | None = Field(default=None, alias="raw_event")

    @model_validator(mode="before")
    @classmethod
    def normalize_agent_payload(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        tenant_id = normalized.get("tenant_id")
        if isinstance(tenant_id, str):
            normalized["tenant_id"] = tenant_id.strip()
        elif normalized.get("tenantId") is not None and isinstance(normalized.get("tenantId"), str):
            normalized["tenantId"] = normalized["tenantId"].strip()

        channel_type = normalized.get("channel_type")
        if isinstance(channel_type, str):
            normalized["channel_type"] = channel_type.strip()
        elif isinstance(normalized.get("channel"), str):
            normalized["channel"] = normalized["channel"].strip()

        external_channel_id = normalized.get("external_channel_id")
        if isinstance(external_channel_id, str):
            normalized["external_channel_id"] = external_channel_id.strip()
        elif isinstance(normalized.get("phone_number_id"), str):
            normalized["phone_number_id"] = normalized["phone_number_id"].strip()

        entrypoint_ref = normalized.get("entrypoint_ref")
        if isinstance(entrypoint_ref, str):
            normalized["entrypoint_ref"] = entrypoint_ref.strip()
        elif isinstance(normalized.get("entrypointRef"), str):
            normalized["entrypointRef"] = normalized["entrypointRef"].strip()

        message = normalized.get("message")
        if isinstance(message, (dict, str)):
            normalized["message"] = message

        contact = normalized.get("contact")
        if isinstance(contact, dict):
            normalized["contact"] = dict(contact)

        return normalized


class AgentResponse(BaseModel):
    model_config = ConfigDict(extra="ignore")

    reply: str = ""
    intent: str = Field(min_length=1)
    score: float = Field(ge=0, le=1)
    action: str = Field(min_length=1)
    needs_human: bool
    data_to_save: dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    model: str | None = None
    latency_ms: int | None = None
