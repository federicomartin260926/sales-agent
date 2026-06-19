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
from app.services.agent_orchestration.schemas import IntentPlan, LLMFinalResponse, ToolPlan
from app.services.agent_orchestration.tool_selector import ToolSelector
from app.services.audio_clients import AudioGatewayClient, AudioTranscriptionClient, AudioTranscriptionResult
from app.services.audio_preprocessor import AudioMessagePreprocessor
from app.services.backend_client import (
    BackendClient,
    BackendConversationMessagePayload,
    BackendConversationUpsertPayload,
    CommercialContext,
)
from app.services.llm_client import LLMClient
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
                data_to_save={"error_code": "misconfigured_routing", "routing": self._routing_payload(routing)},
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

        conversation_result = await self._upsert_conversation(payload, routing)
        conversation_id = self._conversation_id(conversation_result)
        if conversation_id is not None:
            routing.conversation_id = conversation_id

        inbound_result = await self._persist_inbound(payload, routing, audio_result)
        if audio_result is not None:
            await self.audio_preprocessor.report_transcription_event(routing, conversation_result, inbound_result, audio_result, audio_config)

        # The context builder is the main place where complexity is allowed. It
        # loads previous messages, previous offered slots, selected slots and
        # tenant/timezone/contact state. Runtime itself should stay linear.
        conversation_messages = await self.context_builder.load_conversation_messages(payload, routing, limit=12)

        if backend_context is None:
            backend_context = await self._fetch_backend_context(payload, routing)

        intent_plan = await self._classify_intent(payload, routing, backend_context, conversation_messages)
        runtime_context = self.context_builder.build(payload, routing, backend_context, intent_plan, conversation_messages)
        tool_plan = self.tool_selector.select(intent_plan, runtime_context, mcp_config)
        runtime_context.tools = tool_plan.model_dump(exclude_none=True)

        final_response, llm_result = await self._execute_llm_turn(
            payload=payload,
            plan=intent_plan,
            runtime_context=runtime_context,
            tool_plan=tool_plan,
            mcp_config=mcp_config,
        )
        response = self._build_agent_response(final_response, intent_plan, runtime_context, tool_plan, llm_result, started_at, audio_result)

        await self._persist_outbound(response, routing, llm_result, mcp_config)
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
        timezone = "Europe/Madrid"
        now = datetime.now(ZoneInfo(timezone))
        compact_context = {
            "tenant_id": routing.tenant_id,
            "tenant_name": backend_context.tenant.name if backend_context is not None else None,
            "recent_messages": [
                {
                    "direction": m.get("direction"),
                    "body": m.get("body"),
                    "intent": m.get("intent"),
                    "action": m.get("action"),
                }
                for m in conversation_messages[-8:]
                if isinstance(m, dict)
            ],
            "has_offered_slots": bool(self.context_builder._latest_offered_slots(conversation_messages)),
            "has_selected_slot": self.context_builder._latest_selected_slot(conversation_messages) is not None,
            "required_next_action": self.context_builder._latest_required_next_action(conversation_messages),
            "temporal_context": {
                "current_datetime": now.isoformat(),
                "current_date": now.date().isoformat(),
                "timezone": timezone,
                "rules": {
                    "today": "current_date",
                    "tomorrow": "current_date + 1 day",
                    "day_after_tomorrow": "current_date + 2 days",
                    "if_user_mentions_day_month_without_year": "use the nearest future date unless the conversation clearly refers to the past",
                    "do_not_guess_past_years": True,
                },
            },
        }
        prompt = build_intent_user_prompt(payload.message.text or "", compact_context)
        try:
            result = await self.llm_client.generate(self.settings.llm_provider, INTENT_SYSTEM_PROMPT, prompt)
            decoded = self._json_dict(result.content)
            if decoded is None:
                raise ValueError("Intent planner returned non JSON")
            return IntentPlan.model_validate(decoded)
        except Exception as exc:
            logger.warning("Intent classification failed: %s", exc, exc_info=True)
            return IntentPlan(
                domain="general",
                intent="unknown",
                action="answer_directly",
                confidence=0.3,
                needs_tools=False,
                reason="classification_failed",
            )

    # Second LLM call: provide context/tools and let the LLM reason or call MCP tools.
    async def _execute_llm_turn(
        self,
        payload: AgentRequest,
        plan: IntentPlan,
        runtime_context: Any,
        tool_plan: ToolPlan,
        mcp_config: McpRemoteConfig,
    ) -> tuple[LLMFinalResponse, Any | None]:
        # Second LLM call: the LLM receives full context and only the tools that
        # SA has selected for the classified intent. Slot selection happens here.
        prompt = build_final_user_prompt(payload.message.text or "", plan, runtime_context, tool_plan)
        effective_mcp_config = self._filtered_mcp_config(mcp_config, tool_plan.allowed_tools)
        try:
            if effective_mcp_config.enabled and effective_mcp_config.allowed_tools:
                result = await self.llm_client.generate_with_mcp(
                    self.settings.llm_provider,
                    FINAL_SYSTEM_PROMPT,
                    prompt,
                    effective_mcp_config,
                    previous_response_id=None,
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
        except Exception as exc:
            logger.warning("Final LLM turn failed: %s", exc, exc_info=True)
            fallback = LLMFinalResponse(
                reply="Ahora mismo no he podido completar la gestión automáticamente. Te derivo con una persona del equipo.",
                intent=plan.intent,
                action="handoff_to_human",
                needs_human=True,
                score=0.4,
                data_to_save={"error_code": "llm_turn_failed", "error_message": str(exc)},
            )
            return fallback, None

    # Convert the final LLM result into the public AgentResponse and persistable metadata.
    def _build_agent_response(
        self,
        final: LLMFinalResponse,
        plan: IntentPlan,
        runtime_context: Any,
        tool_plan: ToolPlan,
        llm_result: Any | None,
        started_at: float,
        audio_result: AudioTranscriptionResult | None = None,
    ) -> AgentResponse:
        offered_slots = runtime_context.appointment.get("offered_slots") if isinstance(runtime_context.appointment, dict) else []
        validated_selected_slot = None
        selected_slot_invalid = False
        if final.intent != "select_existing_appointment":
            validated_selected_slot = self.context_builder.validate_selected_slot(final.selected_slot, offered_slots if isinstance(offered_slots, list) else [])
            selected_slot_invalid = final.selected_slot is not None and validated_selected_slot is None

        data_to_save: dict[str, Any] = {
            "orchestration_version": "llm_context_tools_v2_audio",
            "intent_plan": plan.model_dump(exclude_none=True),
            "tool_plan": tool_plan.model_dump(exclude_none=True),
            "runtime_context_summary": self._context_summary(runtime_context),
            "mcp_tool_traces": [trace.model_dump(exclude_none=True) for trace in getattr(llm_result, "tool_traces", [])] if llm_result is not None else [],
        }
        data_to_save.update(final.data_to_save or {})
        if final.intent == "select_existing_appointment":
            data_to_save.pop("selected_slot", None)
            data_to_save.pop("new_llm_orchestration_selected_slot", None)

        candidate_existing_appointment = data_to_save.get("existing_appointment")
        existing_appointments = runtime_context.appointment.get("existing_appointments") if isinstance(runtime_context.appointment, dict) else []
        existing_appointment_invalid = False
        if candidate_existing_appointment is not None:
            validated_existing_appointment = self.context_builder.validate_existing_appointment(
                candidate_existing_appointment if isinstance(candidate_existing_appointment, dict) else None,
                existing_appointments if isinstance(existing_appointments, list) else [],
            )
            if validated_existing_appointment is not None:
                data_to_save["existing_appointment"] = validated_existing_appointment
            else:
                existing_appointment_invalid = True
                data_to_save["existing_appointment_validation_error"] = "existing_appointment_not_in_existing_appointments"
                data_to_save["invalid_existing_appointment"] = candidate_existing_appointment

        if not existing_appointment_invalid:
            current_existing_appointment = data_to_save.get("existing_appointment")
            if not isinstance(current_existing_appointment, dict) or not current_existing_appointment:
                runtime_existing_appointment = runtime_context.appointment.get("existing_appointment") if isinstance(runtime_context.appointment, dict) else None
                if isinstance(runtime_existing_appointment, dict) and runtime_existing_appointment:
                    data_to_save["existing_appointment"] = dict(runtime_existing_appointment)

            if isinstance(data_to_save.get("existing_appointment"), dict) and "existing_appointments_count" not in data_to_save:
                data_to_save["existing_appointments_count"] = 1

        current_selected_slot = data_to_save.get("selected_slot")
        if not isinstance(current_selected_slot, dict) or not current_selected_slot:
            runtime_selected_slot = runtime_context.appointment.get("selected_slot") if isinstance(runtime_context.appointment, dict) else None
            if isinstance(runtime_selected_slot, dict) and runtime_selected_slot:
                data_to_save["selected_slot"] = dict(runtime_selected_slot)
                if "new_llm_orchestration_selected_slot" not in data_to_save:
                    data_to_save["new_llm_orchestration_selected_slot"] = dict(runtime_selected_slot)

        if audio_result is not None:
            data_to_save["audio_transcription"] = self.audio_preprocessor.audio_result_payload(audio_result)

        persisted_contact_name = self._clean(runtime_context.conversation.get("persisted_contact_name"))
        if persisted_contact_name is None:
            persisted_contact_name = self._latest_persisted_contact_name(runtime_context)
        missing_contact_name = self._clean(data_to_save.get("contact_name")) is None
        if persisted_contact_name is not None and missing_contact_name:
            data_to_save["contact_name"] = persisted_contact_name
            contact_payload = data_to_save.get("contact")
            if isinstance(contact_payload, dict) and self._clean(contact_payload.get("name")) is None:
                contact_copy = dict(contact_payload)
                contact_copy["name"] = persisted_contact_name
                data_to_save["contact"] = contact_copy

        availability_result = self._latest_appointment_availability_result(llm_result)
        if availability_result is not None:
            # Persist structured availability results so the next LLM turn can select one of the previously offered slots.
            offered_slots = availability_result.get("offered_slots")
            if isinstance(offered_slots, list) and offered_slots != []:
                data_to_save["new_llm_orchestration_offered_slots"] = offered_slots
                data_to_save["new_llm_orchestration_offered_slots_count"] = len(offered_slots)

                appointment_tool_timezone = availability_result.get("timezone")
                if isinstance(appointment_tool_timezone, str) and appointment_tool_timezone.strip() != "":
                    data_to_save["appointment_tool_timezone"] = appointment_tool_timezone.strip()

                appointment_tool_timezone_source = availability_result.get("timezone_source")
                if isinstance(appointment_tool_timezone_source, str) and appointment_tool_timezone_source.strip() != "":
                    data_to_save["appointment_tool_timezone_source"] = appointment_tool_timezone_source.strip()

                raw_summary = availability_result.get("raw_summary")
                if isinstance(raw_summary, dict) and raw_summary != {}:
                    data_to_save["appointment_availability_raw_summary"] = raw_summary

                owner_resolution = availability_result.get("owner_resolution")
                if isinstance(owner_resolution, dict) and owner_resolution != {}:
                    data_to_save["appointment_owner_resolution"] = owner_resolution

                runtime_context_summary = data_to_save.get("runtime_context_summary")
                if isinstance(runtime_context_summary, dict):
                    runtime_context_summary["offered_slots_count"] = len(offered_slots)

        events_result = self._latest_appointment_events_result(llm_result)
        if events_result is not None:
            appointments = events_result.get("existing_appointments")
            if isinstance(appointments, list):
                data_to_save["existing_appointments"] = appointments
                data_to_save["existing_appointments_count"] = len(appointments)
                if len(appointments) == 1:
                    data_to_save["existing_appointment"] = appointments[0]

            raw_summary = events_result.get("raw_summary")
            if raw_summary is not None:
                data_to_save["appointment_events_raw_summary"] = raw_summary

        handoff_result = self._latest_handoff_request_result(llm_result)
        if handoff_result is not None:
            data_to_save.update(handoff_result)

        crm_result = self._latest_crm_contact_submit_result(llm_result)
        if crm_result is not None:
            data_to_save.update(crm_result)

        appointment_reschedule_result = self._latest_appointment_reschedule_result(llm_result)
        if appointment_reschedule_result is not None:
            data_to_save.update(appointment_reschedule_result)

        appointment_cancel_result = self._latest_appointment_cancel_result(llm_result)
        if appointment_cancel_result is not None:
            data_to_save.update(appointment_cancel_result)

        appointment_confirm_result = self._latest_appointment_confirm_result(llm_result)
        if appointment_confirm_result is not None:
            data_to_save.update(appointment_confirm_result)

        if offered_slots and "new_llm_orchestration_offered_slots" not in data_to_save:
            # Preserve structured slots for future turns. These are not a text
            # summary; they are the source of truth the next LLM turn must use.
            data_to_save["new_llm_orchestration_offered_slots"] = offered_slots
            data_to_save["new_llm_orchestration_offered_slots_count"] = len(offered_slots)

        if validated_selected_slot is not None:
            data_to_save["selected_slot"] = validated_selected_slot
            data_to_save["new_llm_orchestration_selected_slot"] = validated_selected_slot
        elif selected_slot_invalid:
            data_to_save["selected_slot_validation_error"] = "selected_slot_not_in_offered_slots"
            data_to_save["invalid_selected_slot"] = final.selected_slot

        active_contact_collection = (
            isinstance(runtime_context.appointment, dict)
            and isinstance(runtime_context.appointment.get("selected_slot"), dict)
            and bool(runtime_context.appointment.get("selected_slot"))
            and runtime_context.appointment.get("required_next_action") == "collect_customer_name"
        )
        if active_contact_collection and "required_next_action" not in data_to_save:
            data_to_save["required_next_action"] = "confirm_selected_slot"

        if final.required_next_action:
            data_to_save["required_next_action"] = final.required_next_action

        self._refresh_context_summary_after_response(data_to_save)

        reply = final.reply.strip() if isinstance(final.reply, str) and final.reply.strip() else "¿Puedes repetirlo de otra forma?"
        if existing_appointment_invalid:
            reply = "No he podido identificar con seguridad cuál de tus citas quieres cambiar. ¿Puedes indicarme cuál?"
        elif selected_slot_invalid:
            reply = "No he podido validar el horario seleccionado. ¿Quieres que revise disponibilidad de nuevo?"

        return AgentResponse(
            reply=reply,
            intent=final.intent or plan.intent or "unknown",
            score=final.score,
            action="ask_clarification" if (existing_appointment_invalid or selected_slot_invalid) else final.action,
            needs_human=bool(final.needs_human),
            data_to_save=data_to_save,
            provider=getattr(llm_result, "provider", None) if llm_result is not None else None,
            model=getattr(llm_result, "model", None) if llm_result is not None else None,
            latency_ms=int((time.monotonic() - started_at) * 1000),
        )

    def _latest_appointment_availability_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        if llm_result is None:
            return None

        tool_traces = getattr(llm_result, "tool_traces", [])
        if not isinstance(tool_traces, list) or tool_traces == []:
            return None

        for trace in reversed(tool_traces):
            trace_type = self._clean(getattr(trace, "type", None))
            tool_name = self._clean(getattr(trace, "tool_name", None))
            if trace_type != "mcp_call" or tool_name != "appointment_availability":
                continue

            status = self._clean(getattr(trace, "status", None))
            if status is not None and status != "completed":
                continue

            output = getattr(trace, "output", None)
            if output is None or output == "" or output == {}:
                continue

            parsed_output: dict[str, Any] | None = None
            if isinstance(output, dict):
                parsed_output = output
            elif isinstance(output, str):
                parsed_output = self._json_dict(output)
            else:
                continue

            if not isinstance(parsed_output, dict) or parsed_output == {}:
                continue

            slots = parsed_output.get("slots")
            if not isinstance(slots, list) or slots == []:
                continue

            normalized_slots = [dict(slot) for slot in slots if isinstance(slot, dict)]
            if normalized_slots == []:
                continue

            raw_summary = parsed_output.get("raw_summary")
            if not isinstance(raw_summary, dict):
                raw_summary = None

            owner_resolution = parsed_output.get("ownerResolution")
            if not isinstance(owner_resolution, dict):
                owner_resolution = parsed_output.get("owner_resolution")
            if not isinstance(owner_resolution, dict):
                owner_resolution = None

            timezone = self._clean(parsed_output.get("timezone"))
            if timezone is None and raw_summary is not None:
                timezone = self._clean(raw_summary.get("timezone"))

            timezone_source = self._clean(parsed_output.get("timezone_source") or parsed_output.get("timezoneSource"))
            if timezone_source is None and raw_summary is not None:
                timezone_source = self._clean(raw_summary.get("timezone_source") or raw_summary.get("timezoneSource"))

            service_id = None
            service_ref = None
            duration_minutes = None
            if raw_summary is not None:
                service_id = self._clean(raw_summary.get("serviceId") or raw_summary.get("service_id"))
                service_ref = self._clean(raw_summary.get("serviceRef") or raw_summary.get("service_ref"))

            if service_id is None:
                service_id = self._clean(parsed_output.get("serviceId") or parsed_output.get("service_id"))
            if service_ref is None:
                service_ref = self._clean(parsed_output.get("serviceRef") or parsed_output.get("service_ref"))

            duration_minutes = self._slot_duration_minutes(normalized_slots[0])
            if duration_minutes is None:
                if raw_summary is not None:
                    raw_duration_minutes = raw_summary.get("durationMinutes")
                    if raw_duration_minutes is None:
                        raw_duration_minutes = raw_summary.get("duration_minutes")
                    if isinstance(raw_duration_minutes, int) and raw_duration_minutes > 0:
                        duration_minutes = raw_duration_minutes
                    elif isinstance(raw_duration_minutes, str) and raw_duration_minutes.strip() != "":
                        try:
                            parsed_duration = int(float(raw_duration_minutes.strip()))
                        except ValueError:
                            parsed_duration = None
                        if isinstance(parsed_duration, int) and parsed_duration > 0:
                            duration_minutes = parsed_duration
                if duration_minutes is None:
                    raw_duration_minutes = parsed_output.get("durationMinutes")
                    if raw_duration_minutes is None:
                        raw_duration_minutes = parsed_output.get("duration_minutes")
                    if isinstance(raw_duration_minutes, int) and raw_duration_minutes > 0:
                        duration_minutes = raw_duration_minutes
                    elif isinstance(raw_duration_minutes, str) and raw_duration_minutes.strip() != "":
                        try:
                            parsed_duration = int(float(raw_duration_minutes.strip()))
                        except ValueError:
                            parsed_duration = None
                        if isinstance(parsed_duration, int) and parsed_duration > 0:
                            duration_minutes = parsed_duration

            enriched_slots: list[dict[str, Any]] = []
            for slot in normalized_slots:
                slot_copy = dict(slot)
                if service_id is not None:
                    slot_copy.setdefault("serviceId", service_id)
                    slot_copy.setdefault("service_id", service_id)
                if service_ref is not None:
                    slot_copy.setdefault("serviceRef", service_ref)
                    slot_copy.setdefault("service_ref", service_ref)
                if duration_minutes is not None:
                    slot_copy.setdefault("durationMinutes", duration_minutes)
                    slot_copy.setdefault("duration_minutes", duration_minutes)
                if timezone is not None:
                    slot_copy.setdefault("timezone", timezone)
                if timezone_source is not None:
                    slot_copy.setdefault("timezone_source", timezone_source)
                enriched_slots.append(slot_copy)

            return {
                "offered_slots": enriched_slots,
                "timezone": timezone,
                "timezone_source": timezone_source,
                "raw_summary": raw_summary,
                "owner_resolution": owner_resolution,
            }

        return None

    def _latest_appointment_events_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        if llm_result is None:
            return None

        tool_traces = getattr(llm_result, "tool_traces", [])
        if not isinstance(tool_traces, list) or tool_traces == []:
            return None

        for trace in reversed(tool_traces):
            trace_type = self._clean(getattr(trace, "type", None))
            tool_name = self._clean(getattr(trace, "tool_name", None))
            if trace_type != "mcp_call" or tool_name != "appointment_events":
                continue

            status = self._clean(getattr(trace, "status", None))
            if status is not None and status != "completed":
                continue

            output = getattr(trace, "output", None)
            if output is None or output == "" or output == {}:
                continue

            parsed_output: dict[str, Any] | None = None
            if isinstance(output, dict):
                parsed_output = output
            elif isinstance(output, str):
                parsed_output = self._json_dict(output)
            else:
                continue

            if not isinstance(parsed_output, dict) or parsed_output == {}:
                continue

            raw_summary = parsed_output.get("raw_summary")

            normalized_appointments: list[dict[str, Any]] = []
            found_list = False
            for key in ("events", "appointments", "items", "results"):
                value = parsed_output.get(key)
                if not isinstance(value, list):
                    continue
                found_list = True
                normalized_appointments = [dict(item) for item in value if isinstance(item, dict)]
                break

            if not found_list:
                normalized_appointments = []

            return {
                "existing_appointments": normalized_appointments,
                "raw_summary": raw_summary,
            }

        return None

    def _latest_handoff_request_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "handoff_request")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        data: dict[str, Any] = {}
        post_processed = parsed_output is not None or trace_status is not None or trace_error_code is not None
        if not post_processed:
            return None

        data["handoff_requested"] = self._resolve_bool_field(
            parsed_output,
            ("ok", "handoff_requested"),
            false_values={"error", "failed", "failure", "rejected", "cancelled", "canceled", "not_requested"},
            status=trace_status,
            error_code=trace_error_code,
        )
        data["handoff_status"] = self._resolve_string_field(parsed_output, ("status",))
        if data["handoff_status"] is None:
            data["handoff_status"] = trace_status
        data["handoff_external_reference"] = self._resolve_string_field(parsed_output, ("external_reference", "externalReference"))
        data["handoff_provider"] = self._resolve_string_field(parsed_output, ("provider",))
        data["handoff_error_code"] = self._resolve_string_field(parsed_output, ("error_code", "errorCode"))
        if data["handoff_error_code"] is None:
            data["handoff_error_code"] = trace_error_code
        return self._compact_observability_payload(data)

    def _latest_crm_contact_submit_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "crm_contact_submit")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        data: dict[str, Any] = {}
        post_processed = parsed_output is not None or trace_status is not None or trace_error_code is not None
        if not post_processed:
            return None

        crm_result = parsed_output.get("crm_result") if isinstance(parsed_output, dict) else None
        if not isinstance(crm_result, dict):
            crm_result = {}

        data["crm_contact_submitted"] = self._resolve_bool_field(
            parsed_output,
            ("ok", "submitted"),
            false_values={"error", "failed", "failure", "rejected", "validation_error", "not_submitted"},
            nested=crm_result,
            status=trace_status,
            error_code=trace_error_code,
        )
        data["crm_contact_status"] = self._resolve_string_field(parsed_output, ("status",), nested=crm_result)
        if data["crm_contact_status"] is None:
            data["crm_contact_status"] = trace_status
        data["crm_contact_id"] = self._resolve_string_field(parsed_output, ("contactId", "contact_id"), nested=crm_result)
        data["crm_lead_id"] = self._resolve_string_field(parsed_output, ("leadId", "lead_id"), nested=crm_result)
        data["crm_customer_id"] = self._resolve_string_field(parsed_output, ("customerId", "customer_id"), nested=crm_result)
        data["crm_decision"] = self._resolve_string_field(parsed_output, ("decision",), nested=crm_result)
        data["crm_activity_created"] = self._resolve_bool_field(parsed_output, ("activityCreated", "activity_created"), nested=crm_result)
        data["crm_summary_stored"] = self._resolve_bool_field(parsed_output, ("summaryStored", "summary_stored"), nested=crm_result)
        data["crm_error_code"] = self._resolve_string_field(parsed_output, ("error_code", "errorCode"), nested=crm_result)
        if data["crm_error_code"] is None:
            data["crm_error_code"] = trace_error_code
        return self._compact_observability_payload(data)

    def _latest_appointment_reschedule_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "appointment_reschedule")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        data: dict[str, Any] = {
            "appointment_reschedule_post_processed": True,
        }
        data["appointment_rescheduled"] = self._resolve_bool_field(
            parsed_output,
            ("ok", "rescheduled"),
            false_values={"error", "failed", "failure", "rejected", "validation_error", "not_rescheduled"},
            status=trace_status,
            error_code=trace_error_code,
        )
        data["appointment_reschedule_status"] = self._resolve_string_field(parsed_output, ("status",))
        if data["appointment_reschedule_status"] is None:
            data["appointment_reschedule_status"] = trace_status
        data["appointment_id"] = self._resolve_string_field(parsed_output, ("appointmentId", "appointment_id"))
        data["appointment_old_start_at"] = self._resolve_string_field(parsed_output, ("old_start_at", "oldStartAt"))
        data["appointment_old_end_at"] = self._resolve_string_field(parsed_output, ("old_end_at", "oldEndAt"))
        data["appointment_new_start_at"] = self._resolve_string_field(parsed_output, ("new_start_at", "newStartAt"))
        data["appointment_new_end_at"] = self._resolve_string_field(parsed_output, ("new_end_at", "newEndAt"))
        data["appointment_reschedule_error_code"] = self._resolve_string_field(parsed_output, ("error_code", "errorCode"))
        if data["appointment_reschedule_error_code"] is None:
            data["appointment_reschedule_error_code"] = trace_error_code
        return self._compact_observability_payload(data)

    def _latest_appointment_cancel_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "appointment_cancel")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        data: dict[str, Any] = {
            "appointment_cancel_post_processed": True,
        }
        data["appointment_cancelled"] = self._resolve_bool_field(
            parsed_output,
            ("ok", "cancelled"),
            false_values={"error", "failed", "failure", "rejected", "validation_error", "not_cancelled"},
            status=trace_status,
            error_code=trace_error_code,
        )
        data["appointment_cancel_status"] = self._resolve_string_field(parsed_output, ("status",))
        if data["appointment_cancel_status"] is None:
            data["appointment_cancel_status"] = trace_status
        data["appointment_id"] = self._resolve_string_field(parsed_output, ("appointmentId", "appointment_id"))
        data["appointment_cancel_error_code"] = self._resolve_string_field(parsed_output, ("error_code", "errorCode"))
        if data["appointment_cancel_error_code"] is None:
            data["appointment_cancel_error_code"] = trace_error_code
        return self._compact_observability_payload(data)

    def _latest_appointment_confirm_result(self, llm_result: Any | None) -> dict[str, Any] | None:
        trace, parsed_output = self._latest_mcp_call_output(llm_result, "appointment_confirm")
        if trace is None:
            return None

        trace_status = self._clean(getattr(trace, "status", None))
        trace_error_code = self._clean(getattr(trace, "error_code", None))
        post_processed = parsed_output is not None or trace_status is not None or trace_error_code is not None
        if not post_processed:
            return None

        data: dict[str, Any] = {
            "appointment_confirm_post_processed": True,
        }

        appointment_payload = parsed_output.get("appointment") if isinstance(parsed_output, dict) else None
        if not isinstance(appointment_payload, dict):
            appointment_payload = {}

        raw_summary = parsed_output.get("raw_summary") if isinstance(parsed_output, dict) else None
        if not isinstance(raw_summary, dict):
            raw_summary = None

        data["appointment_confirmed"] = self._resolve_bool_field(
            parsed_output,
            ("ok", "confirmed"),
            false_values={"error", "failed", "failure", "rejected", "validation_error", "not_confirmed"},
            nested=appointment_payload,
            status=trace_status,
            error_code=trace_error_code,
        )
        data["appointment_confirm_status"] = self._resolve_string_field(parsed_output, ("status",), nested=appointment_payload)
        if data["appointment_confirm_status"] is None and raw_summary is not None:
            data["appointment_confirm_status"] = self._resolve_string_field(raw_summary, ("status",))
        if data["appointment_confirm_status"] is None:
            data["appointment_confirm_status"] = trace_status

        data["appointment_id"] = self._resolve_string_field(parsed_output, ("appointmentId", "appointment_id", "id"), nested=appointment_payload)
        data["appointment_lead_id"] = self._resolve_string_field(parsed_output, ("leadId", "lead_id"), nested=appointment_payload)
        data["appointment_customer_id"] = self._resolve_string_field(parsed_output, ("customerId", "customer_id"), nested=appointment_payload)
        data["appointment_start_at"] = self._resolve_string_field(parsed_output, ("startAt", "start_at"), nested=appointment_payload)
        data["appointment_end_at"] = self._resolve_string_field(parsed_output, ("endAt", "end_at"), nested=appointment_payload)
        data["appointment_timezone"] = self._resolve_string_field(parsed_output, ("timezone",), nested=appointment_payload)
        data["appointment_confirm_error_code"] = self._resolve_string_field(parsed_output, ("error_code", "errorCode"), nested=appointment_payload)
        if data["appointment_confirm_error_code"] is None:
            data["appointment_confirm_error_code"] = trace_error_code

        return data

    def _latest_mcp_call_output(self, llm_result: Any | None, tool_name: str) -> tuple[Any | None, dict[str, Any] | None]:
        if llm_result is None:
            return None, None

        tool_traces = getattr(llm_result, "tool_traces", [])
        if not isinstance(tool_traces, list) or tool_traces == []:
            return None, None

        for trace in reversed(tool_traces):
            trace_type = self._clean(getattr(trace, "type", None))
            candidate_tool_name = self._clean(getattr(trace, "tool_name", None))
            if trace_type != "mcp_call" or candidate_tool_name != tool_name:
                continue

            parsed_output = self._tool_trace_output_dict(trace)
            if parsed_output is None and self._clean(getattr(trace, "status", None)) is None and self._clean(getattr(trace, "error_code", None)) is None:
                return trace, None

            return trace, parsed_output

        return None, None

    def _tool_trace_output_dict(self, trace: Any) -> dict[str, Any] | None:
        output = getattr(trace, "output", None)
        if isinstance(output, dict):
            return output
        if isinstance(output, str):
            parsed_output = self._json_dict(output)
            if isinstance(parsed_output, dict):
                return parsed_output
        return None

    def _resolve_string_field(self, payload: dict[str, Any] | None, field_names: tuple[str, ...], nested: dict[str, Any] | None = None) -> str | None:
        candidates = [payload, nested]
        for source in candidates:
            if not isinstance(source, dict):
                continue
            for field_name in field_names:
                value = source.get(field_name)
                cleaned = self._clean(value)
                if cleaned is not None:
                    return cleaned
        return None

    def _resolve_bool_field(
        self,
        payload: dict[str, Any] | None,
        true_fields: tuple[str, ...],
        false_values: set[str] | None = None,
        nested: dict[str, Any] | None = None,
        status: str | None = None,
        error_code: str | None = None,
    ) -> bool | None:
        false_values = false_values or set()
        candidates = [payload, nested]
        for source in candidates:
            if not isinstance(source, dict):
                continue
            for field_name in true_fields:
                value = source.get(field_name)
                if isinstance(value, bool):
                    return value
                if isinstance(value, str):
                    normalized = value.strip().lower()
                    if normalized in {"true", "1", "yes", "ok", "success", "succeeded", "completed"}:
                        return True
                    if normalized in false_values:
                        return False

        if isinstance(status, str):
            normalized_status = status.strip().lower()
            if normalized_status in {"completed", "success", "succeeded", "ok"}:
                return True
            if normalized_status in {"failed", "failure", "error", "rejected", "cancelled", "canceled"}:
                return False

        if error_code is not None:
            return False

        return None

    def _compact_observability_payload(self, data: dict[str, Any]) -> dict[str, Any] | None:
        compact = {key: value for key, value in data.items() if value is not None}
        return compact or None

    def _slot_duration_minutes(self, slot: dict[str, Any]) -> int | None:
        start_value = slot.get("start") or slot.get("start_at") or slot.get("startAt")
        end_value = slot.get("end") or slot.get("end_at") or slot.get("endAt")
        if not isinstance(start_value, str) or not isinstance(end_value, str):
            return None

        try:
            start_dt = datetime.fromisoformat(start_value.strip())
            end_dt = datetime.fromisoformat(end_value.strip())
        except ValueError:
            return None

        if end_dt <= start_dt:
            return None

        duration_seconds = int((end_dt - start_dt).total_seconds())
        duration_minutes = duration_seconds // 60
        if duration_minutes <= 0:
            return None

        return duration_minutes

    # Ensure the backend conversation exists before storing messages.
    async def _upsert_conversation(self, payload: AgentRequest, routing: RoutingContext) -> dict[str, Any] | None:
        return await self.backend_client.upsert_conversation(
            BackendConversationUpsertPayload(
                tenant_id=routing.tenant_id,
                product_id=routing.product_id,
                entry_point_id=routing.entry_point_id,
                entry_point_utm_id=routing.entry_point_utm_id,
                customer_phone=payload.contact.phone,
                customer_name=payload.contact.name,
                first_message=payload.message.text or self.audio_preprocessor.first_message_placeholder(payload),
                external_conversation_id=payload.conversation.external_id,
                utm_source=routing.utm_source,
                utm_medium=routing.utm_medium,
                utm_campaign=routing.utm_campaign,
                utm_term=routing.utm_term,
                utm_content=routing.utm_content,
                gclid=routing.gclid,
                fbclid=routing.fbclid,
                crm_branch_ref=routing.crm_branch_ref,
            )
        )

    # Store the user message, including transcript metadata when the input was audio.
    async def _persist_inbound(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        audio_result: AudioTranscriptionResult | None = None,
    ) -> Any | None:
        if routing.conversation_id is None:
            return None
        metadata = {
            "orchestration_version": "llm_context_tools_v2_audio",
            "channel_type": payload.channel_type,
            "external_channel_id": payload.external_channel_id,
            "entrypoint_ref": payload.entrypoint_ref,
            "contact": payload.contact.model_dump(exclude_none=True),
        }
        if payload.message.media is not None:
            metadata["message_media"] = payload.message.media
        if audio_result is not None:
            metadata["message_original_type"] = "audio"
            metadata["audio_transcription"] = self.audio_preprocessor.audio_result_payload(audio_result)

        return await self.backend_client.create_conversation_message(
            BackendConversationMessagePayload(
                conversation_id=routing.conversation_id,
                direction="inbound",
                role="user",
                message_type=payload.message.type or "text",
                body=payload.message.text or self.audio_preprocessor.first_message_placeholder(payload),
                external_message_id=payload.message.id,
                external_timestamp=payload.message.timestamp,
                raw_payload=payload.model_dump(exclude_none=True),
                metadata=metadata,
            )
        )

    # Store the assistant response and sanitized MCP/tool trace metadata.
    async def _persist_outbound(
        self,
        response: AgentResponse,
        routing: RoutingContext,
        llm_result: Any | None,
        mcp_config: McpRemoteConfig | None,
    ) -> Any | None:
        if routing.conversation_id is None:
            return None
        metadata = {
            "data_to_save": response.data_to_save,
            "mcp_enabled": bool(mcp_config.enabled) if mcp_config is not None else False,
            "mcp_allowed_tools": list(mcp_config.allowed_tools) if mcp_config is not None else [],
            "mcp_server_label": mcp_config.server_label if mcp_config is not None else None,
            "openai_response_id": getattr(llm_result, "response_id", None) if llm_result is not None else None,
        }
        return await self.backend_client.create_conversation_message(
            BackendConversationMessagePayload(
                conversation_id=routing.conversation_id,
                direction="outbound",
                role="assistant",
                message_type="text",
                body=response.reply,
                provider=response.provider,
                model=response.model,
                latency_ms=response.latency_ms,
                intent=response.intent,
                score=int(response.score * 100),
                action=response.action,
                needs_human=response.needs_human,
                raw_payload=response.model_dump(exclude_none=True),
                metadata=metadata,
            )
        )

    # Keep MCP filtering centralized so the LLM only sees tools selected for the current intent.
    def _filtered_mcp_config(self, mcp_config: McpRemoteConfig, allowed_tools: list[str]) -> McpRemoteConfig:
        if not mcp_config.enabled or not allowed_tools:
            return mcp_config.model_copy(update={"enabled": False, "allowed_tools": []})
        configured = set(mcp_config.allowed_tools)
        filtered = [tool for tool in allowed_tools if tool in configured]
        return mcp_config.model_copy(update={"allowed_tools": filtered, "enabled": bool(filtered)})

    # Extract the backend conversation id from different accepted response shapes.
    def _conversation_id(self, conversation_result: dict[str, Any] | None) -> str | None:
        if not isinstance(conversation_result, dict):
            return None
        conversation = conversation_result.get("conversation")
        if isinstance(conversation, dict):
            candidate = conversation.get("id")
            if isinstance(candidate, str) and candidate.strip():
                return candidate.strip()
        candidate = conversation_result.get("id")
        if isinstance(candidate, str) and candidate.strip():
            return candidate.strip()
        return None

    # Persist a compact context summary for debugging without storing the full prompt.
    def _context_summary(self, runtime_context: Any) -> dict[str, Any]:
        appointment = runtime_context.appointment if isinstance(runtime_context.appointment, dict) else {}
        conversation = runtime_context.conversation if isinstance(runtime_context.conversation, dict) else {}
        existing_appointments = appointment.get("existing_appointments")
        existing_appointments_count = appointment.get("existing_appointments_count")
        if not isinstance(existing_appointments_count, int):
            if isinstance(existing_appointments, list):
                existing_appointments_count = len(existing_appointments)
            else:
                existing_appointments_count = 0
        return {
            "timezone": runtime_context.timezone,
            "timezone_source": runtime_context.timezone_source,
            "offered_slots_count": len(appointment.get("offered_slots") or []),
            "has_selected_slot": isinstance(appointment.get("selected_slot"), dict),
            "has_existing_appointment": bool(existing_appointments_count) or isinstance(appointment.get("existing_appointment"), dict),
            "existing_appointments_count": existing_appointments_count,
            "required_next_action": appointment.get("required_next_action"),
            "recent_messages_count": len(conversation.get("recent_messages") or []),
        }

    def _latest_persisted_contact_name(self, runtime_context: Any) -> str | None:
        conversation = getattr(runtime_context, "conversation", None)
        if not isinstance(conversation, dict):
            return None

        recent_messages = conversation.get("recent_messages")
        if not isinstance(recent_messages, list):
            return None

        for message in reversed(recent_messages):
            if not isinstance(message, dict):
                continue

            for root_key in ("raw_payload", "metadata"):
                root = message.get(root_key)
                if not isinstance(root, dict):
                    continue

                data_to_save = root.get("data_to_save")
                if not isinstance(data_to_save, dict):
                    continue

                contact_name = self._clean(data_to_save.get("contact_name"))
                if contact_name is not None:
                    return contact_name

                contact = data_to_save.get("contact")
                if isinstance(contact, dict):
                    contact_name = self._clean(contact.get("name"))
                    if contact_name is not None:
                        return contact_name

        return None

    def _refresh_context_summary_after_response(self, data_to_save: dict[str, Any]) -> None:
        runtime_context_summary = data_to_save.get("runtime_context_summary")
        if not isinstance(runtime_context_summary, dict):
            return

        selected_slot = data_to_save.get("selected_slot")
        if not isinstance(selected_slot, dict) or not selected_slot:
            selected_slot = data_to_save.get("new_llm_orchestration_selected_slot")
        has_selected_slot = isinstance(selected_slot, dict) and bool(selected_slot)
        runtime_context_summary["has_selected_slot"] = has_selected_slot

        existing_appointment = data_to_save.get("existing_appointment")
        existing_appointments_count = data_to_save.get("existing_appointments_count")
        if not isinstance(existing_appointments_count, int):
            existing_appointments = data_to_save.get("existing_appointments")
            if isinstance(existing_appointments, list):
                existing_appointments_count = len(existing_appointments)
            elif isinstance(existing_appointment, dict):
                existing_appointments_count = 1

        has_existing_appointment = isinstance(existing_appointment, dict) or (
            isinstance(existing_appointments_count, int) and existing_appointments_count > 0
        )
        runtime_context_summary["has_existing_appointment"] = has_existing_appointment
        if isinstance(existing_appointments_count, int):
            runtime_context_summary["existing_appointments_count"] = existing_appointments_count

        offered_slots_count = data_to_save.get("new_llm_orchestration_offered_slots_count")
        if isinstance(offered_slots_count, int):
            runtime_context_summary["offered_slots_count"] = offered_slots_count

        required_next_action = self._clean(data_to_save.get("required_next_action"))
        if required_next_action is not None:
            runtime_context_summary["required_next_action"] = required_next_action

        timezone = None
        timezone_source = None
        if has_selected_slot:
            timezone = self._clean(selected_slot.get("timezone"))
            timezone_source = self._clean(selected_slot.get("timezone_source"))

        if timezone is None:
            timezone = self._clean(data_to_save.get("appointment_tool_timezone"))
        if timezone_source is None:
            timezone_source = self._clean(data_to_save.get("appointment_tool_timezone_source"))

        if timezone is not None:
            runtime_context_summary["timezone"] = timezone
        if timezone_source is not None:
            runtime_context_summary["timezone_source"] = timezone_source

    # Build a small routing diagnostic payload for local error responses.
    def _routing_payload(self, routing: RoutingContext) -> dict[str, Any]:
        return {
            "tenant_id": routing.tenant_id,
            "source": routing.source,
            "external_channel_id": routing.external_channel_id,
            "entrypoint_ref": routing.entrypoint_ref,
            "status": routing.status,
        }

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

    # Normalize optional string values used by routing and defensive checks.
    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None
