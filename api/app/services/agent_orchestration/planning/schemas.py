from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, field_validator


Domain = Literal["general", "sales", "catalog", "inventory", "appointment", "crm", "support", "handoff"]
Intent = Literal[
    "unknown",
    "small_talk",
    "ask_business_question",
    "ask_product_or_service_info",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "request_availability",
    "select_offered_slot",
    "request_booking_confirmation",
    "request_reschedule",
    "request_cancel",
    "provide_contact_data",
    "request_quote",
    "request_handoff",
    "complaint_or_problem",
    "support_question",
]
ActionCandidate = Literal[
    "no_action",
    "answer_directly",
    "search_catalog",
    "search_inventory",
    "search_similar_items",
    "get_availability",
    "prepare_booking_confirmation",
    "prepare_reschedule",
    "prepare_cancel",
    "collect_missing_data",
    "ask_clarification",
    "handoff_to_human",
    "create_or_update_crm_contact",
]

LOOKUP_TOOL_NAMES = {
    "contact_context",
    "services_search",
    "appointment_availability",
    "appointment_events",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "knowledge_search",
}

WRITE_TOOL_NAMES = {
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "crm_contact_submit",
    "lead_create",
    "handoff_request",
}


class PlanningEntities(BaseModel):
    model_config = ConfigDict(extra="forbid")

    service_id: str | None = None
    service_name: str | None = None
    owner_id: str | None = None
    owner_name: str | None = None
    appointment_id: str | None = None
    contact_name: str | None = None
    contact_phone: str | None = None
    contact_email: str | None = None
    date: str | None = None
    time: str | None = None
    time_of_day: Literal["morning", "afternoon", "evening", "night", "any"] | None = None
    selected_slot_index: int | None = None
    slot_reference: Literal["first", "last", "exact_time"] | None = None
    date_from: str | None = None
    date_to: str | None = None
    query: str | None = None
    notes: str | None = None


class ContextRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    include_conversation_history: bool = True
    conversation_history_level: Literal["recent", "full", "none"] = "recent"
    include_customer_context: Literal["basic", "full", "none"] = "basic"
    include_catalog_context: bool = False
    include_inventory_context: bool = False
    include_appointment_context: bool = False
    include_existing_appointments: bool = False
    include_offered_slots: bool = False
    include_service_catalog: bool = False


class ToolRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    lookup_tools: list[str] = Field(default_factory=list)
    write_tools: list[str] = Field(default_factory=list)
    blocked_tools: list[str] = Field(default_factory=list)
    reason: str | None = None

    @field_validator("lookup_tools", "write_tools", "blocked_tools", mode="before")
    @classmethod
    def _normalize_tool_list(cls, value):
        if not isinstance(value, list):
            return []

        normalized: list[str] = []
        for item in value:
            if isinstance(item, str):
                candidate = item.strip()
                if candidate:
                    normalized.append(candidate)

        return list(dict.fromkeys(normalized))


class RiskFlags(BaseModel):
    model_config = ConfigDict(extra="forbid")

    ambiguous_reference: bool = False
    missing_required_data: bool = False
    low_confidence: bool = False
    needs_human_review: bool = False
    explicit_booking_intent: bool = False
    explicit_reschedule_intent: bool = False
    explicit_cancel_intent: bool = False


class ClarificationRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    needed: bool = False
    question: str | None = None
    missing_fields: list[str] = Field(default_factory=list)

    @field_validator("missing_fields", mode="before")
    @classmethod
    def _normalize_missing_fields(cls, value):
        if not isinstance(value, list):
            return []

        normalized: list[str] = []
        for item in value:
            if isinstance(item, str):
                candidate = item.strip()
                if candidate:
                    normalized.append(candidate)

        return list(dict.fromkeys(normalized))


class LLMPlanningResult(BaseModel):
    model_config = ConfigDict(extra="forbid")

    schema_version: Literal["1.0"] = "1.0"
    domain: Domain = "general"
    intent: Intent = "unknown"
    action_candidate: ActionCandidate = "no_action"
    confidence: float = Field(default=0.0, ge=0.0, le=1.0)
    entities: PlanningEntities = Field(default_factory=PlanningEntities)
    context_request: ContextRequest = Field(default_factory=ContextRequest)
    tool_request: ToolRequest = Field(default_factory=ToolRequest)
    risk_flags: RiskFlags = Field(default_factory=RiskFlags)
    clarification: ClarificationRequest = Field(default_factory=ClarificationRequest)
    reason: str = ""

    @field_validator("reason", mode="before")
    @classmethod
    def _normalize_reason(cls, value):
        if not isinstance(value, str):
            return ""

        return value.strip()
