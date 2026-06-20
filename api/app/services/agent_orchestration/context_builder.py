from __future__ import annotations

from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings
from app.schemas.agent import AgentRequest
from app.services.agent_orchestration.schemas import (
    AvailableToolsContext,
    BackendContactContext,
    BackendContext,
    BackendEntrypointContext,
    BackendPoliciesContext,
    BackendTenantContext,
    ConversationContext,
    CurrentMessage,
    ConversationTurn,
    IntentPlan,
    RuntimeContext,
    LatestStructuredData,
    LatestStructuredDataAppointment,
    LatestStructuredDataCrmContact,
    LatestStructuredDataGeneral,
    LatestStructuredDataHandoff,
    LatestStructuredDataServices,
    StructuredData,
)
from app.services.backend_client import BackendClient, CommercialContext
from app.services.routing_resolver import RoutingContext


class OrchestrationContextBuilder:
    """Builds structured context for the LLM.

    This class intentionally does not interpret human text. It only gathers and
    normalizes state that the LLM needs: conversation history, previously offered
    slots, selected slot, tenant/product/playbook/contact/timezone and tool info.
    """

    def __init__(self, settings: Settings, backend_client: BackendClient) -> None:
        self.settings = settings
        self.backend_client = backend_client

    async def load_conversation_messages(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        limit: int = 12,
    ) -> list[dict[str, Any]]:
        conversation_id = routing.conversation_id
        external_conversation_id = self._clean(payload.conversation.external_id)
        if conversation_id is None and external_conversation_id is None:
            return list(payload.conversation.context_messages or [])

        summary_context = await self.backend_client.get_conversation_summary_context(
            conversation_id or external_conversation_id or "",
            limit=limit,
            tenant_id=routing.tenant_id,
            external_conversation_id=external_conversation_id,
            customer_phone=self._clean(payload.contact.phone),
            channel_type=self._clean(payload.channel_type),
        )
        if summary_context is None:
            return list(payload.conversation.context_messages or [])

        messages: list[dict[str, Any]] = []
        for message in summary_context.messages:
            try:
                messages.append(message.model_dump())
            except Exception:
                continue
        return messages

    def build(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        plan: IntentPlan,
        conversation_messages: list[dict[str, Any]],
    ) -> RuntimeContext:
        timezone, timezone_source = self._resolve_timezone(backend_context)
        history = self._history(conversation_messages, limit=12)
        turns = self._conversation_turns(conversation_messages, limit=12)
        backend_block = self._backend_context(backend_context, payload, routing)
        conversation_block = self._conversation_context(payload, turns)
        current_message = conversation_block.current_message.model_dump(exclude_none=True)
        persisted_contact_name = self._latest_persisted_contact_name(conversation_messages)
        offered_slots = self._latest_offered_slots(conversation_messages)
        offered_slots_source = self._latest_offered_slots_source(conversation_messages)
        selected_slot = self._latest_selected_slot(conversation_messages)
        existing_appointment = self._latest_existing_appointment(conversation_messages)
        existing_appointments = self._latest_existing_appointments(conversation_messages)
        required_next_action = self._latest_required_next_action(conversation_messages)
        resolution_required = self._requires_existing_appointment_resolution(plan, required_next_action)

        if resolution_required and (
            not isinstance(existing_appointment, dict)
            or not existing_appointment
            or required_next_action not in {"appointment_reschedule", "appointment_cancel"}
        ):
            selected_slot = None

        appointment: dict[str, Any] = {
            "offered_slots": offered_slots,
            "offered_slots_source": offered_slots_source,
            "selected_slot": selected_slot,
            "existing_appointment": existing_appointment,
            "existing_appointments": existing_appointments,
            "required_next_action": required_next_action,
            "timezone": timezone,
            "timezone_source": timezone_source,
            "rules": {
                "slot_selection_owner": "llm",
                "sa_role": "validate_selected_slot_only",
                "do_not_invent_slots": True,
            },
        }

        return RuntimeContext(
            backend_context=backend_block,
            conversation_context=conversation_block,
            tenant=self._tenant_payload(backend_context, routing),
            entry_point=self._entry_point_payload(backend_context),
            product=self._product_payload(backend_context),
            playbook=self._playbook_payload(backend_context),
            contact={
                "phone": self._clean(payload.contact.phone),
                "name": self._clean(payload.contact.name),
                "email": self._clean(payload.contact.email),
            },
            conversation={
                "external_id": self._clean(payload.conversation.external_id),
                "backend_id": routing.conversation_id,
                "summary": self._clean(payload.conversation.summary),
                "recent_messages": history,
                "persisted_contact_name": persisted_contact_name,
                "current_message": current_message,
            },
            appointment=appointment,
            timezone=timezone,
            timezone_source=timezone_source,
        )

    def validate_selected_slot(self, selected_slot: dict[str, Any] | None, offered_slots: list[dict[str, Any]]) -> dict[str, Any] | None:
        """Validate a slot chosen by the LLM against offered slots.

        This is validation, not interpretation: the LLM chooses; SA only ensures
        the returned object matches a real offered slot.
        """
        if not isinstance(selected_slot, dict) or not offered_slots:
            return None

        selected_start = self._clean(selected_slot.get("start") or selected_slot.get("start_at") or selected_slot.get("startAt"))
        selected_end = self._clean(selected_slot.get("end") or selected_slot.get("end_at") or selected_slot.get("endAt"))
        selected_service_id = self._clean(selected_slot.get("service_id") or selected_slot.get("serviceId"))
        selected_owner_id = self._clean(selected_slot.get("owner_id") or selected_slot.get("ownerId"))
        if selected_owner_id is None:
            owner = selected_slot.get("owner")
            if isinstance(owner, dict):
                selected_owner_id = self._clean(owner.get("id"))

        candidates: list[dict[str, Any]] = []
        for slot in offered_slots:
            if not isinstance(slot, dict):
                continue
            offered_start = self._clean(slot.get("start") or slot.get("start_at") or slot.get("startAt"))
            if selected_start is None or selected_start != offered_start:
                continue
            slot_end = self._clean(slot.get("end") or slot.get("end_at") or slot.get("endAt"))
            if selected_end is not None and slot_end is not None and selected_end != slot_end:
                continue
            slot_service_id = self._clean(slot.get("service_id") or slot.get("serviceId"))
            if selected_service_id is not None and slot_service_id is not None and selected_service_id != slot_service_id:
                continue
            slot_owner_id = self._clean(slot.get("owner_id") or slot.get("ownerId"))
            if slot_owner_id is None:
                owner = slot.get("owner")
                if isinstance(owner, dict):
                    slot_owner_id = self._clean(owner.get("id"))
            if selected_owner_id is not None and slot_owner_id is not None and selected_owner_id != slot_owner_id:
                continue
            candidates.append(dict(slot))

        if len(candidates) == 1:
            return candidates[0]
        return None

    def validate_existing_appointment(
        self,
        selected_appointment: dict[str, Any] | None,
        existing_appointments: list[dict[str, Any]],
    ) -> dict[str, Any] | None:
        """Validate a selected existing appointment by id only.

        The LLM chooses which appointment it means. SA only checks that the
        selected id exists in the structured list already present in context.
        """
        if not isinstance(selected_appointment, dict) or not isinstance(existing_appointments, list) or not existing_appointments:
            return None

        selected_id = self._clean(selected_appointment.get("id"))
        if selected_id is None:
            return None

        for appointment in existing_appointments:
            if not isinstance(appointment, dict):
                continue
            appointment_id = self._clean(appointment.get("id"))
            if appointment_id is not None and appointment_id == selected_id:
                return dict(appointment)

        return None

    def _backend_context(
        self,
        backend_context: CommercialContext | None,
        payload: AgentRequest,
        routing: RoutingContext,
    ) -> BackendContext:
        if backend_context is None:
            tenant = BackendTenantContext(id=routing.tenant_id)
            contact = BackendContactContext(
                phone=self._clean(payload.contact.phone),
                name=self._clean(payload.contact.name),
                email=self._clean(payload.contact.email),
            )
            entrypoint = BackendEntrypointContext(
                ref=self._clean(routing.entrypoint_ref or payload.entrypoint_ref),
                channel=self._clean(payload.channel_type) or "whatsapp",
            )
            return BackendContext(
                tenant=tenant,
                contact=contact,
                entrypoint=entrypoint,
                available_tools=self._available_tools_context(),
                policies=BackendPoliciesContext(),
            )

        tenant = BackendTenantContext(
            id=backend_context.tenant.id,
            name=backend_context.tenant.name,
            timezone=backend_context.timezone or backend_context.tenant.timezone,
            business_context=backend_context.tenant.business_context,
            tone=backend_context.tenant.tone,
            slug=backend_context.tenant.slug,
            sales_policy=backend_context.tenant.sales_policy,
            handoff=backend_context.tenant.handoff,
        )
        contact = BackendContactContext(
            phone=self._clean(payload.contact.phone),
            name=self._clean(payload.contact.name),
            email=self._clean(payload.contact.email),
        )
        entrypoint = BackendEntrypointContext(
            ref=self._clean(routing.entrypoint_ref or payload.entrypoint_ref),
            channel=self._clean(payload.channel_type) or "whatsapp",
            id=getattr(backend_context.entry_point, "id", None),
            code=getattr(backend_context.entry_point, "code", None),
            name=getattr(backend_context.entry_point, "name", None),
            description=getattr(backend_context.entry_point, "description", None),
        )
        booking_policy = {
            "booking_enabled": bool(getattr(backend_context.sales_runtime, "booking_enabled", False)),
            "handoff_enabled": bool(getattr(backend_context.sales_runtime, "handoff_enabled", False)),
            "rag_enabled": bool(getattr(backend_context.sales_runtime, "rag_enabled", False)),
        }
        return BackendContext(
            tenant=tenant,
            contact=contact,
            entrypoint=entrypoint,
            available_tools=self._available_tools_context(),
            policies=BackendPoliciesContext(
                sales_policy=backend_context.tenant.sales_policy,
                booking_policy=booking_policy,
                handoff_policy=backend_context.tenant.handoff,
            ),
        )

    def _available_tools_context(self) -> AvailableToolsContext:
        return AvailableToolsContext(
            services=["services_search"],
            appointment=[
                "appointment_events",
                "appointment_availability",
                "appointment_confirm",
                "appointment_reschedule",
                "appointment_cancel",
            ],
            crm_contact=["contact_context", "crm_contact_submit"],
            handoff=["handoff_request"],
        )

    def _conversation_context(self, payload: AgentRequest, turns: list[ConversationTurn]) -> ConversationContext:
        return ConversationContext(
            current_message=self._current_message(payload),
            history=turns,
            latest_structured_data=self._latest_structured_data(turns),
        )

    def _current_message(self, payload: AgentRequest) -> CurrentMessage:
        return CurrentMessage(
            role="customer",
            text=payload.message.text or "",
            received_at=self._clean(payload.message.timestamp),
            channel=self._clean(payload.channel_type or payload.conversation.channel),
        )

    def _conversation_turns(self, messages: list[dict[str, Any]], limit: int = 12) -> list[ConversationTurn]:
        recent = messages[-limit:]
        turns: list[ConversationTurn] = []
        for message in recent:
            if not isinstance(message, dict):
                continue
            structured_data = self._structured_data_from_message(message)
            tool_results = self._tool_results_from_message(message)
            role = self._normalize_turn_role(message)
            text = self._clean(message.get("body")) or ""
            turns.append(
                ConversationTurn(
                    role=role,
                    text=text,
                    domain=self._clean(message.get("domain")),
                    intent=self._clean(message.get("intent")),
                    action=self._clean(message.get("action")),
                    structured_data=structured_data,
                    tool_results=tool_results,
                    created_at=self._clean(message.get("created_at")),
                )
            )
        return turns

    def _latest_structured_data(self, turns: list[ConversationTurn]) -> LatestStructuredData:
        latest_structured_data = LatestStructuredData()
        for turn in reversed(turns):
            appointment = turn.structured_data.appointment
            if not latest_structured_data.appointment.latest_offered_slots and appointment.offered_slots:
                latest_structured_data.appointment.latest_offered_slots = [dict(slot) for slot in appointment.offered_slots]
            if latest_structured_data.appointment.latest_selected_slot is None and isinstance(appointment.selected_slot, dict):
                latest_structured_data.appointment.latest_selected_slot = dict(appointment.selected_slot)
            if not latest_structured_data.appointment.latest_existing_appointments and appointment.existing_appointments:
                latest_structured_data.appointment.latest_existing_appointments = [dict(slot) for slot in appointment.existing_appointments]
            if latest_structured_data.appointment.latest_existing_appointment is None and isinstance(appointment.existing_appointment, dict):
                latest_structured_data.appointment.latest_existing_appointment = dict(appointment.existing_appointment)

            services = turn.structured_data.services
            if not latest_structured_data.services.latest_service_candidates and services.service_candidates:
                latest_structured_data.services.latest_service_candidates = [dict(item) for item in services.service_candidates]
            if latest_structured_data.services.latest_selected_service is None and isinstance(services.selected_service, dict):
                latest_structured_data.services.latest_selected_service = dict(services.selected_service)

            crm_contact = turn.structured_data.crm_contact
            if latest_structured_data.crm_contact.latest_contact_context is None and isinstance(crm_contact.contact_context, dict):
                latest_structured_data.crm_contact.latest_contact_context = dict(crm_contact.contact_context)

            handoff = turn.structured_data.handoff
            if latest_structured_data.handoff.latest_request is None and handoff.requested:
                latest_structured_data.handoff.latest_request = {
                    "requested": handoff.requested,
                    "reason": handoff.reason,
                    "result": handoff.result,
                }

            general = turn.structured_data.general
            if latest_structured_data.general.latest_answer_summary is None:
                if isinstance(general.last_answer_summary, str) and general.last_answer_summary.strip():
                    latest_structured_data.general.latest_answer_summary = general.last_answer_summary.strip()
                elif turn.role == "assistant" and isinstance(turn.text, str) and turn.text.strip():
                    latest_structured_data.general.latest_answer_summary = turn.text.strip()

        return latest_structured_data

    def _structured_data_from_message(self, message: dict[str, Any]) -> StructuredData:
        data = self._message_data_to_save(message)
        structured_data = data.get("structured_data") if isinstance(data.get("structured_data"), dict) else None
        if isinstance(structured_data, dict):
            return StructuredData.model_validate(structured_data)

        return StructuredData(
            appointment=self._appointment_structured_data_from_legacy(data),
            services=self._services_structured_data_from_legacy(data),
            crm_contact=self._crm_contact_structured_data_from_legacy(data),
            handoff=self._handoff_structured_data_from_legacy(data),
            general=self._general_structured_data_from_legacy(data, message),
        )

    def _appointment_structured_data_from_legacy(self, data: dict[str, Any]) -> dict[str, Any]:
        return {
            "offered_slots": self._dict_list(data.get("new_llm_orchestration_offered_slots") or data.get("offered_slots")),
            "selected_slot": self._dict_value(data.get("new_llm_orchestration_selected_slot") or data.get("selected_slot")),
            "existing_appointments": self._dict_list(data.get("existing_appointments")),
            "existing_appointment": self._dict_value(data.get("existing_appointment")),
            "booking_result": self._dict_value(data.get("booking_result") or data.get("appointment_confirm_result")),
            "reschedule_result": self._dict_value(data.get("reschedule_result") or data.get("appointment_reschedule_result")),
            "cancel_result": self._dict_value(data.get("cancel_result") or data.get("appointment_cancel_result")),
        }

    def _services_structured_data_from_legacy(self, data: dict[str, Any]) -> dict[str, Any]:
        return {
            "service_candidates": self._dict_list(data.get("service_candidates")),
            "selected_service": self._dict_value(data.get("selected_service")),
            "last_query": self._clean(data.get("last_query")),
        }

    def _crm_contact_structured_data_from_legacy(self, data: dict[str, Any]) -> dict[str, Any]:
        return {
            "contact_context": self._dict_value(data.get("contact_context")),
            "lead_data": self._dict_value(data.get("lead_data")),
            "submit_result": self._dict_value(data.get("crm_contact_submit_result") or data.get("submit_result")),
        }

    def _handoff_structured_data_from_legacy(self, data: dict[str, Any]) -> dict[str, Any]:
        return {
            "requested": self._is_truthy(data.get("handoff_requested")) or self._is_truthy(data.get("requested")),
            "reason": self._clean(data.get("handoff_reason") or data.get("reason")),
            "result": self._dict_value(data.get("handoff_result") or data.get("result")),
        }

    def _general_structured_data_from_legacy(self, data: dict[str, Any], message: dict[str, Any]) -> dict[str, Any]:
        return {
            "topic": self._clean(data.get("topic")),
            "last_answer_summary": self._clean(data.get("last_answer_summary")) or self._clean(message.get("body")),
        }

    def _tool_results_from_message(self, message: dict[str, Any]) -> list[dict[str, Any]]:
        data = self._message_data_to_save(message)
        tool_results = data.get("tool_results")
        if isinstance(tool_results, list):
            return [dict(item) for item in tool_results if isinstance(item, dict)]

        mcp_tool_traces = data.get("mcp_tool_traces")
        if isinstance(mcp_tool_traces, list):
            return [dict(item) for item in mcp_tool_traces if isinstance(item, dict)]

        return []

    def _message_data_to_save(self, message: dict[str, Any]) -> dict[str, Any]:
        for data in self._data_to_save_candidates(message):
            return data
        return {}

    def _dict_value(self, value: Any) -> dict[str, Any] | None:
        if isinstance(value, dict):
            return dict(value)
        return None

    def _dict_list(self, value: Any) -> list[dict[str, Any]]:
        if not isinstance(value, list):
            return []

        return [dict(item) for item in value if isinstance(item, dict)]

    def _normalize_turn_role(self, message: dict[str, Any]) -> str:
        role = self._clean(message.get("role"))
        direction = self._clean(message.get("direction"))
        if role in {"customer", "user"} or direction == "inbound":
            return "customer"
        if role in {"assistant", "agent", "bot"} or direction == "outbound":
            return "assistant"
        return role or "customer"

    def _latest_offered_slots(self, messages: list[dict[str, Any]], max_slots: int = 60) -> list[dict[str, Any]]:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                if self._is_truthy(data.get("existing_appointment_resolution_blocked")):
                    return []
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        slots = appointment.get("offered_slots")
                        if isinstance(slots, list):
                            return [dict(slot) for slot in slots if isinstance(slot, dict)][:max_slots]
                if "new_llm_orchestration_offered_slots" in data:
                    slots = data.get("new_llm_orchestration_offered_slots")
                    if isinstance(slots, list):
                        return [dict(slot) for slot in slots if isinstance(slot, dict)][:max_slots]
                    return []
                if "offered_slots" in data:
                    slots = data.get("offered_slots")
                    if isinstance(slots, list):
                        return [dict(slot) for slot in slots if isinstance(slot, dict)][:max_slots]
        return []

    def _latest_selected_slot(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                if self._is_truthy(data.get("existing_appointment_resolution_blocked")):
                    return None
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        slot = appointment.get("selected_slot")
                        if isinstance(slot, dict) and slot:
                            return dict(slot)
                slot = data.get("selected_slot") or data.get("new_llm_orchestration_selected_slot")
                if isinstance(slot, dict) and slot:
                    return dict(slot)
        return None

    def _latest_offered_slots_source(self, messages: list[dict[str, Any]]) -> str | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                source = data.get("offered_slots_source")
                if isinstance(source, str) and source.strip():
                    return source.strip()
        return None

    def _latest_existing_appointment(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        existing_appointment = appointment.get("existing_appointment")
                        if isinstance(existing_appointment, dict) and existing_appointment:
                            return dict(existing_appointment)
                appointment = data.get("existing_appointment")
                if isinstance(appointment, dict) and appointment:
                    return dict(appointment)
        return None

    def _latest_existing_appointments(self, messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        appointments = appointment.get("existing_appointments")
                        if isinstance(appointments, list):
                            return [dict(appointment) for appointment in appointments if isinstance(appointment, dict)]
                appointments = data.get("existing_appointments")
                if isinstance(appointments, list):
                    return [dict(appointment) for appointment in appointments if isinstance(appointment, dict)]
        return []

    def _latest_required_next_action(self, messages: list[dict[str, Any]]) -> str | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                action = data.get("required_next_action")
                if isinstance(action, str) and action.strip():
                    return action.strip()
        return None

    def _requires_existing_appointment_resolution(self, plan: IntentPlan, required_next_action: str | None) -> bool:
        normalized_required_next_action = required_next_action.strip() if isinstance(required_next_action, str) else None
        if plan.intent in {"request_reschedule", "request_cancel", "select_existing_appointment"}:
            return True

        return normalized_required_next_action in {"resolve_existing_appointment", "appointment_reschedule", "appointment_cancel"}

    def _latest_persisted_contact_name(self, messages: list[dict[str, Any]]) -> str | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                contact_name = self._clean(data.get("contact_name"))
                if contact_name is not None:
                    return contact_name

                contact = data.get("contact")
                if isinstance(contact, dict):
                    contact_name = self._clean(contact.get("name"))
                    if contact_name is not None:
                        return contact_name

        return None

    def _data_to_save_candidates(self, message: dict[str, Any]) -> list[dict[str, Any]]:
        candidates: list[dict[str, Any]] = []
        for root_key in ("raw_payload", "metadata"):
            root = message.get(root_key)
            if not isinstance(root, dict):
                continue
            data = root.get("data_to_save")
            if isinstance(data, dict):
                candidates.append(data)
        return candidates

    def _history(self, messages: list[dict[str, Any]], limit: int = 10) -> list[dict[str, Any]]:
        recent = messages[-limit:]
        history: list[dict[str, Any]] = []
        for message in recent:
            if not isinstance(message, dict):
                continue
            history.append(
                {
                    "direction": message.get("direction"),
                    "role": message.get("role"),
                    "body": message.get("body"),
                    "intent": message.get("intent"),
                    "action": message.get("action"),
                    "created_at": message.get("created_at"),
                }
            )
        return history

    def _resolve_timezone(self, backend_context: CommercialContext | None) -> tuple[str, str]:
        candidates: list[tuple[str | None, str]] = []
        if backend_context is not None:
            candidates.extend(
                [
                    (backend_context.timezone, backend_context.timezone_source or "commercial_context"),
                    (getattr(backend_context.tenant, "timezone", None), "tenant"),
                    (getattr(backend_context.entry_point, "timezone", None) if backend_context.entry_point else None, "entry_point"),
                ]
            )
        candidates.append((self.settings.default_business_timezone, "settings.default_business_timezone"))
        candidates.append((self.settings.SAFE_DEFAULT_BUSINESS_TIMEZONE, "safety_fallback"))

        for timezone, source in candidates:
            cleaned = self._clean(timezone)
            if cleaned is None:
                continue
            try:
                ZoneInfo(cleaned)
            except Exception:
                continue
            return cleaned, source
        return self.settings.SAFE_DEFAULT_BUSINESS_TIMEZONE, "safety_fallback"

    def _tenant_payload(self, backend_context: CommercialContext | None, routing: RoutingContext) -> dict[str, Any]:
        if backend_context is None:
            return {"id": routing.tenant_id}
        return {
            "id": backend_context.tenant.id,
            "name": backend_context.tenant.name,
            "slug": backend_context.tenant.slug,
            "business_context": backend_context.tenant.business_context,
            "tone": backend_context.tenant.tone,
            "sales_policy": backend_context.tenant.sales_policy,
            "handoff": backend_context.tenant.handoff,
        }

    def _entry_point_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        entry = backend_context.entry_point if backend_context is not None else None
        if entry is None:
            return None
        return {"id": entry.id, "code": entry.code, "name": entry.name, "description": entry.description, "crm_branch_ref": entry.crm_branch_ref}

    def _product_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        product = backend_context.selected_product if backend_context is not None else None
        if product is None:
            return None
        return {
            "id": product.id,
            "name": product.name,
            "slug": product.slug,
            "description": product.description,
            "value_proposition": product.value_proposition,
            "sales_policy": product.sales_policy,
        }

    def _playbook_payload(self, backend_context: CommercialContext | None) -> dict[str, Any] | None:
        playbook = backend_context.selected_playbook if backend_context is not None else None
        if playbook is None:
            return None
        return {"id": playbook.id, "name": playbook.name, "config": playbook.config}

    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        cleaned = value.strip()
        return cleaned or None

    def _is_truthy(self, value: Any) -> bool:
        if isinstance(value, bool):
            return value
        if isinstance(value, str):
            return value.strip().lower() in {"1", "true", "yes", "on"}
        return False
