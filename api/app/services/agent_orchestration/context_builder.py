from __future__ import annotations

from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings
from app.schemas.agent import AgentRequest
from app.services.agent_orchestration.schemas import (
    BackendContactContext,
    BackendContext,
    BackendEntrypointContext,
    BackendPoliciesContext,
    BackendTenantContext,
    ConversationContext,
    ConversationTemporalContext,
    CurrentMessage,
    ConversationTurn,
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
        conversation_messages: list[dict[str, Any]],
    ) -> tuple[BackendContext, ConversationContext]:
        current_message_id = self._clean(payload.message.id)
        history_messages = self._previous_messages(conversation_messages, current_message_id)
        turns = self._conversation_turns(history_messages, limit=12)
        contact_context = self._project_contact_context_for_prompt(self._contact_context_from_messages(history_messages))
        timezone, _timezone_source = self._resolve_timezone(backend_context, contact_context)
        temporal_context = self._conversation_temporal_context(timezone)
        backend_block = self._backend_context(backend_context, payload, routing, timezone, contact_context)
        conversation_block = self._conversation_context(payload, turns, temporal_context)
        return backend_block, conversation_block

    def _backend_context(
        self,
        backend_context: CommercialContext | None,
        payload: AgentRequest,
        routing: RoutingContext,
        effective_timezone: str,
        contact_context: dict[str, Any] | None,
    ) -> BackendContext:
        if backend_context is None:
            tenant = BackendTenantContext(id=routing.tenant_id, timezone=effective_timezone)
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
                contact_context=contact_context,
                contact=contact,
                entrypoint=entrypoint,
                policies=BackendPoliciesContext(),
            )

        tenant = BackendTenantContext(
            id=backend_context.tenant.id,
            name=backend_context.tenant.name,
            timezone=backend_context.timezone or backend_context.tenant.timezone or effective_timezone,
            business_context=backend_context.tenant.business_context,
            tone=backend_context.tenant.tone,
            slug=backend_context.tenant.slug,
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
        return BackendContext(
            tenant=tenant,
            contact_context=contact_context,
            contact=contact,
            entrypoint=entrypoint,
            policies=BackendPoliciesContext(
                sales_policy=backend_context.tenant.sales_policy,
                handoff_policy=backend_context.tenant.handoff,
            ),
        )

    def _conversation_context(
        self,
        payload: AgentRequest,
        turns: list[ConversationTurn],
        temporal_context: ConversationTemporalContext,
    ) -> ConversationContext:
        return ConversationContext(
            current_message=self._current_message(payload),
            history=turns,
            temporal_context=temporal_context,
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
            role = self._normalize_turn_role(message)
            text = self._clean(message.get("body")) or ""
            turns.append(
                ConversationTurn(
                    turn_index=len(turns) + 1,
                    role=role,
                    text=text,
                    domain=self._clean(message.get("domain")),
                    intent=self._clean(message.get("intent")),
                    action=self._clean(message.get("action")),
                    structured_data=structured_data,
                    created_at=self._clean(message.get("created_at")),
                )
            )
        return turns

    def _structured_data_from_message(self, message: dict[str, Any]) -> StructuredData:
        data = self._message_data_to_save(message)
        structured_data = data.get("structured_data") if isinstance(data.get("structured_data"), dict) else None
        if isinstance(structured_data, dict):
            return StructuredData.model_validate(self._normalize_structured_data_payload(structured_data))

        return StructuredData()

    def _normalize_structured_data_payload(self, value: Any) -> dict[str, Any]:
        if not isinstance(value, dict):
            return {}

        normalized = dict(value)
        for domain in ("appointment", "services", "crm_contact", "handoff", "general"):
            domain_value = normalized.get(domain)
            if not isinstance(domain_value, dict):
                normalized[domain] = {}
        return normalized

    def _message_data_to_save(self, message: dict[str, Any]) -> dict[str, Any]:
        for data in self._data_to_save_candidates(message):
            return data
        return {}

    def _previous_messages(self, messages: list[dict[str, Any]], current_message_id: str | None) -> list[dict[str, Any]]:
        if current_message_id is None:
            return list(messages)

        filtered: list[dict[str, Any]] = []
        for message in messages:
            if self._is_current_inbound_message(message, current_message_id):
                continue
            filtered.append(message)

        return filtered

    def _is_current_inbound_message(self, message: dict[str, Any], current_message_id: str) -> bool:
        if not isinstance(message, dict):
            return False

        raw_payload = message.get("raw_payload")
        if not isinstance(raw_payload, dict):
            return False

        raw_message = raw_payload.get("message")
        if not isinstance(raw_message, dict):
            return False

        return self._clean(raw_message.get("id")) == current_message_id

    def _normalize_turn_role(self, message: dict[str, Any]) -> str:
        role = self._clean(message.get("role"))
        direction = self._clean(message.get("direction"))
        if role in {"customer", "user"} or direction == "inbound":
            return "customer"
        if role in {"assistant", "agent", "bot"} or direction == "outbound":
            return "assistant"
        return role or "customer"

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

    def _conversation_temporal_context(self, timezone: str) -> ConversationTemporalContext:
        now = datetime.now(ZoneInfo(timezone))
        return ConversationTemporalContext(
            current_datetime=now.isoformat(),
            current_date=now.date().isoformat(),
            rules={
                "today": "current_date",
                "tomorrow": "current_date + 1 day",
                "day_after_tomorrow": "current_date + 2 days",
            },
        )

    def _resolve_timezone(self, backend_context: CommercialContext | None, contact_context: dict[str, Any] | None) -> tuple[str, str]:
        candidates: list[tuple[str | None, str]] = []
        if backend_context is not None:
            candidates.extend(
                [
                    (backend_context.timezone, backend_context.timezone_source or "commercial_context"),
                    (self._clean(contact_context.get("timezone")) if isinstance(contact_context, dict) else None, "backend_context.contact_context"),
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

    def _contact_context_from_messages(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            if not isinstance(message, dict):
                continue

            for data in self._data_to_save_candidates(message):
                backend_context = data.get("backend_context")
                if isinstance(backend_context, dict):
                    contact_context = self._normalize_contact_context_payload(backend_context.get("contact_context"))
                    if contact_context is not None:
                        return contact_context

                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    crm_contact = structured_data.get("crm_contact")
                    if isinstance(crm_contact, dict):
                        contact_context = self._normalize_contact_context_payload(crm_contact.get("contact_context"))
                        if contact_context is not None:
                            return contact_context

        return None

    def _normalize_contact_context_payload(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict) or value == {}:
            return None

        payload = dict(value)
        meaningful_keys = [key for key in payload.keys() if key not in {"status", "error_code", "error_message", "ok"}]
        if meaningful_keys == []:
            return None

        return payload

    def _project_contact_context_for_prompt(self, value: dict[str, Any] | None) -> dict[str, Any] | None:
        if not isinstance(value, dict) or value == {}:
            return None

        projected: dict[str, Any] = {}
        self._copy_if_present(projected, value, "found")
        self._copy_if_present(projected, value, "status")
        self._copy_if_present(projected, value, "error_code")
        self._copy_if_present(projected, value, "error_message")
        self._copy_any_present(projected, value, "timezone", "timezone")
        self._copy_any_present(projected, value, "timezone_source", "timezone_source", "timezoneSource")

        contact = self._project_contact_block(value.get("contact"))
        if contact is not None:
            projected["contact"] = contact

        self._copy_any_present(projected, value, "branch", "branch")
        self._copy_any_present(projected, value, "needs_branch_selection", "needs_branch_selection", "needsBranchSelection")

        if self._is_truthy(projected.get("needs_branch_selection")):
            branches = value.get("branches")
            if isinstance(branches, list) and branches != []:
                projected["branches"] = branches

        appointments = self._project_appointments_block(value.get("appointments"))
        if appointments is not None:
            projected["appointments"] = appointments

        flags = self._project_flags_block(value.get("flags"))
        if flags is not None:
            projected["flags"] = flags

        self._copy_if_present(projected, value, "summary")

        return projected or None

    def _project_contact_block(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict) or value == {}:
            return None

        projected: dict[str, Any] = {}
        self._copy_any_present(projected, value, "found", "found")
        self._copy_any_present(projected, value, "id", "id")
        self._copy_any_present(projected, value, "name", "name")
        self._copy_any_present(projected, value, "email", "email")
        self._copy_any_present(projected, value, "phone", "phone")
        self._copy_any_present(projected, value, "type", "type")

        return projected or None

    def _project_appointments_block(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict) or value == {}:
            return None

        projected: dict[str, Any] = {}
        next_item = value.get("next")
        if next_item not in (None, {}, []):
            projected["next"] = next_item
        items = value.get("items")
        if isinstance(items, list) and items != []:
            projected["items"] = items

        return projected or None

    def _project_flags_block(self, value: Any) -> dict[str, Any] | None:
        if not isinstance(value, dict) or value == {}:
            return None

        projected: dict[str, Any] = {}
        self._copy_any_present(projected, value, "needs_human", "needs_human", "needsHuman")
        self._copy_any_present(projected, value, "do_not_contact", "do_not_contact", "doNotContact")
        return projected or None

    def _copy_if_present(self, target: dict[str, Any], source: dict[str, Any], key: str) -> None:
        if key in source:
            target[key] = source[key]

    def _copy_any_present(self, target: dict[str, Any], source: dict[str, Any], target_key: str, *source_keys: str) -> None:
        for key in source_keys:
            if key in source:
                target[target_key] = source[key]
                return

    def _tenant_payload(self, backend_context: CommercialContext | None, routing: RoutingContext) -> dict[str, Any]:
        if backend_context is None:
            return {"id": routing.tenant_id}
        return {
            "id": backend_context.tenant.id,
            "name": backend_context.tenant.name,
            "slug": backend_context.tenant.slug,
            "business_context": backend_context.tenant.business_context,
            "tone": backend_context.tenant.tone,
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
