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
        turns = self._conversation_turns(conversation_messages, limit=12)
        contact_context = self._contact_context_from_messages(conversation_messages)
        timezone, timezone_source = self._resolve_timezone(backend_context, contact_context)
        backend_block = self._backend_context(backend_context, payload, routing, timezone, contact_context)
        conversation_block = self._conversation_context(payload, turns)
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
                tenant=BackendTenantContext(id=routing.tenant_id, timezone=effective_timezone),
                contact_context=contact_context,
                contact=contact,
                entrypoint=entrypoint,
                available_tools=self._available_tools_context(),
                policies=BackendPoliciesContext(),
            )

        tenant = BackendTenantContext(
            id=backend_context.tenant.id,
            name=backend_context.tenant.name,
            timezone=backend_context.timezone or backend_context.tenant.timezone or effective_timezone,
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
            contact_context=contact_context,
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
                    turn_index=len(turns) + 1,
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
