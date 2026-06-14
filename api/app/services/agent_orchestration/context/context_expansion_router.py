from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict

from app.services.agent_orchestration.planning.schemas import LLMPlanningResult


class ContextExpansionPlan(BaseModel):
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


class ContextExpansionRouter:
    def build(self, planning: LLMPlanningResult) -> ContextExpansionPlan:
        plan = ContextExpansionPlan()

        intent = planning.intent
        if intent in {"catalog_search", "ask_product_or_service_info"}:
            plan.include_catalog_context = True
            plan.include_service_catalog = True

        if intent in {"inventory_search", "inventory_similarity_search"}:
            plan.include_inventory_context = True

        if intent in {"request_availability", "select_offered_slot", "request_booking_confirmation"}:
            plan.include_appointment_context = True
            plan.include_offered_slots = intent == "select_offered_slot"

        if intent in {"request_reschedule", "request_cancel"}:
            plan.include_appointment_context = True
            plan.include_existing_appointments = True

        if intent in {"request_handoff", "complaint_or_problem"}:
            plan.include_customer_context = "basic"

        if planning.context_request.include_catalog_context:
            plan.include_catalog_context = True
        if planning.context_request.include_inventory_context:
            plan.include_inventory_context = True
        if planning.context_request.include_appointment_context:
            plan.include_appointment_context = True
        if planning.context_request.include_existing_appointments:
            plan.include_existing_appointments = True
        if planning.context_request.include_offered_slots:
            plan.include_offered_slots = True

        return plan
