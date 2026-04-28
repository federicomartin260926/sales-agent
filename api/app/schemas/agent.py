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

    tenant_id: str = Field(min_length=1)
    message: Message
    contact: Contact
    conversation: Conversation = Field(default_factory=Conversation)

    @model_validator(mode="before")
    @classmethod
    def normalize_agent_payload(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        tenant_id = normalized.get("tenant_id")
        if isinstance(tenant_id, str):
            normalized["tenant_id"] = tenant_id.strip()

        message = normalized.get("message")
        if isinstance(message, (dict, str)):
            normalized["message"] = message

        contact = normalized.get("contact")
        if isinstance(contact, dict):
            normalized["contact"] = dict(contact)

        return normalized


class AgentResponse(BaseModel):
    model_config = ConfigDict(extra="ignore")

    reply: str = Field(min_length=1)
    intent: str = Field(min_length=1)
    score: float = Field(ge=0, le=1)
    action: str = Field(min_length=1)
    needs_human: bool
    data_to_save: dict[str, Any] = Field(default_factory=dict)
