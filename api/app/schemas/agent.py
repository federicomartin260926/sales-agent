from typing import Any

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, model_validator


class Contact(BaseModel):
    model_config = ConfigDict(populate_by_name=True, extra="ignore")

    phone: str = Field(validation_alias=AliasChoices("phone", "wa_id", "from"), min_length=1)
    name: str | None = None
    email: str | None = None

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

        if normalized.get("email") is None and isinstance(profile, dict):
            normalized["email"] = profile.get("email")

        return normalized

    @model_validator(mode="after")
    def normalize_contact_values(self) -> "Contact":
        self.phone = self.phone.strip()
        if self.name is not None:
            self.name = self.name.strip() or None
        if self.email is not None:
            self.email = self.email.strip() or None

        return self


class Message(BaseModel):
    model_config = ConfigDict(populate_by_name=True, extra="ignore")

    id: str | None = None
    type: str = "text"
    text: str | None = Field(default=None, validation_alias=AliasChoices("text", "body"))
    timestamp: str | None = None
    media: dict[str, Any] | None = None

    @model_validator(mode="before")
    @classmethod
    def normalize_message_payload(cls, data: Any) -> Any:
        if isinstance(data, str):
            return {"type": "text", "text": data}

        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        if "type" not in normalized or not isinstance(normalized.get("type"), str) or normalized.get("type", "").strip() == "":
            normalized["type"] = "audio" if isinstance(normalized.get("media"), dict) else "text"

        if "text" not in normalized and isinstance(normalized.get("body"), str):
            normalized["text"] = normalized["body"]

        media = normalized.get("media")
        if isinstance(media, dict):
            normalized["media"] = dict(media)

        return normalized

    @model_validator(mode="after")
    def normalize_message_values(self) -> "Message":
        self.type = self.type.strip() or "text"
        if self.text is not None:
            self.text = self.text.strip()
            if self.text == "":
                self.text = None
        if self.id is not None:
            self.id = self.id.strip() or None
        if self.timestamp is not None:
            self.timestamp = self.timestamp.strip() or None

        return self


class Conversation(BaseModel):
    model_config = ConfigDict(extra="ignore")

    external_id: str | None = Field(default=None, validation_alias=AliasChoices("external_id", "externalId"))
    channel: str | None = Field(default=None, validation_alias=AliasChoices("channel", "channel_type"))
    summary: str | None = None
    last_messages: list[str] = Field(default_factory=list)
    context_messages: list[dict[str, Any]] = Field(default_factory=list)

    @model_validator(mode="before")
    @classmethod
    def normalize_conversation_payload(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        external_id = normalized.get("external_id")
        if isinstance(external_id, str):
            normalized["external_id"] = external_id.strip()
        elif isinstance(normalized.get("externalId"), str):
            normalized["externalId"] = normalized["externalId"].strip()

        channel = normalized.get("channel")
        if isinstance(channel, str):
            normalized["channel"] = channel.strip()
        elif isinstance(normalized.get("channel_type"), str):
            normalized["channel_type"] = normalized["channel_type"].strip()

        summary = normalized.get("summary")
        if isinstance(summary, str):
            normalized["summary"] = summary.strip()

        return normalized

    @model_validator(mode="after")
    def normalize_conversation_values(self) -> "Conversation":
        if self.external_id is not None:
            self.external_id = self.external_id.strip() or None
        if self.channel is not None:
            self.channel = self.channel.strip() or None
        if self.summary is not None:
            self.summary = self.summary.strip() or None
        self.last_messages = [message.strip() for message in self.last_messages if isinstance(message, str) and message.strip() != ""]
        self.context_messages = [message for message in self.context_messages if isinstance(message, dict)]
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
