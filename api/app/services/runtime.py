from __future__ import annotations

import json
import logging
import time
from datetime import datetime
from typing import Any
from zoneinfo import ZoneInfo

from app.config import Settings
from app.schemas.agent import AgentRequest, AgentResponse
from app.schemas.llm import McpRemoteConfig
from app.services.ai_usage_guard import AiUsageGuard
from app.services.agent_orchestration.context_builder import OrchestrationContextBuilder
from app.services.agent_orchestration.prompts import (
    FINAL_SYSTEM_PROMPT,
    INTENT_SYSTEM_PROMPT,
    build_final_user_prompt,
    build_intent_user_prompt,
)
from app.services.agent_orchestration.schemas import BackendContext, ConversationContext, IntentPlan, LLMFinalResponse, ToolPlan
from app.services.agent_orchestration.tool_selector import ToolSelector
from app.services.audio_clients import AudioGatewayClient, AudioTranscriptionClient, AudioTranscriptionResult
from app.services.audio_preprocessor import AudioMessagePreprocessor
from app.services.agent_turn_response_builder import AgentTurnResponseBuilder
from app.services.backend_client import BackendClient, CommercialContext
from app.services.conversation_message_persistence import ConversationMessagePersistence
from app.services.llm_client import LLMClient
from app.services.llm_provider_resilience import LlmProviderUnavailable
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


class AgentRuntime:
    """Minimal runtime for the new LLM-led Sales Agent architecture.

    The runtime is deliberately boring. It should not become a second agent.
    Its job is to move data between systems in a safe order:

    1. Resolve routing and tenant context.
    2. Normalize the incoming message, including optional audio transcription.
    3. Persist inbound/outbound messages.
    4. Ask the LLM for structured intent.
    5. Build structured context and tool permissions for that intent.
    6. Let the LLM reason, select slots and call MCP tools.
    7. Validate only hard consistency constraints before returning.

    Important: SA does not parse human phrases like "mañana a las 5" or
    "el primero". The LLM must receive enough context to interpret them and
    return structured data. SA only validates that returned structured data.
    """


    def __init__(
        self,
        backend_client: BackendClient,
        routing_resolver: RuntimeRoutingResolver,
        ai_usage_guard: AiUsageGuard | None = None,
        settings: Settings | None = None,
        audio_gateway_client: AudioGatewayClient | None = None,
        audio_transcription_client: AudioTranscriptionClient | None = None,
    ) -> None:
        self.backend_client = backend_client
        self.routing_resolver = routing_resolver
        self.ai_usage_guard = ai_usage_guard
        self.settings = settings or backend_client.settings

        self.runtime_settings_client = RuntimeSettingsClient(self.settings)
        self.llm_client = LLMClient(self.settings, runtime_settings_client=self.runtime_settings_client)
        self.context_builder = OrchestrationContextBuilder(self.settings, self.backend_client)
        self.tool_selector = ToolSelector()
        self.response_builder = AgentTurnResponseBuilder()

        # Audio stays outside the conversational orchestration: this service
        # only turns an audio message into text. Runtime then continues as if
        # the user had written that text directly.
        self.audio_preprocessor = AudioMessagePreprocessor(
            settings=self.settings,
            backend_client=self.backend_client,
            ai_usage_guard=self.ai_usage_guard,
            runtime_settings_client=self.runtime_settings_client,
            audio_gateway_client=audio_gateway_client,
            audio_transcription_client=audio_transcription_client,
        )
        self.conversation_message_persistence = ConversationMessagePersistence(self.backend_client, self.audio_preprocessor)

    # Main API entrypoint: route, normalize, persist, classify, build context, run LLM and persist reply.
    async def respond(self, payload: AgentRequest) -> AgentResponse:
        started_at = time.monotonic()
        routing = await self.routing_resolver.resolve(payload)
        if routing is None or not self._clean(routing.tenant_id):
            return self._local_response(
                reply="No puedo identificar el negocio para esta conversación. Te derivo con una persona del equipo.",
                intent="routing_error",
                action="handoff_to_human",
                needs_human=True,
                data_to_save={"error_code": "tenant_not_resolved"},
            )

        if routing.status == "misconfigured_routing":
            return self._local_response(
                reply="Hay una configuración incorrecta del canal. Te derivo con una persona del equipo.",
                intent="misconfigured_routing",
                action="handoff_to_human",
                needs_human=True,
                data_to_save={"error_code": "misconfigured_routing", "routing": self.conversation_message_persistence.routing_payload(routing)},
            )

        audio_result: AudioTranscriptionResult | None = None
        audio_config: Any | None = None
        if self.audio_preprocessor.is_audio_message(payload):
            audio_response, audio_result, audio_config = await self.audio_preprocessor.prepare(payload, routing)
            if audio_response is not None:
                return audio_response

        message_text = payload.message.text or ""
        if message_text.strip() == "":
            return self._local_response(
                reply="Ahora mismo solo puedo procesar mensajes de texto en este flujo. Por favor, escríbeme tu consulta.",
                intent="unsupported_message",
                action="ask_clarification",
                needs_human=False,
                data_to_save={"message_type": payload.message.type},
            )

        backend_context = await self._fetch_backend_context(payload, routing)
        mcp_config = await self.backend_client.fetch_mcp_config(routing.tenant_id)

        conversation_result = await self.conversation_message_persistence.upsert_conversation(payload, routing)
        conversation_id = self.conversation_message_persistence.conversation_id(conversation_result)
        if conversation_id is not None:
            routing.conversation_id = conversation_id

        inbound_result = await self.conversation_message_persistence.persist_inbound(payload, routing, audio_result)
        if audio_result is not None:
            await self.audio_preprocessor.report_transcription_event(routing, conversation_result, inbound_result, audio_result, audio_config)

        # The context builder is the main place where complexity is allowed. It
        # loads previous messages, previous offered slots, selected slots and
        # tenant/timezone/contact state. Runtime itself should stay linear.
        conversation_messages = await self.context_builder.load_conversation_messages(payload, routing, limit=12)

        if backend_context is None:
            backend_context = await self._fetch_backend_context(payload, routing)

        try:
            intent_plan = await self._classify_intent(payload, routing, backend_context, conversation_messages)
        except LlmProviderUnavailable as exc:
            response = self._build_llm_provider_failure_response(
                payload=payload,
                routing=routing,
                backend_context=backend_context,
                started_at=started_at,
                audio_result=audio_result,
                provider_failure=exc,
                intent_plan=None,
            )
            await self.conversation_message_persistence.persist_outbound(response, routing, None, mcp_config)
            return response
        except Exception as exc:
            logger.warning("Intent classification failed: %s", exc, exc_info=True)
            intent_plan = IntentPlan(
                domain="general",
                intent="unknown",
                action="answer_directly",
                confidence=0.3,
                needs_tools=False,
                reason="classification_failed",
            )

        llm_backend_context, llm_conversation_context = self.context_builder.build(
            payload,
            routing,
            backend_context,
            conversation_messages,
        )
        tool_plan = self.tool_selector.select(intent_plan, llm_backend_context, llm_conversation_context, mcp_config)

        try:
            final_response, llm_result = await self._execute_llm_turn(
                payload=payload,
                plan=intent_plan,
                backend_context=llm_backend_context,
                conversation_context=llm_conversation_context,
                tool_plan=tool_plan,
                mcp_config=mcp_config,
            )
        except LlmProviderUnavailable as exc:
            response = self._build_llm_provider_failure_response(
                payload=payload,
                routing=routing,
                backend_context=llm_backend_context,
                started_at=started_at,
                audio_result=audio_result,
                provider_failure=exc,
                intent_plan=intent_plan,
            )
            await self.conversation_message_persistence.persist_outbound(response, routing, None, mcp_config)
            return response
        except Exception as exc:
            logger.warning("Final LLM turn failed: %s", exc, exc_info=True)
            fallback = LLMFinalResponse(
                reply="Ahora mismo no he podido completar la gestión automáticamente. Te derivo con una persona del equipo.",
                intent=intent_plan.intent,
                action="handoff_to_human",
                needs_human=True,
                score=0.4,
                data_to_save={"error_code": "llm_turn_failed", "error_type": exc.__class__.__name__},
            )
            final_response, llm_result = fallback, None

        response = self.response_builder.build_response(
            final_response,
            intent_plan,
            tool_plan,
            llm_result,
            started_at,
            routing=routing,
        )

        await self.conversation_message_persistence.persist_outbound(response, routing, llm_result, mcp_config)
        return response

    # Load tenant/product/playbook/entry-point context from Symfony backend.
    async def _fetch_backend_context(self, payload: AgentRequest, routing: RoutingContext) -> CommercialContext | None:
        backend_context = await self.backend_client.fetch_tenant_context(
            routing.tenant_id,
            selected_product_id=routing.product_id,
            selected_playbook_id=routing.playbook_id,
            entry_point_id=routing.entry_point_id,
            entrypoint_ref=routing.entrypoint_ref or payload.entrypoint_ref,
            customer_phone=payload.contact.phone,
            external_channel_id=routing.external_channel_id or payload.external_channel_id,
            current_message=payload.message.text,
        )
        if backend_context is not None:
            return backend_context

        # Fallback: if a scoped lookup fails, retry with tenant-only context so
        # handoff and commercial policy remain available instead of dropping to
        # a minimal runtime tenant. This keeps the LLM-led flow operational when
        # entrypoint/product scoping is incomplete or temporarily inconsistent.
        return await self.backend_client.fetch_tenant_context(
            routing.tenant_id,
            customer_phone=payload.contact.phone,
            external_channel_id=routing.external_channel_id or payload.external_channel_id,
            current_message=payload.message.text,
        )

    # First LLM call: classify the message into a closed structured intent contract.
    async def _classify_intent(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        conversation_messages: list[dict[str, Any]],
    ) -> IntentPlan:
        # First LLM call: classify, do not execute. We include compact state so
        # the planner knows if the user is likely selecting a previous slot, but
        # it still returns only a small structured contract.
        intent_context = self._intent_prompt_context(payload, routing, backend_context, conversation_messages)
        prompt = build_intent_user_prompt(intent_context)
        result = await self.llm_client.generate(self.settings.llm_provider, INTENT_SYSTEM_PROMPT, prompt)
        decoded = self._json_dict(result.content)
        if decoded is None:
            raise ValueError("Intent planner returned non JSON")
        return IntentPlan.model_validate(decoded)

    def _resolve_effective_timezone(
        self,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> tuple[str, str]:
        candidates: list[tuple[str | None, str]] = []
        if isinstance(contact_context, dict):
            candidates.append(
                (
                    self._clean(contact_context.get("timezone")),
                    self._clean(contact_context.get("timezone_source")) or "backend_context.contact_context",
                )
            )
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

    def _backend_context_timezone(self, backend_context: BackendContext | None) -> tuple[str | None, str | None]:
        if backend_context is None:
            return None, None

        candidates: list[tuple[str | None, str]] = []
        contact_context = backend_context.contact_context if isinstance(backend_context.contact_context, dict) else None
        if isinstance(contact_context, dict):
            candidates.append(
                (
                    self._clean(contact_context.get("timezone")),
                    self._clean(contact_context.get("timezone_source")) or "backend_context.contact_context",
                )
            )
        candidates.extend(
            [
                (getattr(backend_context.tenant, "timezone", None), self._clean(getattr(backend_context.tenant, "timezone_source", None)) or "backend_context.tenant"),
                (getattr(backend_context.entrypoint, "timezone", None), self._clean(getattr(backend_context.entrypoint, "timezone_source", None)) or "backend_context.entrypoint"),
                (self.settings.default_business_timezone, "settings.default_business_timezone"),
                (self.settings.SAFE_DEFAULT_BUSINESS_TIMEZONE, "safety_fallback"),
            ]
        )

        for timezone, source in candidates:
            cleaned = self._clean(timezone)
            if cleaned is None:
                continue
            try:
                ZoneInfo(cleaned)
            except Exception:
                continue
            return cleaned, source
        return None, None

    def _intent_prompt_context(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        conversation_messages: list[dict[str, Any]],
    ) -> dict[str, Any]:
        contact_context = self._contact_context_from_messages(conversation_messages)
        timezone, timezone_source = self._resolve_effective_timezone(backend_context, contact_context)
        now = datetime.now(ZoneInfo(timezone))
        historical_messages = self._messages_without_current_inbound(conversation_messages, payload)
        backend_block: dict[str, Any] = {
            "tenant": {
                "id": routing.tenant_id,
                "name": backend_context.tenant.name if backend_context is not None else None,
            },
            "timezone": timezone,
            "timezone_source": timezone_source,
            "contact": {
                "phone": self._clean(payload.contact.phone),
                "name": self._clean(payload.contact.name),
            },
        }
        if contact_context is not None:
            backend_block["contact_context"] = self._intent_contact_context_summary(contact_context)

        return {
            "backend_context": backend_block,
            "conversation_context": {
                "current_message": {
                    "role": "customer",
                    "text": payload.message.text or "",
                    "channel": self._clean(payload.channel_type or payload.conversation.channel),
                },
                "state": {
                    "has_offered_slots": bool(self._offered_slots_in_messages(conversation_messages)),
                    "has_selected_slot": self._selected_slot_in_messages(conversation_messages) is not None,
                    "has_existing_appointment": self._has_existing_appointment_in_messages(conversation_messages),
                    "existing_appointments_count": self._existing_appointments_count_in_messages(conversation_messages),
                    "required_next_action": self._required_next_action_in_messages(conversation_messages),
                },
                "temporal_context": {
                    "current_datetime": now.isoformat(),
                    "current_date": now.date().isoformat(),
                    "rules": {
                        "today": "current_date",
                        "tomorrow": "current_date + 1 day",
                        "day_after_tomorrow": "current_date + 2 days",
                        "if_user_mentions_day_month_without_year": "use the nearest future date unless the conversation clearly refers to the past",
                        "do_not_guess_past_years": True,
                    },
                },
                "recent_turns_summary": self._recent_turns_summary(historical_messages, limit=4),
            },
        }

    def _intent_contact_context_summary(self, contact_context: dict[str, Any]) -> dict[str, Any]:
        return {
            "found": self._is_truthy(contact_context.get("found")),
            "timezone": self._clean(contact_context.get("timezone")),
            "timezone_source": self._clean(contact_context.get("timezone_source")),
            "has_existing_appointments": self._is_truthy(contact_context.get("has_existing_appointments")),
            "appointments_count": self._int_value(contact_context.get("appointments_count")),
        }

    # Second LLM call: provide context/tools and let the LLM reason or call MCP tools.
    async def _execute_llm_turn(
        self,
        payload: AgentRequest,
        plan: IntentPlan,
        backend_context: BackendContext,
        conversation_context: ConversationContext,
        tool_plan: ToolPlan,
        mcp_config: McpRemoteConfig,
    ) -> tuple[LLMFinalResponse, Any | None]:
        # Second LLM call: the LLM receives full context and only the tools that
        # SA has selected for the classified intent. Slot selection happens here.
        if hasattr(mcp_config, "config") and isinstance(mcp_config.config, dict):
            mcp_config.config = dict(mcp_config.config)
            effective_timezone, effective_timezone_source = self._backend_context_timezone(backend_context)
            if effective_timezone is not None:
                mcp_config.config["effective_timezone"] = effective_timezone
            if effective_timezone_source is not None:
                mcp_config.config["effective_timezone_source"] = effective_timezone_source
        prompt = build_final_user_prompt(payload.message.text or "", plan, backend_context, conversation_context, tool_plan)
        effective_mcp_config = self._filtered_mcp_config(mcp_config, tool_plan.allowed_tools)
        if effective_mcp_config.enabled and effective_mcp_config.allowed_tools:
            tool_choice = self._forced_mcp_tool_choice(tool_plan, effective_mcp_config)
            result = await self.llm_client.generate_with_mcp(
                self.settings.llm_provider,
                FINAL_SYSTEM_PROMPT,
                prompt,
                effective_mcp_config,
                previous_response_id=None,
                tool_choice=tool_choice,
                parallel_tool_calls=False,
                max_tool_rounds=4,
            )
        else:
            result = await self.llm_client.generate(self.settings.llm_provider, FINAL_SYSTEM_PROMPT, prompt)

        decoded = self._json_dict(result.content)
        if decoded is None:
            raise ValueError("Final LLM returned non JSON")
        final_response = LLMFinalResponse.model_validate(decoded)
        return final_response, result

    def _forced_mcp_tool_choice(self, tool_plan: ToolPlan, mcp_config: McpRemoteConfig) -> dict[str, Any] | None:
        must_call_tool = self._clean(tool_plan.must_call_tool)
        server_label = self._clean(mcp_config.server_label)
        if must_call_tool is None or server_label is None:
            return None

        if must_call_tool not in tool_plan.allowed_tools:
            return None

        return {"type": "mcp", "server_label": server_label, "name": must_call_tool}

    def _offered_slots_in_messages(self, messages: list[dict[str, Any]]) -> list[dict[str, Any]]:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        slots = appointment.get("offered_slots")
                        if isinstance(slots, list):
                            return [dict(slot) for slot in slots if isinstance(slot, dict)]
                slots = data.get("new_llm_orchestration_offered_slots") or data.get("offered_slots")
                if isinstance(slots, list):
                    return [dict(slot) for slot in slots if isinstance(slot, dict)]
        return []

    def _recent_turns_summary(self, messages: list[dict[str, Any]], limit: int = 4) -> list[dict[str, Any]]:
        recent = messages[-limit:]
        summary: list[dict[str, Any]] = []
        for message in recent:
            if not isinstance(message, dict):
                continue
            body = self._clean(message.get("body"))
            summary.append(
                {
                    "direction": message.get("direction"),
                    "intent": self._clean(message.get("intent")),
                    "action": self._clean(message.get("action")),
                    "short_summary": self._short_summary(body),
                }
            )
        return summary

    def _messages_without_current_inbound(
        self,
        messages: list[dict[str, Any]],
        payload: AgentRequest,
    ) -> list[dict[str, Any]]:
        if not messages:
            return []

        current_message_id = self._clean(payload.message.id)
        current_message_timestamp = self._clean(payload.message.timestamp)
        current_message_text = self._clean(payload.message.text)

        target_index = self._current_inbound_message_index(
            messages,
            current_message_id=current_message_id,
            current_message_timestamp=current_message_timestamp,
            current_message_text=current_message_text,
        )
        if target_index is not None:
            return [message for index, message in enumerate(messages) if index != target_index]

        return list(messages)

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

    def _current_inbound_message_index(
        self,
        messages: list[dict[str, Any]],
        current_message_id: str | None,
        current_message_timestamp: str | None,
        current_message_text: str | None,
    ) -> int | None:
        if current_message_id is not None or current_message_timestamp is not None:
            for index in range(len(messages) - 1, -1, -1):
                if self._is_current_inbound_message(
                    messages[index],
                    current_message_id=current_message_id,
                    current_message_timestamp=current_message_timestamp,
                    current_message_text=None,
                ):
                    return index

        if current_message_text is not None:
            for index in range(len(messages) - 1, -1, -1):
                if self._is_current_inbound_message(
                    messages[index],
                    current_message_id=None,
                    current_message_timestamp=None,
                    current_message_text=current_message_text,
                ):
                    return index

        return None

    def _is_current_inbound_message(
        self,
        message: dict[str, Any],
        current_message_id: str | None,
        current_message_timestamp: str | None,
        current_message_text: str | None,
    ) -> bool:
        if not isinstance(message, dict):
            return False

        direction = self._clean(message.get("direction"))
        role = self._clean(message.get("role"))
        if direction not in {"inbound"} and role not in {"user", "customer"}:
            return False

        candidate_ids = [
            self._clean(message.get("external_message_id")),
            self._clean(message.get("message_id")),
            self._clean(message.get("id")),
        ]
        if current_message_id is not None and current_message_id in candidate_ids:
            return True

        candidate_timestamps = [
            self._clean(message.get("external_timestamp")),
            self._clean(message.get("timestamp")),
            self._clean(message.get("created_at")),
        ]
        if current_message_timestamp is not None and current_message_timestamp in candidate_timestamps:
            return True

        if current_message_id is None and current_message_timestamp is None and current_message_text is not None:
            body = self._clean(message.get("body"))
            if body is not None and body == current_message_text:
                return True

        return False

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

    def _selected_slot_in_messages(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
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

    def _has_existing_appointment_in_messages(self, messages: list[dict[str, Any]]) -> bool:
        return self._existing_appointment_in_messages(messages) is not None

    def _existing_appointments_count_in_messages(self, messages: list[dict[str, Any]]) -> int:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                count = data.get("existing_appointments_count")
                if isinstance(count, int) and count >= 0:
                    return count

                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        existing_appointments = appointment.get("existing_appointments")
                        if isinstance(existing_appointments, list):
                            return len(existing_appointments)
                        existing_appointment = appointment.get("existing_appointment")
                        if isinstance(existing_appointment, dict) and existing_appointment:
                            return 1
        return 0

    def _existing_appointment_in_messages(self, messages: list[dict[str, Any]]) -> dict[str, Any] | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                structured_data = data.get("structured_data")
                if isinstance(structured_data, dict):
                    appointment = structured_data.get("appointment")
                    if isinstance(appointment, dict):
                        existing_appointment = appointment.get("existing_appointment")
                        if isinstance(existing_appointment, dict) and existing_appointment:
                            return dict(existing_appointment)

                existing_appointment = data.get("existing_appointment")
                if isinstance(existing_appointment, dict) and existing_appointment:
                    return dict(existing_appointment)
        return None

    def _required_next_action_in_messages(self, messages: list[dict[str, Any]]) -> str | None:
        for message in reversed(messages):
            for data in self._data_to_save_candidates(message):
                action = data.get("required_next_action")
                if isinstance(action, str) and action.strip():
                    return action.strip()
        return None

    def _short_summary(self, value: str | None, limit: int = 120) -> str | None:
        if value is None:
            return None

        normalized = " ".join(value.split())
        if normalized == "":
            return None
        if len(normalized) <= limit:
            return normalized
        return normalized[: max(0, limit - 1)].rstrip() + "…"

    # Keep MCP filtering centralized so the LLM only sees tools selected for the current intent.
    def _filtered_mcp_config(self, mcp_config: McpRemoteConfig, allowed_tools: list[str]) -> McpRemoteConfig:
        if not mcp_config.enabled or not allowed_tools:
            return mcp_config.model_copy(update={"enabled": False, "allowed_tools": []})
        configured = set(mcp_config.allowed_tools)
        filtered = [tool for tool in allowed_tools if tool in configured]
        return mcp_config.model_copy(update={"allowed_tools": filtered, "enabled": bool(filtered)})

    # Parse a JSON object from LLM output, tolerating accidental surrounding text.
    def _json_dict(self, content: str) -> dict[str, Any] | None:
        try:
            payload = json.loads(content)
        except json.JSONDecodeError:
            start = content.find("{")
            end = content.rfind("}")
            if start < 0 or end <= start:
                return None
            try:
                payload = json.loads(content[start : end + 1])
            except json.JSONDecodeError:
                return None
        return payload if isinstance(payload, dict) else None

    # Build a deterministic response when runtime cannot or should not call the LLM.
    def _local_response(
        self,
        reply: str,
        intent: str,
        action: str,
        needs_human: bool,
        data_to_save: dict[str, Any] | None = None,
    ) -> AgentResponse:
        return AgentResponse(
            reply=reply,
            intent=intent,
            score=0.0,
            action=action,
            needs_human=needs_human,
            data_to_save=data_to_save or {},
            provider=None,
            model=None,
            latency_ms=0,
        )

    def _build_llm_provider_failure_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        started_at: float,
        audio_result: AudioTranscriptionResult | None,
        provider_failure: LlmProviderUnavailable,
        intent_plan: IntentPlan | None,
    ) -> AgentResponse:
        handoff_available = self._handoff_available(backend_context)
        reply = (
            "Ahora mismo no puedo completar la consulta. Te derivo con una persona del equipo."
            if handoff_available
            else "Ahora mismo no puedo completar la consulta. Inténtalo de nuevo en unos minutos."
        )
        intent = intent_plan.intent if intent_plan is not None else "unknown"
        action = "handoff_to_human" if handoff_available else "answer_directly"
        response = AgentResponse(
            reply=reply,
            intent=intent,
            score=0.0,
            action=action,
            needs_human=handoff_available,
            data_to_save={"error_code": "llm_provider_unavailable"},
            provider=None,
            model=None,
            latency_ms=int((time.monotonic() - started_at) * 1000),
        )
        response.data_to_save["technical_metadata"] = self.response_builder.build_technical_metadata(provider_failure)
        return response

    # Normalize optional string values used by routing and defensive checks.
    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None

    def _handoff_available(self, backend_context: Any | None) -> bool:
        if backend_context is None:
            return False

        tenant = getattr(backend_context, "tenant", None)
        handoff = getattr(tenant, "handoff", {}) if tenant is not None else {}
        if not isinstance(handoff, dict):
            handoff = {}

        enabled = bool(handoff.get("enabled"))
        policies = getattr(backend_context, "policies", None)
        handoff_policy = getattr(policies, "handoff_policy", None) if policies is not None else None
        if isinstance(handoff_policy, dict):
            enabled = enabled or bool(handoff_policy.get("enabled"))

        strategy = self._clean(handoff.get("strategy"))
        if strategy is None:
            strategy = self._clean(handoff_policy.get("strategy")) if isinstance(handoff_policy, dict) else None
        if strategy is None:
            strategy = "disabled"

        return enabled and strategy in {"manual_wa_link", "n8n_webhook", "manual_wa_link_and_n8n"}

    def _is_truthy(self, value: Any) -> bool | None:
        if isinstance(value, bool):
            return value
        if isinstance(value, (int, float)) and value in {0, 1}:
            return bool(value)
        if isinstance(value, str):
            normalized = value.strip().lower()
            if normalized in {"true", "1", "yes", "y", "on"}:
                return True
            if normalized in {"false", "0", "no", "n", "off"}:
                return False
        return None

    def _int_value(self, value: Any) -> int | None:
        if isinstance(value, int):
            return value
        if isinstance(value, str):
            try:
                return int(value.strip())
            except Exception:
                return None
        return None
