from __future__ import annotations

from typing import Any, Literal

from pydantic import AliasChoices, BaseModel, ConfigDict, Field, field_validator


# =============================================================================
# Closed vocabulary for the LLM planner
# =============================================================================
#
# The first LLM call must classify the human message into this controlled
# contract. SA should not infer free-text intent by heuristics. SA uses these
# values to decide which context to prepare and which tools to expose next.
#
# Keep this list explicit and conservative. Adding new values is a product/API
# decision because runtime code and tool policies may depend on them.


Domain = Literal[
    "general",
    "sales",
    "catalog",
    "inventory",
    "appointment",
    "crm",
    "support",
    "handoff",
]

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
    "select_existing_appointment",
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

# =============================================================================
# Tool vocabulary
# =============================================================================
#
# Read tools can be exposed often when they help the LLM reason.
# Write/action tools must only be exposed when the classified intent allows them.


LookupToolName = Literal[
    "contact_context",
    "services_search",
    "appointment_availability",
    "appointment_events",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "knowledge_search",
]

WriteToolName = Literal[
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "crm_contact_submit",
    "lead_create",
    "handoff_request",
]

LOOKUP_TOOL_NAMES: set[str] = {
    "contact_context",
    "services_search",
    "appointment_availability",
    "appointment_events",
    "catalog_search",
    "inventory_search",
    "inventory_similarity_search",
    "knowledge_search",
}

WRITE_TOOL_NAMES: set[str] = {
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "crm_contact_submit",
    "lead_create",
    "handoff_request",
}


# =============================================================================
# Planner schema: first LLM call
# =============================================================================
#
# The planner does not execute tools. It classifies the current human message,
# extracts structured entities, and tells SA what context/tools will be useful
# for the second LLM call.
#
# Important architectural rule:
# - The LLM interprets human language.
# - SA uses this structured result to build context/tools.
# - SA must not resolve slot selection, dates, owners or natural-language
#   meaning with ad-hoc parsing.


class PlanningEntities(BaseModel):
    model_config = ConfigDict(extra="forbid")

    service_id: str | None = None
    service_name: str | None = None
    service_ref: str | None = None

    owner_id: str | None = None
    owner_name: str | None = None
    owner_ref: str | None = None

    appointment_id: str | None = None

    contact_name: str | None = None
    contact_phone: str | None = None
    contact_email: str | None = None

    date: str | None = None
    time: str | None = None
    time_of_day: Literal["morning", "afternoon", "evening", "night", "any"] | None = None
    date_from: str | None = None
    date_to: str | None = None

    selected_slot_index: int | None = None
    slot_reference: Literal["first", "last", "exact_time", "relative_time", "other"] | None = None

    query: str | None = None
    notes: str | None = None

    @field_validator(
        "service_id",
        "service_name",
        "service_ref",
        "owner_id",
        "owner_name",
        "owner_ref",
        "appointment_id",
        "contact_name",
        "contact_phone",
        "contact_email",
        "date",
        "time",
        "date_from",
        "date_to",
        "query",
        "notes",
        mode="before",
    )
    @classmethod
    def _normalize_optional_string(cls, value: Any) -> str | None:
        if value is None:
            return None
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        return normalized if normalized != "" else None


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
    def _normalize_tool_list(cls, value: Any) -> list[str]:
        if not isinstance(value, list):
            return []

        normalized: list[str] = []
        for item in value:
            if isinstance(item, str):
                candidate = item.strip()
                if candidate:
                    normalized.append(candidate)

        return list(dict.fromkeys(normalized))

    @field_validator("lookup_tools")
    @classmethod
    def _keep_known_lookup_tools(cls, value: list[str]) -> list[str]:
        return [tool for tool in value if tool in LOOKUP_TOOL_NAMES]

    @field_validator("write_tools")
    @classmethod
    def _keep_known_write_tools(cls, value: list[str]) -> list[str]:
        return [tool for tool in value if tool in WRITE_TOOL_NAMES]

    @field_validator("blocked_tools")
    @classmethod
    def _keep_known_blocked_tools(cls, value: list[str]) -> list[str]:
        known_tools = LOOKUP_TOOL_NAMES | WRITE_TOOL_NAMES
        return [tool for tool in value if tool in known_tools]

    @field_validator("reason", mode="before")
    @classmethod
    def _normalize_reason(cls, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        return normalized if normalized != "" else None


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

    @field_validator("question", mode="before")
    @classmethod
    def _normalize_question(cls, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        return normalized if normalized != "" else None

    @field_validator("missing_fields", mode="before")
    @classmethod
    def _normalize_missing_fields(cls, value: Any) -> list[str]:
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
    """Structured result from the first LLM call.

    This is the canonical intent contract.

    SA uses this object to decide:
    - which context blocks to build,
    - which read tools to expose,
    - which write tools, if any, are allowed in the second LLM call.

    The planner must not execute tools and must not invent values outside this
    schema.
    """

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
    def _normalize_reason(cls, value: Any) -> str:
        if not isinstance(value, str):
            return ""

        return value.strip()


# =============================================================================
# Backward-compatible refactor alias
# =============================================================================
#
# The new simplified runtime may refer to IntentPlan. Keep this as an alias-like
# model with an `action` field, while accepting `action_candidate` from planner
# prompts. Runtime code can use either `plan.action` or `plan.action_candidate`.


class IntentPlan(BaseModel):
    """Compact structured intent used by the simplified runtime.

    Prefer LLMPlanningResult for the planner contract. IntentPlan exists as a
    lighter runtime DTO and to keep the refactor code readable.
    """

    model_config = ConfigDict(extra="ignore", populate_by_name=True)

    domain: Domain = "general"
    intent: Intent = "unknown"
    action: ActionCandidate = Field(
        default="no_action",
        validation_alias=AliasChoices("action", "action_candidate"),
    )
    confidence: float = Field(default=0.5, ge=0.0, le=1.0)
    entities: PlanningEntities = Field(default_factory=PlanningEntities)
    needs_tools: bool = False
    reason: str | None = None

    @property
    def action_candidate(self) -> ActionCandidate:
        return self.action

    @classmethod
    def from_planning_result(cls, planning: LLMPlanningResult) -> "IntentPlan":
        return cls(
            domain=planning.domain,
            intent=planning.intent,
            action=planning.action_candidate,
            confidence=planning.confidence,
            entities=planning.entities,
            needs_tools=(
                planning.tool_request.lookup_tools != []
                or planning.tool_request.write_tools != []
                or planning.context_request.include_appointment_context
                or planning.context_request.include_catalog_context
                or planning.context_request.include_inventory_context
            ),
            reason=planning.reason,
        )


# =============================================================================
# Runtime context: SA prepares data, LLM reasons over it
# =============================================================================


class RuntimeContext(BaseModel):
    """Context prepared by SA for the second LLM call.

    This object is deliberately data-oriented. It should contain facts, state,
    previous messages, slots and tools. It must not contain ad-hoc
    interpretations of the human message.

    Example for appointment slot selection:
    - current human message: "Quiero el turno de las 5"
    - appointment.offered_slots: structured list from previous availability
    - tools.allowed_tools: only tools valid for the current phase

    The LLM must select the slot or ask clarification. SA only validates the
    selected slot against this context.
    """

    model_config = ConfigDict(extra="ignore")

    tenant: dict[str, Any] = Field(default_factory=dict)
    entry_point: dict[str, Any] | None = None
    product: dict[str, Any] | None = None
    playbook: dict[str, Any] | None = None

    contact: dict[str, Any] = Field(default_factory=dict)
    conversation: dict[str, Any] = Field(default_factory=dict)

    appointment: dict[str, Any] = Field(default_factory=dict)
    catalog: dict[str, Any] = Field(default_factory=dict)
    crm: dict[str, Any] = Field(default_factory=dict)

    timezone: str | None = None
    timezone_source: str | None = None

    tools: dict[str, Any] = Field(default_factory=dict)


class ToolPlan(BaseModel):
    """Tools allowed for the second LLM call.

    Read tools may be exposed broadly.
    Write tools must be constrained by the classified intent.
    """

    model_config = ConfigDict(extra="ignore")

    allowed_tools: list[str] = Field(default_factory=list)
    read_tools: list[str] = Field(default_factory=list)
    write_tools: list[str] = Field(default_factory=list)
    reason: str | None = None

    @field_validator("allowed_tools", "read_tools", "write_tools", mode="before")
    @classmethod
    def _normalize_tool_list(cls, value: Any) -> list[str]:
        if not isinstance(value, list):
            return []

        normalized: list[str] = []
        known_tools = LOOKUP_TOOL_NAMES | WRITE_TOOL_NAMES
        for item in value:
            if isinstance(item, str):
                candidate = item.strip()
                if candidate and candidate in known_tools:
                    normalized.append(candidate)

        return list(dict.fromkeys(normalized))

    @field_validator("reason", mode="before")
    @classmethod
    def _normalize_reason(cls, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        return normalized if normalized != "" else None


# =============================================================================
# Second LLM call / final response contract
# =============================================================================
#
# This is the response SA expects after it has built context and exposed tools.
# The LLM may answer directly, ask clarification, select a slot, or report the
# result of a tool call. SA validates and persists.


ResponseAction = Literal[
    "ignore",
    "answer_question",
    "answer_directly",
    "ask_question",
    "ask_clarification",
    "completed",
    "handoff_to_human",
    "create_or_update_crm_contact",
    "prepare_booking_confirmation",
    "appointment_confirmed",
    "appointment_failed",
]


NextAction = Literal[
    "none",
    "ask_clarification",
    "collect_customer_name",
    "collect_contact_data",
    "select_offered_slot",
    "confirm_selected_slot",
    "appointment_confirm",
    "appointment_reschedule",
    "appointment_cancel",
    "handoff_to_human",
]


class SelectedSlot(BaseModel):
    """Slot selected by the LLM from RuntimeContext.appointment.offered_slots.

    SA must validate that this slot exists in the offered slots or in a fresh
    tool result. The LLM should copy exact identifiers and timestamps instead of
    inventing or reformatting them.
    """

    model_config = ConfigDict(extra="ignore")

    start: str
    end: str
    timezone: str | None = None

    service_id: str | None = None
    service_name: str | None = None
    service_ref: str | None = None

    owner_id: str | None = None
    owner_name: str | None = None
    owner_ref: str | None = None

    display_time: str | None = None
    slot_label: str | None = None

    @field_validator(
        "start",
        "end",
        "timezone",
        "service_id",
        "service_name",
        "service_ref",
        "owner_id",
        "owner_name",
        "owner_ref",
        "display_time",
        "slot_label",
        mode="before",
    )
    @classmethod
    def _normalize_optional_string(cls, value: Any) -> str | None:
        if value is None:
            return None
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        return normalized if normalized != "" else None


class LLMFinalResponse(BaseModel):
    """Structured result from the second LLM call.

    The LLM reasons over the runtime context and available tools. For slot
    selection, it must either:
    - return selected_slot copied from the offered slots,
    - ask a clarification question,
    - or call an allowed tool when needed.
    """

    model_config = ConfigDict(extra="ignore")

    reply: str = ""
    domain: Domain = "general"
    intent: Intent = "unknown"
    action: ResponseAction = "answer_question"

    needs_human: bool = False
    score: float = Field(default=0.7, ge=0.0, le=1.0)

    selected_slot: dict[str, Any] | None = None
    required_next_action: NextAction | None = None

    clarification: ClarificationRequest | None = None
    data_to_save: dict[str, Any] = Field(default_factory=dict)

    @field_validator("reply", mode="before")
    @classmethod
    def _normalize_reply(cls, value: Any) -> str:
        if not isinstance(value, str):
            return ""

        return value.strip()

    @field_validator("selected_slot", mode="before")
    @classmethod
    def _normalize_selected_slot(cls, value: Any) -> dict[str, Any] | None:
        if value is None:
            return None
        if not isinstance(value, dict):
            return None

        # Keep a plain dict for flexibility because MCP/tool payloads may include
        # both snake_case and camelCase. Runtime validator will enforce that the
        # slot exists in context.
        return value
