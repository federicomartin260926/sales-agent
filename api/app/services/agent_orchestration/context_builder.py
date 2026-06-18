from __future__ import annotations

from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings
from app.schemas.agent import AgentRequest
from app.services.agent_orchestration.schemas import IntentPlan, RuntimeContext
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
        history = self._history(conversation_messages)
        persisted_contact_name = self._latest_persisted_contact_name(conversation_messages)
        offered_slots = self._latest_offered_slots(conversation_messages)
        selected_slot = self._latest_selected_slot(conversation_messages)
        required_next_action = self._latest_required_next_action(conversation_messages)

        appointment: dict[str, Any] = {
            "offered_slots": offered_slots,
            "selected_slot": selected_slot,
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
                "current_message": payload.message.text,
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

        for slot in offered_slots:
            if not isinstance(slot, dict):
                continue
            if selected_start is None or selected_start != self._clean(slot.get("start") or slot.get("start_at") or slot.get("startAt")):
                continue
            slot_end = self._clean(slot.get("end") or slot.get("end_at") or slot.get("endAt"))
            if selected_end is not None and slot_end is not None and selected_end != slot_end:
                continue
            slot_service_id = self._clean(slot.get("service_id") or slot.get("serviceId"))
            if selected_service_id is not None and slot_service_id is not None and selected_service_id != slot_service_id:
                continue
            slot_owner_id = self._clean(slot.get("owner_id") or slot.get("ownerId"))
            if selected_owner_id is not None and slot_owner_id is not None and selected_owner_id != slot_owner_id:
                continue
            return dict(slot)
        return None

    def _latest_offered_slots(self, messages: list[dict[str, Any]], max_slots: int = 60) -> list[dict[str, Any]]:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                slots = data.get("new_llm_orchestration_offered_slots") or data.get("offered_slots")
                if isinstance(slots, list) and slots:
                    return [dict(slot) for slot in slots if isinstance(slot, dict)][:max_slots]
        return []

    def _latest_selected_slot(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                slot = data.get("selected_slot") or data.get("new_llm_orchestration_selected_slot")
                if isinstance(slot, dict) and slot:
                    return dict(slot)
        return None

    def _latest_required_next_action(self, messages: list[dict[str, Any]]) -> str | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                action = data.get("required_next_action")
                if isinstance(action, str) and action.strip():
                    return action.strip()
        return None

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
