from typing import Any

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, model_validator


class Contact(BaseModel):
    model_config = ConfigDict(populate_by_name=True)

    phone: str = Field(validation_alias=AliasChoices("phone", "wa_id", "from"))
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


class Conversation(BaseModel):
    last_messages: list[str] = Field(default_factory=list)


class AgentRequest(BaseModel):
    tenant_id: str
    message: str
    contact: Contact
    conversation: Conversation = Field(default_factory=Conversation)

    @model_validator(mode="before")
    @classmethod
    def normalize_agent_payload(cls, data: Any) -> Any:
        if not isinstance(data, dict):
            return data

        normalized = dict(data)
        message = normalized.get("message")
        if isinstance(message, dict):
            normalized["message"] = message.get("text") or message.get("body") or ""

        contact = normalized.get("contact")
        if isinstance(contact, dict):
            normalized["contact"] = dict(contact)

        return normalized


class AgentResponse(BaseModel):
    reply: str
    intent: str
    score: float
    action: str
    needs_human: bool
    data_to_save: dict[str, Any] = Field(default_factory=dict)
