from __future__ import annotations

import copy
import json
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
import httpx
import logging
import re
import unicodedata
import uuid
from typing import Any
from zoneinfo import ZoneInfo

import time

from app.config import Settings
from app.schemas.agent import AgentRequest, AgentResponse
from app.schemas.llm import BackendAiUsageEventPayload
from app.schemas.llm import McpRemoteConfig
from app.services.ai_usage_guard import AiUsageGuard, AiUsageGuardDecision
from app.services.audio_clients import AudioGatewayClient, AudioTranscriptionClient, AudioTranscriptionResult
from app.services.backend_client import (
    BackendClient,
    BackendConversationMessagePayload,
    BackendConversationUpsertPayload,
    CommercialContext,
)
from app.services.conversation_summary_service import ConversationSummaryService
from app.services.contact_context_resolver import ContactContextResolver
from app.services.agent_orchestration.execution.appointment_availability_execution_service import (
    AppointmentAvailabilityExecutionService,
)
from app.services.agent_orchestration.execution.slot_selection_execution_service import (
    SlotSelectionExecutionService,
)
from app.services.agent_orchestration.shadow.shadow_planning_service import ShadowPlanningService
from app.services.agent_orchestration.execution.catalog_execution_service import CatalogExecutionService
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


@dataclass
class ShadowOrchestrationResult:
    data_to_save: dict[str, Any]
    response: AgentResponse | None = None


class AgentRuntime:
    DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE = (
        "El audio es demasiado largo para procesarlo automáticamente. Por favor, envíame un audio más corto o escríbeme el mensaje por texto."
    )
    DEFAULT_AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR = 0.02
    DEFAULT_AUDIO_LLM_FOLLOWUP_RESERVE_COST_EUR = 0.01
    AUDIO_TRANSCRIPTION_USAGE_LIMIT_MESSAGE = (
        "Ahora mismo no puedo procesar audios. Por favor, envíame tu consulta por escrito."
    )
    AUDIO_TRANSCRIPTION_PLAN_DISABLED_MESSAGE = (
        "Tu plan actual no incluye procesamiento automático de audios. Por favor, escribe el mensaje en texto o contacta con el equipo para ampliar el plan."
    )

    def __init__(
        self,
        backend_client: BackendClient,
        routing_resolver: RuntimeRoutingResolver,
        decision_engine: DecisionEngine,
        ai_usage_guard: AiUsageGuard | None = None,
        audio_gateway_client: AudioGatewayClient | None = None,
        audio_transcription_client: AudioTranscriptionClient | None = None,
        conversation_summary_service: ConversationSummaryService | None = None,
        contact_context_resolver: ContactContextResolver | None = None,
    ) -> None:
        self.backend_client = backend_client
        self.routing_resolver = routing_resolver
        self.decision_engine = decision_engine
        self.ai_usage_guard = ai_usage_guard or AiUsageGuard(backend_client)
        backend_settings = getattr(backend_client, "settings", None)
        self.settings = backend_settings if backend_settings is not None else Settings()
        runtime_settings_client = RuntimeSettingsClient(backend_settings) if backend_settings is not None else None
        self.audio_gateway_client = audio_gateway_client
        if self.audio_gateway_client is None and backend_settings is not None:
            self.audio_gateway_client = AudioGatewayClient(backend_settings, runtime_settings_client)
        self.audio_transcription_client = audio_transcription_client
        if self.audio_transcription_client is None and backend_settings is not None:
            self.audio_transcription_client = AudioTranscriptionClient(backend_settings, runtime_settings_client)
        self.conversation_summary_service = conversation_summary_service
        if self.conversation_summary_service is None and backend_settings is not None:
            self.conversation_summary_service = ConversationSummaryService(backend_settings, backend_client)
        self.contact_context_resolver = contact_context_resolver
        if self.contact_context_resolver is None:
            llm_client = getattr(getattr(self.decision_engine, "llm_decision_service", None), "llm_client", None)
            self.contact_context_resolver = ContactContextResolver(backend_client, llm_client, self.settings)
        self.shadow_planning_service = ShadowPlanningService(
            self.settings,
            llm_client=getattr(getattr(self.decision_engine, "llm_decision_service", None), "llm_client", None),
        )
        self.appointment_availability_execution_service = AppointmentAvailabilityExecutionService(
            self.settings,
            llm_client=getattr(getattr(self.decision_engine, "llm_decision_service", None), "llm_client", None),
        )
        self.slot_selection_execution_service = SlotSelectionExecutionService(
            self.settings,
            llm_client=getattr(getattr(self.decision_engine, "llm_decision_service", None), "llm_client", None),
        )
        self.catalog_execution_service = CatalogExecutionService(
            self.settings,
            llm_client=getattr(getattr(self.decision_engine, "llm_decision_service", None), "llm_client", None),
        )

    async def respond(self, payload: AgentRequest) -> AgentResponse:
        routing = await self.routing_resolver.resolve(payload)
        if routing is None:
            return AgentResponse(
                reply="No tengo suficiente contexto de routing para identificar el negocio. ¿Puedes compartir el enlace o canal correcto?",
                intent="routing",
                score=0.1,
                action="missing_routing_context",
                needs_human=True,
                data_to_save=self._missing_routing_data(payload),
            )

        if routing.status == "misconfigured_routing":
            return AgentResponse(
                reply="La configuración de routing es inconsistente: el entrypoint_ref y el phone_number_id apuntan a tenants distintos. Revisa qué negocio debe usar ese número.",
                intent="routing",
                score=0.0,
                action="misconfigured_routing",
                needs_human=True,
                data_to_save=self._misconfigured_routing_data(payload, routing),
            )

        audio_transcription_error: Exception | None = None
        if self._is_audio_message(payload):
            if self.audio_gateway_client is None or self.audio_transcription_client is None:
                logger.error("Audio clients are not configured for WhatsApp audio processing")
                return self._audio_transcription_failure_response(
                    payload,
                    routing,
                    RuntimeError("Audio clients are not configured."),
                )

            ai_usage_decision = await self.ai_usage_guard.evaluate(routing.tenant_id)
            audio_plan_enabled = getattr(ai_usage_decision.policy, "audio_transcription_enabled_by_plan", True)
            if not audio_plan_enabled:
                return self._audio_transcription_disabled_response(
                    payload,
                    routing,
                    reply=self._audio_transcription_plan_disabled_message(ai_usage_decision.policy),
                    data_extra={
                        "audio_transcription_disabled_by_plan": True,
                        "audio_transcription_plan_message": self._audio_transcription_plan_disabled_message(ai_usage_decision.policy),
                    },
                )

            if not ai_usage_decision.allowed:
                return self._ai_usage_limit_response(ai_usage_decision)

            audio_configuration = await self._resolve_audio_transcription_configuration()
            if audio_configuration is not None and not getattr(audio_configuration, "enabled", True):
                return self._audio_transcription_disabled_response(payload, routing)

            duration_seconds = self._audio_duration_seconds(payload)
            max_audio_seconds = self._effective_audio_limit_seconds(ai_usage_decision.policy)
            if duration_seconds is not None and duration_seconds > max_audio_seconds:
                logger.info(
                    "WhatsApp audio duration limit exceeded tenant_id=%s message_id=%s media_id=%s duration_seconds=%s max_seconds=%s",
                    routing.tenant_id,
                    payload.message.id or "-",
                    self._audio_media_id(payload) or "-",
                    duration_seconds,
                    max_audio_seconds,
                )
                return self._audio_duration_limit_response(
                    payload,
                    routing,
                    duration_seconds,
                    max_audio_seconds,
                    ai_usage_decision.policy,
                )

            transcription_cost_limit_decision = self._audio_transcription_cost_limit_decision(
                ai_usage_decision.policy,
                ai_usage_decision.usage,
                duration_seconds,
                max_audio_seconds,
                audio_configuration,
            )
            if transcription_cost_limit_decision is not None:
                logger.info(
                    "WhatsApp audio transcription cost limit exceeded tenant_id=%s message_id=%s media_id=%s estimated_cost_eur=%.6f required_cost_eur=%.6f remaining_daily_cost_eur=%s remaining_monthly_cost_eur=%s reason=%s",
                    routing.tenant_id,
                    payload.message.id or "-",
                    self._audio_media_id(payload) or "-",
                    transcription_cost_limit_decision["estimated_cost_eur"],
                    transcription_cost_limit_decision["required_cost_eur"],
                    transcription_cost_limit_decision["remaining_daily_cost_eur"],
                    transcription_cost_limit_decision["remaining_monthly_cost_eur"],
                    transcription_cost_limit_decision["reason"],
                )
                return self._audio_transcription_usage_limit_response(
                    payload,
                    routing,
                    max_audio_seconds,
                    transcription_cost_limit_decision,
                )

            try:
                transcription_result = await self._transcribe_audio_message(payload, audio_configuration, duration_seconds)
            except Exception as exc:
                wa_id = getattr(payload.contact, "wa_id", None) or payload.contact.phone
                sender = getattr(payload.contact, "sender", None) or payload.contact.phone
                logger.exception(
                    "Failed to transcribe WhatsApp audio message_id=%s wa_id=%s sender=%s",
                    payload.message.id,
                    wa_id,
                    sender,
                )
                audio_transcription_error = exc
            else:
                payload.message.text = transcription_result.text

        audio_failure_handoff_response: AgentResponse | None = None
        backend_context = await self.backend_client.fetch_tenant_context(
            routing.tenant_id,
            routing.product_id,
            routing.playbook_id,
            routing.entry_point_id,
            routing.entrypoint_ref,
            payload.contact.phone,
            routing.external_channel_id or payload.external_channel_id,
            payload.message.text or "",
        )
        if self._is_audio_message(payload) and audio_transcription_error is not None:
            handoff_config = self._handoff_config_from_tenant(backend_context.tenant if backend_context is not None else None)
            if handoff_config is None:
                return self._audio_transcription_failure_response(payload, routing, audio_transcription_error)

            audio_failure_handoff_response = self._audio_transcription_failure_response(
                payload,
                routing,
                audio_transcription_error,
                handoff_config=handoff_config,
            )

        mcp_config = await self.backend_client.fetch_mcp_config(routing.tenant_id)
        self._log_mcp_config(routing.tenant_id, mcp_config)

        conversation_result = await self.backend_client.upsert_conversation(
            BackendConversationUpsertPayload(
                tenant_id=routing.tenant_id,
                product_id=routing.product_id,
                entry_point_id=routing.entry_point_id,
                entry_point_utm_id=routing.entry_point_utm_id,
                customer_phone=payload.contact.phone,
                customer_name=payload.contact.name,
                first_message=payload.message.text or "",
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

        conversation_id = self._conversation_id_from_result(conversation_result)
        if conversation_id is not None:
            inbound_result = await self.backend_client.create_conversation_message(
                BackendConversationMessagePayload(
                    conversation_id=conversation_id,
                    direction="inbound",
                    role="user",
                    message_type=payload.message.type,
                    body=payload.message.text or "",
                    external_message_id=self._raw_event_field(payload.raw_event, "whatsapp_message_id", "whatsappMessageId", "message_id", "id"),
                    external_timestamp=self._raw_event_field(payload.raw_event, "timestamp"),
                    raw_payload=payload.raw_event,
                    metadata=self._inbound_metadata(payload, routing),
                )
            )
            if inbound_result is not None and inbound_result.duplicate:
                return AgentResponse(
                    reply="",
                    intent="duplicate_message",
                    score=0,
                    action="ignore",
                    needs_human=False,
                    data_to_save={
                        "duplicate": True,
                        "reason": "inbound_message_already_processed",
                    },
                )

            if self._is_audio_message(payload):
                if audio_transcription_error is None:
                    await self._report_audio_transcription_event(
                        routing,
                        conversation_result,
                        inbound_result,
                        transcription_result,
                        audio_configuration,
                        duration_seconds,
                    )

        if isinstance(conversation_result, dict):
            conversation = conversation_result.get("conversation")
            if isinstance(conversation, dict) and isinstance(conversation.get("id"), str):
                routing.conversation_id = conversation["id"]

        normalized_message = unicodedata.normalize("NFKD", self._normalize_text(payload.message.text)).encode("ascii", "ignore").decode("ascii").lower().strip()
        if routing.conversation_id is not None and self._is_slot_selection_followup(normalized_message):
            try:
                conversation_summary_context = await self.backend_client.get_conversation_summary_context(routing.conversation_id, limit=8)
            except Exception:
                conversation_summary_context = None

            if conversation_summary_context is not None and conversation_summary_context.messages:
                payload.conversation.context_messages = [message.model_dump() for message in conversation_summary_context.messages]

        previous_response_id = self._previous_response_id_from_conversation_result(conversation_result)
        if previous_response_id is not None and self._requires_fresh_availability_turn(payload):
            previous_response_id = None

        explicit_handoff_request = False
        if audio_failure_handoff_response is not None:
            response = audio_failure_handoff_response
            latency_ms = 0
        else:
            explicit_handoff_request = self._is_explicit_handoff_request(payload)
            handoff_config = self._handoff_config_from_tenant(backend_context.tenant if backend_context is not None else None)
            short_circuited_handoff = (
                explicit_handoff_request
                and handoff_config is not None
                and str(handoff_config.get("strategy") or "").strip().lower() == "manual_wa_link"
            )

            if short_circuited_handoff:
                response = self._build_local_handoff_response(handoff_config)
                mcp_config = None
                latency_ms = response.latency_ms if response.latency_ms is not None else 0
            else:
                mcp_config = await self.backend_client.fetch_mcp_config(routing.tenant_id)
                self._log_mcp_config(routing.tenant_id, mcp_config)
                contact_context = None
                contact_context_resolver_called = False
                if self._should_preload_contact_context(payload, normalized_message):
                    contact_context_resolver_called = True
                    contact_context = await self._resolve_contact_context(
                        payload,
                        routing,
                        backend_context,
                        conversation_id,
                        mcp_config,
                    )
                    self._publish_contact_context_to_conversation_context(payload, contact_context)

                agenda_tools_available = self._mcp_agenda_tools_available(mcp_config)
                agenda_message_kind = self._agenda_message_kind(payload.message.text or "")
                effective_contact_context = self._effective_contact_context(payload, contact_context)
                agenda_effective_timezone, agenda_effective_timezone_source = self._resolve_agenda_effective_timezone_details(
                    backend_context,
                    effective_contact_context,
                )
                if agenda_message_kind == "booking" and agenda_tools_available and agenda_effective_timezone is None:
                    if not contact_context_resolver_called:
                        contact_context_resolver_called = True
                        contact_context = await self._resolve_contact_context(
                            payload,
                            routing,
                            backend_context,
                            conversation_id,
                            mcp_config,
                        )
                        self._publish_contact_context_to_conversation_context(payload, contact_context)
                        effective_contact_context = self._effective_contact_context(payload, contact_context)
                        agenda_effective_timezone, agenda_effective_timezone_source = self._resolve_agenda_effective_timezone_details(
                            backend_context,
                            effective_contact_context,
                        )

                if agenda_message_kind == "booking" and agenda_tools_available and agenda_effective_timezone is None:
                    response = self._timezone_guardrail_block_response(
                        mismatched_tool="appointment_availability",
                        reason="missing_effective_timezone",
                        timezone_source=agenda_effective_timezone_source,
                        contact_context_resolver_called=contact_context_resolver_called,
                        contact_context=effective_contact_context,
                    )
                    return response

                if not agenda_tools_available:
                    agenda_response = self.decision_engine.resolve_agenda_response(
                        payload,
                        routing=routing,
                        backend_context=backend_context,
                        contact_context=effective_contact_context,
                    )
                    if agenda_response is not None:
                        return agenda_response

                ai_usage_decision = await self.ai_usage_guard.evaluate(backend_context.tenant.id if backend_context is not None else None)
                if not ai_usage_decision.allowed:
                    response = self._ai_usage_limit_response(ai_usage_decision)
                    if conversation_id is not None:
                        await self.backend_client.create_conversation_message(
                            BackendConversationMessagePayload(
                                conversation_id=conversation_id,
                                direction="outbound",
                                role="assistant",
                                message_type="text",
                                body=response.reply,
                                intent=response.intent,
                                score=self._score_to_integer(response.score),
                                action=response.action,
                                needs_human=response.needs_human,
                                raw_payload=response.model_dump(),
                                metadata=self._outbound_metadata(response, mcp_config),
                            )
                        )
                    return response

                started_at = time.perf_counter()
                shadow_orchestration_result = ShadowOrchestrationResult(data_to_save={})
                if bool(getattr(self.settings, "new_llm_orchestration_enabled", False)):
                    shadow_orchestration_result = await self._apply_shadow_orchestration(
                        payload,
                        routing,
                        backend_context,
                        effective_contact_context,
                        mcp_config,
                        previous_response_id,
                    )

                if shadow_orchestration_result.response is not None:
                    response = self._merge_shadow_data_into_response(
                        shadow_orchestration_result.response,
                        shadow_orchestration_result.data_to_save,
                    )
                    latency_ms = response.latency_ms if response.latency_ms is not None else 0
                else:
                    started_at = time.perf_counter()
                    response = await self.decision_engine.decide(
                        payload,
                        routing=routing,
                        backend_context=backend_context,
                        contact_context=effective_contact_context,
                        mcp_config=mcp_config,
                        previous_response_id=previous_response_id,
                    )
                    decision_latency_ms = int(round((time.perf_counter() - started_at) * 1000))
                    latency_ms = response.latency_ms if response.latency_ms is not None else decision_latency_ms
                    response = self._merge_shadow_data_into_response(response, shadow_orchestration_result.data_to_save)
                response = self._normalize_agenda_timezone_mismatch(response, backend_context, effective_contact_context)
                response = self._normalize_agenda_owner_mismatch(response)
                response = self._normalize_failed_appointment_confirmation(response)
                response = self._normalize_premature_appointment_confirmation(response, normalized_message)
                response = self._normalize_handoff_response(response, explicit_handoff_request)
                response = self._merge_runtime_diagnostics(
                    response,
                    payload,
                    backend_context,
                    effective_contact_context,
                    contact_context_resolver_called,
                )
                response = self._normalize_successful_appointment_confirmation(response, normalized_message)

        response = await self._apply_handoff_policy(
            response,
            payload,
            routing,
            backend_context,
            conversation_result,
            mcp_config,
            explicit_handoff_request,
        )
        response = self._finalize_successful_appointment_confirmation(response)

        if conversation_id is not None:
            outbound_result = await self.backend_client.create_conversation_message(
                BackendConversationMessagePayload(
                    conversation_id=conversation_id,
                    direction="outbound",
                    role="assistant",
                    message_type="text",
                    body=response.reply,
                    provider=response.provider,
                    model=response.model,
                    latency_ms=latency_ms,
                    intent=response.intent,
                    score=self._score_to_integer(response.score),
                    action=response.action,
                    needs_human=response.needs_human,
                    raw_payload=response.model_dump(),
                    metadata=self._outbound_metadata(response, mcp_config),
                )
            )
            await self._report_ai_usage_event(response, routing, conversation_result, outbound_result)
            await self._maybe_generate_conversation_summary(response, conversation_result, outbound_result)
        else:
            await self._report_ai_usage_event(response, routing, conversation_result, None)

        return response

    async def _apply_shadow_orchestration(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
        mcp_config: McpRemoteConfig | None,
        previous_response_id: str | None,
    ) -> ShadowOrchestrationResult:
        data_to_save: dict[str, Any] = {}
        if not bool(getattr(self.settings, "new_llm_orchestration_enabled", False)):
            return ShadowOrchestrationResult(data_to_save=data_to_save)

        data_to_save["new_llm_orchestration_shadow_enabled"] = True

        try:
            trace = await self.shadow_planning_service.execute(
                payload,
                routing,
                backend_context,
                contact_context,
            )
        except Exception as exc:
            data_to_save["new_llm_orchestration_error"] = f"{exc.__class__.__name__}: {exc}"
            return ShadowOrchestrationResult(data_to_save=data_to_save)

        data_to_save["new_llm_orchestration_trace"] = trace.to_safe_dict()
        planning_output = self._shadow_planning_output(trace)
        planning_domain = self._normalize_text(planning_output.get("domain")) if isinstance(planning_output, dict) else None
        planning_intent = self._normalize_text(planning_output.get("intent")) if isinstance(planning_output, dict) else None
        planning_action_candidate = self._normalize_text(planning_output.get("action_candidate")) if isinstance(planning_output, dict) else None
        if planning_domain == "":
            planning_domain = None
        if planning_intent == "":
            planning_intent = None
        if planning_action_candidate == "":
            planning_action_candidate = None
        planning_entities = planning_output.get("entities") if isinstance(planning_output, dict) else None

        data_to_save["new_llm_orchestration_planning_domain"] = planning_domain
        data_to_save["new_llm_orchestration_planning_intent"] = planning_intent
        data_to_save["new_llm_orchestration_planning_action_candidate"] = planning_action_candidate
        if isinstance(planning_entities, dict):
            data_to_save["new_llm_orchestration_planning_entities"] = copy.deepcopy(planning_entities)

        catalog_execution_enabled = bool(getattr(self.settings, "new_llm_orchestration_catalog_execution_enabled", False))
        appointment_availability_enabled = bool(getattr(self.settings, "new_llm_orchestration_appointment_availability_enabled", False))
        slot_selection_enabled = bool(getattr(self.settings, "new_llm_orchestration_slot_selection_enabled", False))

        catalog_applies = catalog_execution_enabled and planning_domain == "catalog"
        availability_applies = (
            appointment_availability_enabled
            and planning_domain == "appointment"
            and planning_action_candidate == "get_availability"
        )
        slot_selection_applies = (
            slot_selection_enabled
            and planning_domain == "appointment"
            and planning_intent == "select_offered_slot"
        )

        data_to_save["new_llm_orchestration_catalog_applies"] = catalog_applies
        data_to_save["new_llm_orchestration_availability_applies"] = availability_applies
        data_to_save["new_llm_orchestration_slot_selection_applies"] = slot_selection_applies

        if catalog_execution_enabled:
            data_to_save.setdefault("new_llm_orchestration_catalog_execution_attempted", False)
            if not catalog_applies:
                data_to_save.setdefault("new_llm_orchestration_catalog_execution_fallback_reason", "planning_domain_not_catalog")
            else:
                data_to_save["new_llm_orchestration_catalog_execution_attempted"] = True
                try:
                    catalog_execution_outcome = await self.catalog_execution_service.execute(
                        payload,
                        routing,
                        backend_context,
                        contact_context,
                        trace,
                        mcp_config,
                        previous_response_id=previous_response_id,
                    )
                except Exception as exc:
                    data_to_save["new_llm_orchestration_catalog_execution_error"] = f"{exc.__class__.__name__}: {exc}"
                    data_to_save.setdefault("new_llm_orchestration_catalog_execution_fallback_reason", "catalog_execution_technical_error")
                    return ShadowOrchestrationResult(data_to_save=data_to_save)
                else:
                    data_to_save["new_llm_orchestration_catalog_execution_trace"] = catalog_execution_outcome.to_safe_dict()
                    data_to_save["new_llm_orchestration_catalog_execution_ok"] = catalog_execution_outcome.ok
                    if catalog_execution_outcome.fallback_reason is not None:
                        data_to_save["new_llm_orchestration_catalog_execution_fallback_reason"] = catalog_execution_outcome.fallback_reason
                    if catalog_execution_outcome.ok and catalog_execution_outcome.reply is not None:
                        data_to_save["new_llm_orchestration_catalog_execution_used"] = True
                        return ShadowOrchestrationResult(
                            data_to_save=data_to_save,
                            response=self._build_shadow_response(
                                reply=catalog_execution_outcome.reply,
                                planning_intent=planning_intent,
                                planning_confidence=self._planning_confidence(planning_output),
                                data_to_save=data_to_save,
                                provider=catalog_execution_outcome.provider,
                                model=catalog_execution_outcome.model,
                                latency_ms=catalog_execution_outcome.latency_ms,
                            ),
                        )

        if appointment_availability_enabled:
            data_to_save.setdefault("new_llm_orchestration_appointment_availability_attempted", False)
            if not availability_applies:
                if planning_domain != "appointment":
                    data_to_save.setdefault("new_llm_orchestration_appointment_availability_fallback_reason", "planning_domain_not_appointment")
                elif planning_action_candidate != "get_availability":
                    data_to_save.setdefault("new_llm_orchestration_appointment_availability_fallback_reason", "planning_action_not_get_availability")
                else:
                    data_to_save.setdefault("new_llm_orchestration_appointment_availability_fallback_reason", "appointment_availability_not_applicable")
            else:
                data_to_save["new_llm_orchestration_appointment_availability_attempted"] = True
                try:
                    appointment_availability_outcome = await self.appointment_availability_execution_service.execute(
                        payload,
                        routing,
                        backend_context,
                        contact_context,
                        trace,
                        mcp_config,
                        previous_response_id=previous_response_id,
                    )
                except Exception as exc:
                    data_to_save["new_llm_orchestration_appointment_availability_error"] = f"{exc.__class__.__name__}: {exc}"
                    data_to_save.setdefault("new_llm_orchestration_appointment_availability_fallback_reason", "appointment_availability_technical_error")
                    return ShadowOrchestrationResult(data_to_save=data_to_save)
                else:
                    data_to_save["new_llm_orchestration_appointment_availability_trace"] = appointment_availability_outcome.to_safe_dict()
                    data_to_save["new_llm_orchestration_appointment_availability_ok"] = appointment_availability_outcome.ok
                    if appointment_availability_outcome.fallback_reason is not None:
                        data_to_save["new_llm_orchestration_appointment_availability_fallback_reason"] = appointment_availability_outcome.fallback_reason
                    if appointment_availability_outcome.ok and appointment_availability_outcome.reply is not None:
                        data_to_save["new_llm_orchestration_appointment_availability_used"] = True
                        persisted_offered_slots = appointment_availability_outcome.offered_slots
                        if not persisted_offered_slots:
                            response_slots = appointment_availability_outcome.response_payload.get("slots")
                            if isinstance(response_slots, list):
                                persisted_offered_slots = response_slots
                        if persisted_offered_slots:
                            data_to_save["new_llm_orchestration_offered_slots"] = copy.deepcopy(persisted_offered_slots)
                            data_to_save["new_llm_orchestration_offered_slots_count"] = len(persisted_offered_slots)
                        return ShadowOrchestrationResult(
                            data_to_save=data_to_save,
                            response=self._build_shadow_response(
                                reply=appointment_availability_outcome.reply,
                                planning_intent=planning_intent,
                                planning_confidence=self._planning_confidence(planning_output),
                                data_to_save=data_to_save,
                                provider=appointment_availability_outcome.provider,
                                model=appointment_availability_outcome.model,
                                latency_ms=appointment_availability_outcome.latency_ms,
                            ),
                        )

        if slot_selection_enabled:
            data_to_save.setdefault("new_llm_orchestration_slot_selection_attempted", False)
            if not slot_selection_applies:
                if planning_domain != "appointment":
                    data_to_save.setdefault("new_llm_orchestration_slot_selection_fallback_reason", "planning_domain_not_appointment")
                elif planning_intent != "select_offered_slot":
                    data_to_save.setdefault("new_llm_orchestration_slot_selection_fallback_reason", "planning_intent_not_select_offered_slot")
                else:
                    data_to_save.setdefault("new_llm_orchestration_slot_selection_fallback_reason", "slot_selection_not_applicable")
            else:
                data_to_save["new_llm_orchestration_slot_selection_attempted"] = True
                try:
                    slot_selection_outcome = await self.slot_selection_execution_service.execute(
                        payload,
                        routing,
                        backend_context,
                        contact_context,
                        trace,
                        mcp_config,
                        previous_response_id=previous_response_id,
                    )
                except Exception as exc:
                    data_to_save["new_llm_orchestration_slot_selection_error"] = f"{exc.__class__.__name__}: {exc}"
                    data_to_save.setdefault("new_llm_orchestration_slot_selection_fallback_reason", "slot_selection_technical_error")
                    return ShadowOrchestrationResult(data_to_save=data_to_save)
                else:
                    data_to_save["new_llm_orchestration_slot_selection_trace"] = slot_selection_outcome.to_safe_dict()
                    data_to_save["new_llm_orchestration_slot_selection_ok"] = slot_selection_outcome.ok
                    if slot_selection_outcome.fallback_reason is not None:
                        data_to_save["new_llm_orchestration_slot_selection_fallback_reason"] = slot_selection_outcome.fallback_reason
                    if slot_selection_outcome.ok and slot_selection_outcome.reply is not None:
                        data_to_save["new_llm_orchestration_slot_selection_used"] = True
                        if slot_selection_outcome.offered_slots:
                            data_to_save["new_llm_orchestration_offered_slots"] = copy.deepcopy(slot_selection_outcome.offered_slots)
                            data_to_save["new_llm_orchestration_offered_slots_count"] = len(slot_selection_outcome.offered_slots)
                        if slot_selection_outcome.selected_slot is not None:
                            data_to_save["new_llm_orchestration_selected_slot"] = copy.deepcopy(slot_selection_outcome.selected_slot)
                        return ShadowOrchestrationResult(
                            data_to_save=data_to_save,
                            response=self._build_shadow_response(
                                reply=slot_selection_outcome.reply,
                                planning_intent=planning_intent,
                                planning_confidence=self._planning_confidence(planning_output),
                                data_to_save=data_to_save,
                                provider=None,
                                model=None,
                                latency_ms=None,
                            ),
                        )

        if appointment_availability_enabled and "new_llm_orchestration_appointment_availability_attempted" not in data_to_save:
            data_to_save["new_llm_orchestration_appointment_availability_attempted"] = False
        if (
            appointment_availability_enabled
            and "new_llm_orchestration_appointment_availability_used" not in data_to_save
            and data_to_save.get("new_llm_orchestration_appointment_availability_attempted") is not True
        ):
            data_to_save["new_llm_orchestration_appointment_availability_used"] = False

        if catalog_execution_enabled and "new_llm_orchestration_catalog_execution_attempted" not in data_to_save:
            data_to_save["new_llm_orchestration_catalog_execution_attempted"] = False
        if (
            catalog_execution_enabled
            and "new_llm_orchestration_catalog_execution_used" not in data_to_save
            and data_to_save.get("new_llm_orchestration_catalog_execution_attempted") is not True
        ):
            data_to_save["new_llm_orchestration_catalog_execution_used"] = False

        if slot_selection_enabled and "new_llm_orchestration_slot_selection_attempted" not in data_to_save:
            data_to_save["new_llm_orchestration_slot_selection_attempted"] = False
        if (
            slot_selection_enabled
            and "new_llm_orchestration_slot_selection_used" not in data_to_save
            and data_to_save.get("new_llm_orchestration_slot_selection_attempted") is not True
        ):
            data_to_save["new_llm_orchestration_slot_selection_used"] = False

        return ShadowOrchestrationResult(data_to_save=data_to_save)

    def _merge_shadow_data_into_response(self, response: AgentResponse, extra_data: dict[str, Any]) -> AgentResponse:
        if not extra_data:
            return response

        merged_data = copy.deepcopy(response.data_to_save)
        merged_data.update(extra_data)
        return AgentResponse(
            reply=response.reply,
            intent=response.intent,
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=merged_data,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _build_shadow_response(
        self,
        reply: str,
        planning_intent: str | None,
        planning_confidence: float,
        data_to_save: dict[str, Any],
        provider: str | None,
        model: str | None,
        latency_ms: int | None,
    ) -> AgentResponse:
        intent = planning_intent or "unknown"
        action = "answer_question"
        score = planning_confidence if planning_confidence > 0 else 0.85
        return AgentResponse(
            reply=reply,
            intent=intent,
            score=score,
            action=action,
            needs_human=False,
            data_to_save=copy.deepcopy(data_to_save),
            provider=provider,
            model=model,
            latency_ms=latency_ms,
        )

    def _planning_confidence(self, planning_output: dict[str, Any]) -> float:
        value = planning_output.get("confidence") if isinstance(planning_output, dict) else None
        if isinstance(value, (int, float)):
            if value < 0:
                return 0.0
            if value > 1:
                return 1.0
            return float(value)
        return 0.0

    def _shadow_planning_output(self, trace: Any) -> dict[str, Any]:
        if not hasattr(trace, "steps"):
            return {}

        for step in trace.steps:
            if getattr(step, "step_type", None) != "llm_intent_planning":
                continue
            output = getattr(step, "output", None)
            if isinstance(output, dict):
                return output

        return {}

    def _missing_routing_data(self, payload: AgentRequest) -> dict[str, str]:
        data = {
            "topic": "missing_routing_context",
            "customer_phone": payload.contact.phone,
        }
        if payload.tenant_id is not None and payload.tenant_id.strip() != "":
            data["tenant_id"] = payload.tenant_id.strip()
        if payload.channel_type is not None and payload.channel_type.strip() != "":
            data["channel_type"] = payload.channel_type.strip()
        if payload.external_channel_id is not None and payload.external_channel_id.strip() != "":
            data["external_channel_id"] = payload.external_channel_id.strip()
        if payload.entrypoint_ref is not None and payload.entrypoint_ref.strip() != "":
            data["entrypoint_ref"] = payload.entrypoint_ref.strip()

        return data

    def _misconfigured_routing_data(self, payload: AgentRequest, routing: RoutingContext) -> dict[str, str]:
        data = {
            "topic": "misconfigured_routing",
            "customer_phone": payload.contact.phone,
            "tenant_id": routing.tenant_id,
        }
        if routing.tenant_slug is not None and routing.tenant_slug.strip() != "":
            data["tenant_slug"] = routing.tenant_slug.strip()
        if routing.external_channel_id is not None and routing.external_channel_id.strip() != "":
            data["external_channel_id"] = routing.external_channel_id.strip()
        if routing.entrypoint_ref is not None and routing.entrypoint_ref.strip() != "":
            data["entrypoint_ref"] = routing.entrypoint_ref.strip()
        if payload.channel_type is not None and payload.channel_type.strip() != "":
            data["channel_type"] = payload.channel_type.strip()

        return data

    def _conversation_id_from_result(self, conversation_result: dict[str, Any] | None) -> str | None:
        if not isinstance(conversation_result, dict):
            return None

        conversation = conversation_result.get("conversation")
        if not isinstance(conversation, dict):
            return None

        conversation_id = conversation.get("id")
        if isinstance(conversation_id, str) and conversation_id.strip() != "":
            return conversation_id.strip()

        return None

    def _should_generate_conversation_summary(self, response: AgentResponse) -> bool:
        if response.needs_human:
            return True

        return response.action == "handoff_to_human"

    async def _maybe_generate_conversation_summary(
        self,
        response: AgentResponse,
        conversation_result: dict[str, Any] | None,
        outbound_result: Any | None,
    ) -> None:
        if self.conversation_summary_service is None:
            return

        if not self._should_generate_conversation_summary(response):
            return

        conversation_id = self._conversation_id_from_result(conversation_result)
        if conversation_id is None:
            return

        try:
            summary = await self.conversation_summary_service.generate_and_persist(conversation_id, reason=response.action or "handoff")
        except Exception:
            logger.warning(
                "Conversation summary generation failed conversation_id=%s response_action=%s",
                conversation_id,
                response.action,
                exc_info=True,
            )
            return

        if summary is None:
            return

        if isinstance(conversation_result, dict):
            conversation = conversation_result.get("conversation")
            if isinstance(conversation, dict):
                conversation["summary"] = summary

    def _previous_response_id_from_conversation_result(self, conversation_result: dict[str, Any] | None) -> str | None:
        if not self.settings.openai_conversation_state_enabled:
            return None

        if not isinstance(conversation_result, dict):
            return None

        conversation = conversation_result.get("conversation")
        if not isinstance(conversation, dict):
            return None

        status = str(conversation.get("status") or "").strip().lower()
        if status != "active":
            return None

        previous_response_id = self._normalize_telemetry_value(
            conversation.get("lastOpenAiResponseId") or conversation.get("last_openai_response_id"),
        )
        if not isinstance(previous_response_id, str) or not previous_response_id.startswith("resp_"):
            return None

        last_response_at = self._parse_datetime_utc(
            conversation.get("lastOpenAiResponseAt") or conversation.get("last_openai_response_at"),
        )
        if last_response_at is None:
            return None

        ttl_hours = max(1, self.settings.openai_conversation_state_ttl_hours)
        if datetime.now(timezone.utc) - last_response_at > timedelta(hours=ttl_hours):
            return None

        return previous_response_id

    def _is_audio_message(self, payload: AgentRequest) -> bool:
        if str(payload.message.type or "").strip().lower() == "audio":
            return True

        media = payload.message.media
        if isinstance(media, dict):
            return str(media.get("kind") or "").strip().lower() == "audio"

        return False

    async def _transcribe_audio_message(
        self,
        payload: AgentRequest,
        audio_configuration: Any | None = None,
        duration_seconds: int | None = None,
    ) -> AudioTranscriptionResult:
        media = payload.message.media if isinstance(payload.message.media, dict) else None
        media_id = ""
        if isinstance(media, dict):
            media_id = str(media.get("media_id") or media.get("mediaId") or "").strip()

        if media_id == "":
            raise RuntimeError("Audio message does not include a media reference.")

        download_result = await self.audio_gateway_client.download_whatsapp_media(media_id)
        transcription_result = await self.audio_transcription_client.transcribe(
            download_result.content,
            download_result.content_type,
            download_result.media_id,
            duration_seconds=duration_seconds,
        )

        payload.message.text = transcription_result.text
        if isinstance(payload.message.media, dict):
            payload.message.media["transcript"] = transcription_result.text
            payload.message.media["transcription_model"] = transcription_result.model
            payload.message.media.setdefault("media_id", download_result.media_id)
            if download_result.content_type is not None:
                payload.message.media.setdefault("content_type", download_result.content_type)

        return transcription_result

    def _inbound_metadata(self, payload: AgentRequest, routing: RoutingContext) -> dict[str, Any]:
        metadata: dict[str, Any] = {
            "channel_type": payload.channel_type,
            "external_channel_id": payload.external_channel_id,
            "entrypoint_ref": payload.entrypoint_ref,
            "contact": payload.contact.model_dump(),
        }

        if payload.message.media is not None:
            metadata["message_media"] = payload.message.media

        if self._is_audio_message(payload):
            metadata["message_original_type"] = "audio"

        if routing.entrypoint_ref is not None and routing.entrypoint_ref.strip() != "":
            metadata["routing_entrypoint_ref"] = routing.entrypoint_ref.strip()

        return metadata

    def _raw_event_field(self, raw_event: Any | None, *names: str) -> str | None:
        if not isinstance(raw_event, dict):
            return None

        for name in names:
            value = raw_event.get(name)
            if isinstance(value, str) and value.strip() != "":
                return value.strip()
            if isinstance(value, (int, float)) and str(value).strip() != "":
                return str(value)

        return None

    def _parse_datetime_utc(self, value: Any) -> datetime | None:
        if not isinstance(value, str) or value.strip() == "":
            return None

        raw_value = value.strip()
        if raw_value.endswith("Z"):
            raw_value = raw_value[:-1] + "+00:00"

        try:
            parsed = datetime.fromisoformat(raw_value)
        except ValueError:
            return None

        if parsed.tzinfo is None:
            parsed = parsed.replace(tzinfo=timezone.utc)

        return parsed.astimezone(timezone.utc)

    def _score_to_integer(self, score: float) -> int | None:
        try:
            return int(round(score * 100))
        except Exception:
            return None

    def _resolve_effective_timezone_details(
        self,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> tuple[str | None, str | None]:
        candidates: list[tuple[str, Any]] = []

        if isinstance(contact_context, dict):
            data = contact_context.get("data")
            if isinstance(data, dict):
                business_context = data.get("business_context") or data.get("businessContext")
                if isinstance(business_context, dict):
                    candidates.append(("contact_context.data.business_context", business_context))
                candidates.append(("contact_context.data", data))

            business_context = contact_context.get("business_context") or contact_context.get("businessContext")
            if isinstance(business_context, dict):
                candidates.append(("contact_context.business_context", business_context))
            candidates.append(("contact_context", contact_context))

        if backend_context is not None:
            candidates.append(("backend_context", backend_context))
            for attribute in ("tenant", "entry_point", "sales_runtime"):
                source = getattr(backend_context, attribute, None)
                if source is not None:
                    candidates.append((attribute, source))

        for source_label, source in candidates:
            timezone_value, timezone_source = self._first_timezone_candidate(source)
            if not isinstance(timezone_value, str):
                continue

            normalized = timezone_value.strip()
            if normalized == "":
                continue

            if not self._is_valid_timezone(normalized):
                continue

            return normalized, timezone_source or source_label

        if isinstance(getattr(self.settings, "default_business_timezone", None), str):
            configured = self.settings.default_business_timezone.strip()
            if configured != "" and self._is_valid_timezone(configured):
                return configured, "settings.default_business_timezone"

        fallback = self.settings.safe_default_business_timezone()
        return fallback, "safety_fallback"

    def _resolve_agenda_effective_timezone_details(
        self,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> tuple[str | None, str | None]:
        timezone, timezone_source = self._resolve_effective_timezone_details(backend_context, contact_context)
        if timezone_source in {"settings.default_business_timezone", "safety_fallback"}:
            return None, timezone_source

        return timezone, timezone_source

    def _first_timezone_candidate(self, source: Any) -> tuple[str | None, str | None]:
        field_names = (
            "branch_timezone",
            "timezone",
            "time_zone",
            "business_timezone",
            "businessTimezone",
            "local_timezone",
            "localTimezone",
            "tenant_timezone",
            "tenantTimezone",
            "crm_timezone",
            "crmTimezone",
            "effective_timezone",
            "effectiveTimezone",
        )
        source_name_fields = ("timezone_source", "timezoneSource", "business_timezone_source", "businessTimezoneSource")

        if isinstance(source, dict):
            for field_name in field_names:
                value = source.get(field_name)
                if isinstance(value, str) and value.strip():
                    return value, self._first_string_value(source, source_name_fields)
            return None, None

        for field_name in field_names:
            value = getattr(source, field_name, None)
            if isinstance(value, str) and value.strip():
                return value, self._first_string_value(source, source_name_fields)

        return None, None

    def _first_string_value(self, source: Any, field_names: tuple[str, ...]) -> str | None:
        if isinstance(source, dict):
            for field_name in field_names:
                value = source.get(field_name)
                if isinstance(value, str) and value.strip():
                    return value.strip()
            return None

        for field_name in field_names:
            value = getattr(source, field_name, None)
            if isinstance(value, str) and value.strip():
                return value.strip()

        return None

    def _is_valid_timezone(self, timezone_name: str) -> bool:
        try:
            ZoneInfo(timezone_name)
        except Exception:
            return False

        return True

    def _normalize_agenda_timezone_mismatch(
        self,
        response: AgentResponse,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
    ) -> AgentResponse:
        effective_timezone, effective_timezone_source = self._resolve_agenda_effective_timezone_details(backend_context, contact_context)

        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list) or traces == []:
            return response

        mismatch_tools: list[str] = []
        agenda_tools = {
            "appointment_availability",
            "appointment_confirm",
            "appointment_reschedule",
            "appointment_cancel",
            "appointment_events",
            "appointment_booking_invitation",
        }
        timezone_required_tools = {
            "appointment_availability",
            "appointment_confirm",
            "appointment_reschedule",
            "appointment_cancel",
            "appointment_booking_invitation",
        }

        for trace in traces:
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(tool_name, str) or tool_name.strip() not in agenda_tools:
                continue

            arguments = trace.get("arguments")
            if not isinstance(arguments, dict):
                arguments = trace.get("input") if isinstance(trace.get("input"), dict) else {}

            if not isinstance(arguments, dict):
                if tool_name.strip() in timezone_required_tools:
                    mismatch_tools.append(tool_name.strip())
                continue

            argument_timezone = arguments.get("timezone")
            if not isinstance(argument_timezone, str) or argument_timezone.strip() == "":
                if tool_name.strip() in timezone_required_tools:
                    mismatch_tools.append(tool_name.strip())
                continue

            if effective_timezone is None and tool_name.strip() in timezone_required_tools:
                mismatch_tools.append(tool_name.strip())
                continue

            if argument_timezone.strip() != effective_timezone:
                mismatch_tools.append(tool_name.strip())

        if mismatch_tools == []:
            return response

        if effective_timezone is None:
            return self._timezone_guardrail_block_response(
                mismatched_tool=mismatch_tools[0],
                reason="missing_effective_timezone",
                timezone_source=effective_timezone_source,
                response=response,
                contact_context_resolver_called=contact_context is not None,
                contact_context=contact_context,
            )

        return self._timezone_guardrail_block_response(
            mismatched_tool=mismatch_tools[0],
            reason="timezone_mismatch",
            timezone_source=effective_timezone_source,
            response=response,
            expected_timezone=effective_timezone,
            contact_context_resolver_called=contact_context is not None,
            contact_context=contact_context,
        )

    def _normalize_agenda_owner_mismatch(
        self,
        response: AgentResponse,
    ) -> AgentResponse:
        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list) or traces == []:
            return response

        agenda_tools = {
            "appointment_availability",
            "appointment_confirm",
            "appointment_reschedule",
            "appointment_booking_invitation",
        }

        for trace in traces:
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(tool_name, str) or tool_name.strip() not in agenda_tools:
                continue

            arguments = trace.get("arguments")
            if not isinstance(arguments, dict):
                arguments = trace.get("input") if isinstance(trace.get("input"), dict) else {}

            if not isinstance(arguments, dict):
                continue

            owner_id = arguments.get("owner_id") or arguments.get("ownerId")
            owner_ref = arguments.get("owner_ref") or arguments.get("ownerRef")

            if owner_id is not None and isinstance(owner_id, str) and self._looks_like_uuid(owner_id):
                continue

            if owner_ref is None:
                continue

            if isinstance(owner_ref, str) and self._looks_like_uuid(owner_ref):
                continue

            cleaned_data = copy.deepcopy(response.data_to_save)
            cleaned_data["owner_guardrail_blocked"] = True
            cleaned_data["owner_guardrail_reason"] = "unresolved_owner_reference"
            cleaned_data["mismatched_tool"] = tool_name.strip()
            cleaned_data["mcp_tool_traces"] = []

            return AgentResponse(
                reply="Necesito identificar correctamente a Claudia en la agenda antes de consultar sus huecos.",
                intent="agenda",
                score=response.score,
                action="ask_question",
                needs_human=False,
                data_to_save=cleaned_data,
                provider=response.provider,
                model=response.model,
                latency_ms=response.latency_ms,
            )

        return response

    def _timezone_guardrail_block_response(
        self,
        *,
        mismatched_tool: str,
        reason: str,
        timezone_source: str | None = None,
        response: AgentResponse | None = None,
        expected_timezone: str | None = None,
        contact_context_resolver_called: bool | None = None,
        contact_context: dict[str, Any] | None = None,
    ) -> AgentResponse:
        data_to_save = copy.deepcopy(response.data_to_save) if response is not None else {}
        data_to_save["timezone_guardrail_blocked"] = True
        data_to_save["timezone_guardrail_reason"] = reason
        data_to_save["mismatched_tool"] = mismatched_tool
        data_to_save["mcp_tool_traces"] = []
        if timezone_source is not None:
            data_to_save["timezone_expected_source"] = timezone_source
        if expected_timezone is not None:
            data_to_save["timezone_expected"] = expected_timezone
        if contact_context_resolver_called is not None:
            data_to_save["contact_context_resolver_called"] = contact_context_resolver_called

        contact_context_available, contact_context_source, contact_context_error_code = self._contact_context_diagnostics(contact_context)
        if contact_context_error_code is None and contact_context_resolver_called:
            contact_context_error_code = "unknown"
        data_to_save["contact_context_available"] = contact_context_available
        data_to_save["contact_context_source"] = contact_context_source
        data_to_save["contact_context_error_code"] = contact_context_error_code
        data_to_save["contact_context_cache_lookup"] = self._contact_context_bool_field(contact_context, "cache_lookup")
        data_to_save["contact_context_cache_hit"] = self._contact_context_bool_field(contact_context, "cache_hit")
        data_to_save["contact_context_mcp_available"] = self._contact_context_bool_field(contact_context, "mcp_available")
        data_to_save["contact_context_mcp_called"] = self._contact_context_bool_field(contact_context, "mcp_called")
        data_to_save["contact_context_external_tool_available"] = self._contact_context_bool_field(contact_context, "external_tool_available")
        data_to_save["contact_context_external_tool_called"] = self._contact_context_bool_field(contact_context, "external_tool_called")
        contact_context_error_message = self._contact_context_text_field(contact_context, "error_message")
        data_to_save["contact_context_error_message"] = contact_context_error_message
        effective_timezone, effective_timezone_source = self._resolve_agenda_effective_timezone_details(None, contact_context)
        if effective_timezone is not None:
            data_to_save["effective_timezone"] = effective_timezone
            if effective_timezone_source is not None:
                data_to_save["effective_timezone_source"] = effective_timezone_source
        else:
            technical_timezone, technical_timezone_source = self._technical_fallback_timezone_details()
            if technical_timezone is not None:
                data_to_save["technical_fallback_timezone"] = technical_timezone
            if technical_timezone_source is not None:
                data_to_save["technical_fallback_timezone_source"] = technical_timezone_source

        if reason == "missing_effective_timezone":
            reply = "Necesito comprobar la configuración de agenda del negocio antes de consultar huecos. ¿Me confirmas de qué centro o zona se trata?"
        else:
            reply = "No he podido procesar la agenda con la zona horaria correcta. Repite la disponibilidad y la recalcularé con la zona del negocio."

        return AgentResponse(
            reply=reply,
            intent="agenda",
            score=response.score if response is not None else 0.0,
            action="ask_question",
            needs_human=False,
            data_to_save=data_to_save,
            provider=response.provider if response is not None else None,
            model=response.model if response is not None else None,
            latency_ms=response.latency_ms if response is not None else None,
        )

    def _contact_context_diagnostics(self, contact_context: dict[str, Any] | None) -> tuple[bool, str, str | None]:
        if not isinstance(contact_context, dict):
            return False, "none", None

        available = bool(contact_context.get("available", False) or contact_context.get("ok", False) or contact_context.get("found", False))
        source = self._normalize_text_value(contact_context.get("source"))
        if source is None:
            source = self._normalize_text_value(contact_context.get("cache_source"))
        if source is None:
            source = "contact_context"

        error_code = self._normalize_text_value(contact_context.get("error_code"))
        return available, source, error_code

    def _contact_context_bool_field(self, contact_context: dict[str, Any] | None, field_name: str) -> bool:
        if not isinstance(contact_context, dict):
            return False

        return bool(contact_context.get(field_name, False))

    def _contact_context_text_field(self, contact_context: dict[str, Any] | None, field_name: str) -> str | None:
        if not isinstance(contact_context, dict):
            return None

        value = contact_context.get(field_name)
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None

    def _merge_runtime_diagnostics(
        self,
        response: AgentResponse,
        payload: AgentRequest,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None,
        contact_context_resolver_called: bool,
    ) -> AgentResponse:
        data_to_save = copy.deepcopy(response.data_to_save)

        def set_if_missing(key: str, value: Any) -> None:
            if key not in data_to_save or data_to_save[key] in (None, ""):
                data_to_save[key] = value

        set_if_missing("contact_context_resolver_called", contact_context_resolver_called)

        contact_available, contact_source, contact_error_code = self._contact_context_diagnostics(contact_context)
        set_if_missing("contact_context_available", contact_available)
        set_if_missing("contact_context_source", contact_source)
        set_if_missing("contact_context_error_code", contact_error_code)
        set_if_missing("contact_context_error_message", self._contact_context_text_field(contact_context, "error_message"))
        set_if_missing("contact_context_cache_lookup", self._contact_context_bool_field(contact_context, "cache_lookup"))
        set_if_missing("contact_context_cache_hit", self._contact_context_bool_field(contact_context, "cache_hit"))
        set_if_missing("contact_context_mcp_available", self._contact_context_bool_field(contact_context, "mcp_available"))
        set_if_missing("contact_context_mcp_called", self._contact_context_bool_field(contact_context, "mcp_called"))
        set_if_missing("contact_context_external_tool_available", self._contact_context_bool_field(contact_context, "external_tool_available"))
        set_if_missing("contact_context_external_tool_called", self._contact_context_bool_field(contact_context, "external_tool_called"))

        effective_timezone, effective_timezone_source = self._resolve_agenda_effective_timezone_details(backend_context, contact_context)
        technical_timezone, technical_timezone_source = self._technical_fallback_timezone_details()
        actual_timezone = self._agenda_tool_timezone_from_traces(response.data_to_save.get("mcp_tool_traces"))
        if actual_timezone is None and effective_timezone is not None:
            actual_timezone = effective_timezone

        set_if_missing("effective_timezone", effective_timezone)
        set_if_missing("effective_timezone_source", effective_timezone_source)
        set_if_missing("technical_fallback_timezone", technical_timezone)
        set_if_missing("technical_fallback_timezone_source", technical_timezone_source)
        set_if_missing("actual_timezone", actual_timezone)
        set_if_missing("expected_timezone", effective_timezone)
        set_if_missing("timezone_mismatch_detected", bool(
            effective_timezone is not None
            and actual_timezone is not None
            and effective_timezone != actual_timezone
        ))
        set_if_missing("timezone_guardrail_blocked", False)
        set_if_missing("timezone_guardrail_reason", None)
        set_if_missing("mismatched_tool", None)

        operational_context = self._operational_context_payload(payload, effective_timezone, effective_timezone_source, contact_context)
        if operational_context is not None:
            set_if_missing("operational_context", operational_context)

        return AgentResponse(
            reply=response.reply,
            intent=response.intent,
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _agenda_tool_timezone_from_traces(self, traces: Any) -> str | None:
        if not isinstance(traces, list):
            return None

        agenda_tools = {
            "appointment_availability",
            "appointment_confirm",
            "appointment_reschedule",
            "appointment_cancel",
            "appointment_booking_invitation",
        }

        for trace in reversed(traces):
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(tool_name, str) or tool_name.strip() not in agenda_tools:
                continue

            arguments = trace.get("arguments")
            if not isinstance(arguments, dict):
                arguments = trace.get("input") if isinstance(trace.get("input"), dict) else {}

            if not isinstance(arguments, dict):
                continue

            timezone = arguments.get("timezone")
            if isinstance(timezone, str) and timezone.strip() != "":
                return timezone.strip()

        return None

    def _operational_context_payload(
        self,
        payload: AgentRequest,
        effective_timezone: str | None,
        effective_timezone_source: str | None,
        contact_context: dict[str, Any] | None,
    ) -> dict[str, Any] | None:
        contact_context_source = None
        if isinstance(contact_context, dict):
            contact_context_source = self._normalize_text_value(contact_context.get("source"))
            if contact_context_source is None:
                contact_context_source = self._normalize_text_value(contact_context.get("cache_source"))

        contact_context_available, _, _ = self._contact_context_diagnostics(contact_context)
        channel = self._normalize_text_value(payload.conversation.channel) or self._normalize_text_value(payload.channel_type)

        payload_data: dict[str, Any] = {
            "tenant_id": self._normalize_text_value(payload.tenant_id),
            "channel": channel,
            "contact": {
                "phone": self._normalize_text_value(payload.contact.phone),
                "email": self._normalize_text_value(payload.contact.email),
                "name": self._normalize_text_value(payload.contact.name),
            },
            "effective_timezone": effective_timezone,
            "appointment_tool_timezone": effective_timezone,
            "contact_context_available": contact_context_available,
        }

        if effective_timezone_source is not None:
            payload_data["effective_timezone_source"] = effective_timezone_source
        if contact_context_source is not None:
            payload_data["contact_context_source"] = contact_context_source

        return payload_data

    def _technical_fallback_timezone_details(self) -> tuple[str | None, str | None]:
        configured = getattr(self.settings, "default_business_timezone", None)
        if isinstance(configured, str):
            normalized = configured.strip()
            if normalized != "" and self._is_valid_timezone(normalized):
                return normalized, "settings.default_business_timezone"

        fallback = self.settings.safe_default_business_timezone()
        if isinstance(fallback, str) and fallback.strip() != "":
            return fallback.strip(), "safety_fallback"

        return None, None

    def _looks_like_uuid(self, value: str) -> bool:
        normalized = value.strip()
        if normalized == "":
            return False

        uuid_regex = re.compile(r"^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$")
        return bool(uuid_regex.match(normalized))

    def _mcp_agenda_tools_available(self, mcp_config: McpRemoteConfig | None) -> bool:
        if mcp_config is None or not mcp_config.enabled:
            return False

        return any(
            tool.strip().startswith("appointment_")
            for tool in mcp_config.allowed_tools
        )

    def _recent_contact_context_from_messages(self, context_messages: list[dict[str, Any]] | None) -> dict[str, Any] | None:
        if not isinstance(context_messages, list):
            return None

        for message in reversed(context_messages):
            if not isinstance(message, dict):
                continue

            metadata = message.get("metadata")
            if not isinstance(metadata, dict):
                continue

            stored_contact_context = metadata.get("contact_context")
            if isinstance(stored_contact_context, dict):
                timezone, _ = self._resolve_effective_timezone_details(None, stored_contact_context)
                if timezone is not None:
                    return stored_contact_context

            data_to_save = metadata.get("data_to_save")
            if not isinstance(data_to_save, dict):
                data_to_save = metadata

            if not isinstance(data_to_save, dict):
                continue

            traces = metadata.get("mcp_tool_traces")
            if isinstance(traces, list):
                for trace in reversed(traces):
                    if not isinstance(trace, dict):
                        continue

                    tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
                    if not isinstance(tool_name, str) or tool_name.strip() != "appointment_availability":
                        continue

                    output = trace.get("output")
                    if not isinstance(output, dict):
                        output = trace.get("raw") if isinstance(trace.get("raw"), dict) else {}
                    if not isinstance(output, dict):
                        continue

                    timezone = output.get("timezone")
                    if not isinstance(timezone, str) or timezone.strip() == "":
                        slots = output.get("slots")
                        if isinstance(slots, list) and slots:
                            first_slot = slots[0]
                            if isinstance(first_slot, dict):
                                timezone = first_slot.get("timezone") if isinstance(first_slot.get("timezone"), str) else None

                    if not isinstance(timezone, str) or timezone.strip() == "":
                        continue

                    timezone_source = output.get("timezone_source")
                    if not isinstance(timezone_source, str) or timezone_source.strip() == "":
                        timezone_source = "appointment_availability"

                    contact_context: dict[str, Any] = {
                        "available": bool(output.get("available", False)),
                        "configured": bool(output.get("configured", True)),
                        "provider": self._normalize_text_value(metadata.get("provider")),
                        "ok": bool(output.get("ok", output.get("available", False))),
                        "found": bool(output.get("ok", output.get("available", False))),
                        "error_code": self._normalize_text_value(output.get("error_code")),
                        "data": {
                            "source": "appointment_availability",
                            "summary": self._normalize_text_value(output.get("summary")),
                            "timezone": timezone.strip(),
                            "timezone_source": self._normalize_text_value(timezone_source),
                            "needs_branch_selection": False,
                            "flags": {},
                        },
                    }
                    contact_context["data"]["business_context"] = {
                        "timezone": timezone.strip(),
                        "timezone_source": self._normalize_text_value(timezone_source),
                        "needs_branch_selection": False,
                    }
                    return contact_context

            timezone = data_to_save.get("external_context_timezone") or data_to_save.get("external_business_context_timezone")
            timezone_source = (
                data_to_save.get("external_context_timezone_source")
                or data_to_save.get("external_business_context_timezone_source")
            )
            needs_branch_selection = bool(
                data_to_save.get("external_context_needs_branch_selection", False)
                or data_to_save.get("external_business_context_needs_branch_selection", False)
            )

            if not isinstance(timezone, str) or timezone.strip() == "":
                continue

            contact_context: dict[str, Any] = {
                "available": bool(data_to_save.get("external_context_available", False)),
                "configured": bool(data_to_save.get("external_context_configured", False)),
                "provider": self._normalize_text_value(data_to_save.get("external_context_provider")),
                "ok": bool(data_to_save.get("external_context_available", False)),
                "found": bool(data_to_save.get("external_context_available", False)),
                "error_code": self._normalize_text_value(data_to_save.get("external_context_error_code")),
                "data": {
                    "source": self._normalize_text_value(data_to_save.get("external_context_source")),
                    "summary": self._normalize_text_value(data_to_save.get("external_context_summary")),
                    "timezone": timezone.strip(),
                    "timezone_source": self._normalize_text_value(timezone_source),
                    "needs_branch_selection": False,
                    "contact": {
                        "name": self._normalize_text_value(data_to_save.get("external_contact_name")),
                        "phone": self._normalize_text_value(data_to_save.get("external_contact_phone")),
                        "email": self._normalize_text_value(data_to_save.get("external_contact_email")),
                        "status": self._normalize_text_value(data_to_save.get("external_contact_status")),
                        "stage": self._normalize_text_value(data_to_save.get("external_contact_stage")),
                        "owner": self._normalize_text_value(data_to_save.get("external_contact_owner")),
                    },
                    "flags": {
                        "needs_human": bool(data_to_save.get("external_flag_needs_human", False)),
                        "do_not_contact": bool(data_to_save.get("external_flag_do_not_contact", False)),
                        "existing_customer": bool(data_to_save.get("external_flag_existing_customer", False)),
                    },
                },
            }

            branch = data_to_save.get("external_context_branch") or data_to_save.get("external_business_context_branch")
            if isinstance(branch, (dict, str)):
                contact_context["data"]["branch"] = branch

            selected_branch = data_to_save.get("external_context_selected_branch") or data_to_save.get("external_business_context_selected_branch")
            if isinstance(selected_branch, (dict, str)):
                contact_context["data"]["selected_branch"] = selected_branch

            branches = data_to_save.get("external_context_branches") or data_to_save.get("external_business_context_branches")
            if isinstance(branches, list):
                contact_context["data"]["branches"] = branches

            business_timezone = data_to_save.get("external_business_context_timezone")
            if isinstance(business_timezone, str) and business_timezone.strip() != "":
                contact_context["data"]["business_context"] = {
                    "timezone": business_timezone.strip(),
                    "timezone_source": self._normalize_text_value(data_to_save.get("external_business_context_timezone_source")),
                    "needs_branch_selection": bool(data_to_save.get("external_business_context_needs_branch_selection", False)),
                }
                business_branch = data_to_save.get("external_business_context_branch")
                if isinstance(business_branch, (dict, str)):
                    contact_context["data"]["business_context"]["branch"] = business_branch
                business_selected_branch = data_to_save.get("external_business_context_selected_branch")
                if isinstance(business_selected_branch, (dict, str)):
                    contact_context["data"]["business_context"]["selected_branch"] = business_selected_branch
                business_branches = data_to_save.get("external_business_context_branches")
                if isinstance(business_branches, list):
                    contact_context["data"]["business_context"]["branches"] = business_branches

            return contact_context

        return None

    async def _resolve_contact_context(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        conversation_id: str | None,
        mcp_config: McpRemoteConfig | None,
    ) -> dict[str, Any] | None:
        if self.contact_context_resolver is None:
            return None

        recent_contact_context = self._recent_contact_context_from_messages(payload.conversation.context_messages)
        return await self.contact_context_resolver.resolve(
            payload,
            backend_context,
            mcp_config,
            recent_contact_context=recent_contact_context,
        )

    def _effective_contact_context(
        self,
        payload: AgentRequest,
        contact_context: dict[str, Any] | None,
    ) -> dict[str, Any] | None:
        if isinstance(contact_context, dict):
            return contact_context

        return self._recent_contact_context_from_messages(payload.conversation.context_messages)

    def _publish_contact_context_to_conversation_context(
        self,
        payload: AgentRequest,
        contact_context: dict[str, Any] | None,
    ) -> None:
        if not isinstance(contact_context, dict):
            return

        context_messages = payload.conversation.context_messages
        if not isinstance(context_messages, list):
            payload.conversation.context_messages = []
            context_messages = payload.conversation.context_messages

        context_messages.append(
            {
                "id": f"contact-context-{uuid.uuid4()}",
                "direction": "outbound",
                "role": "assistant",
                "message_type": "text",
                "body": "Contexto externo resuelto.",
                "metadata": {
                    "contact_context": copy.deepcopy(contact_context),
                },
            }
        )

    def _should_preload_contact_context(self, payload: AgentRequest, normalized_message: str) -> bool:
        if self._agenda_message_kind(normalized_message) is not None:
            return True

        if self._is_slot_selection_followup(normalized_message):
            return True

        if self._recent_contact_context_from_messages(payload.conversation.context_messages) is not None:
            return True

        return False

    def _agenda_message_kind(self, message: str) -> str | None:
        normalized = message.lower().strip()

        lookup_keywords = (
            "consulta mi agenda",
            "consulta la agenda",
            "consultar mi agenda",
            "ver mi agenda",
            "ver agenda",
            "mis citas",
            "mis reuniones",
            "tengo citas",
            "tengo cita",
            "citas programadas",
            "reuniones programadas",
            "próximas citas",
            "proximas citas",
            "qué citas tengo",
            "que citas tengo",
            "agenda de",
        )
        if any(keyword in normalized for keyword in lookup_keywords):
            return "lookup"

        booking_keywords = (
            "agendar",
            "reservar",
            "reserva",
            "booking",
            "appointment",
            "disponibilidad",
            "huecos",
            "horarios",
            "demo",
            "agenda",
            "cita",
            "citas",
            "reunión",
            "reunion",
        )
        if any(keyword in normalized for keyword in booking_keywords):
            return "booking"

        return None

    def _normalize_text_value(self, value: Any) -> str | None:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None

    def _log_mcp_config(self, tenant_id: str, mcp_config: McpRemoteConfig | None) -> None:
        if mcp_config is None:
            logger.info("Runtime MCP config resolved tenant_id=%s mcp_config=null", tenant_id)
            return

        logger.info(
            "Runtime MCP config resolved tenant_id=%s enabled=%s server_label=%s server_url=%s allowed_tools=%d require_approval=%s downstream_authorization_configured=%s error_code=%s",
            tenant_id,
            mcp_config.enabled,
            (mcp_config.server_label or "-").strip() or "-",
            (mcp_config.server_url or "-").strip() or "-",
            len(mcp_config.allowed_tools),
            (mcp_config.require_approval or "-").strip() or "-",
            mcp_config.downstream_authorization_configured,
            (mcp_config.error_code or "-").strip() or "-",
        )

    def _outbound_metadata(self, response: AgentResponse, mcp_config: McpRemoteConfig | None) -> dict[str, Any]:
        metadata: dict[str, Any] = {
            "data_to_save": response.data_to_save,
        }

        telemetry_keys = (
            "provider",
            "model",
            "response_id",
            "input_tokens",
            "output_tokens",
            "cached_tokens",
            "total_tokens",
            "prompt_tokens",
            "completion_tokens",
            "latency_ms",
            "estimated_cost",
            "llm_provider",
            "llm_model",
            "llm_response_id",
            "llm_usage",
            "llm_estimated_cost",
            "llm_latency_ms",
        )
        for key in telemetry_keys:
            if key in response.data_to_save:
                metadata[key] = response.data_to_save.get(key)

        if mcp_config is None:
            return metadata

        metadata["mcp_enabled"] = bool(mcp_config.enabled)
        if mcp_config.server_label is not None and mcp_config.server_label.strip() != "":
            metadata["mcp_server_label"] = mcp_config.server_label.strip()
        if mcp_config.server_url is not None and mcp_config.server_url.strip() != "":
            metadata["mcp_server_url"] = mcp_config.server_url.strip()
        if mcp_config.allowed_tools != []:
            metadata["mcp_allowed_tools"] = mcp_config.allowed_tools
        if mcp_config.require_approval is not None and mcp_config.require_approval.strip() != "":
            metadata["mcp_require_approval"] = mcp_config.require_approval.strip()
        if mcp_config.error_code is not None and mcp_config.error_code.strip() != "":
            metadata["mcp_error_code"] = mcp_config.error_code.strip()
        if mcp_config.error_message is not None and mcp_config.error_message.strip() != "":
            metadata["mcp_error_message"] = mcp_config.error_message.strip()

        if mcp_config.enabled and response.provider != "openai":
            metadata["mcp_skipped_reason"] = "provider_not_supported"

        if isinstance(response.data_to_save.get("mcp_tool_traces"), list) and response.data_to_save["mcp_tool_traces"] != []:
            metadata["mcp_tool_traces"] = response.data_to_save["mcp_tool_traces"]
        if "mcp_response_id" in response.data_to_save:
            metadata["mcp_response_id"] = response.data_to_save["mcp_response_id"]
        if isinstance(response.data_to_save.get("mcp_errors"), list) and response.data_to_save["mcp_errors"] != []:
            metadata["mcp_errors"] = response.data_to_save["mcp_errors"]

        return metadata

    async def _apply_handoff_policy(
        self,
        response: AgentResponse,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        conversation_result: dict[str, Any] | None,
        mcp_config: McpRemoteConfig | None,
        explicit_handoff_request: bool,
    ) -> AgentResponse:
        if not self._should_apply_handoff(response, backend_context):
            return response

        tenant = backend_context.tenant if backend_context is not None else None
        handoff_config = self._handoff_config_from_tenant(tenant)

        if handoff_config is None:
            return response

        updated_reply = response.reply
        strategy = str(handoff_config.get("strategy") or "").strip().lower()
        if strategy == "manual_wa_link":
            if explicit_handoff_request:
                updated_reply = self._build_direct_handoff_reply(handoff_config)
            else:
                updated_reply = self._append_handoff_link(updated_reply, handoff_config)
        elif strategy == "manual_wa_link_and_n8n" and (explicit_handoff_request or response.needs_human or response.action == "handoff_to_human"):
            updated_reply = self._append_handoff_link(updated_reply, handoff_config)

        if self._handoff_allows_webhook(handoff_config) and not self._response_used_handoff_request_tool(response):
            await self._send_handoff_webhook(
                response,
                payload,
                routing,
                backend_context,
                conversation_result,
                mcp_config,
                handoff_config,
            )

        if updated_reply == response.reply:
            return response

        return AgentResponse(
            reply=updated_reply,
            intent=response.intent,
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=response.data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _response_used_handoff_request_tool(self, response: AgentResponse) -> bool:
        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list):
            return False

        for trace in traces:
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if isinstance(tool_name, str) and tool_name.strip() == "handoff_request":
                return True

        return False

    def _should_apply_handoff(self, response: AgentResponse, backend_context: CommercialContext | None) -> bool:
        if backend_context is None:
            return False

        if response.needs_human:
            return True

        return response.action in {"handoff_to_human", "ai_usage_limit_reached", "missing_routing_context"}

    def _handoff_config_from_tenant(self, tenant: Any | None) -> dict[str, Any] | None:
        if tenant is None:
            return None

        handoff = getattr(tenant, "handoff", None)
        if not isinstance(handoff, dict):
            return None

        enabled = bool(handoff.get("enabled", False))
        strategy = self._normalize_handoff_strategy(handoff.get("strategy"))
        if not enabled or strategy == "disabled":
            return None

        return {
            "enabled": enabled,
            "strategy": strategy,
            "whatsapp_public": self._normalize_handoff_phone(handoff.get("whatsapp_public")),
            "message": self._normalize_text(handoff.get("message")),
        }

    def _handoff_allows_manual_link(self, handoff_config: dict[str, Any]) -> bool:
        strategy = str(handoff_config.get("strategy") or "").strip().lower()
        return strategy in {"manual_wa_link", "manual_wa_link_and_n8n"}

    def _handoff_allows_webhook(self, handoff_config: dict[str, Any]) -> bool:
        strategy = str(handoff_config.get("strategy") or "").strip().lower()
        return strategy in {"n8n_webhook", "manual_wa_link_and_n8n"}

    def _append_handoff_link(self, reply: str, handoff_config: dict[str, Any]) -> str:
        phone = self._normalize_handoff_phone(handoff_config.get("whatsapp_public"))
        if phone == "":
            return reply

        if "wa.me/" in reply:
            return reply

        message = self._normalize_text(handoff_config.get("message"))
        if message == "":
            message = "Prefiero que esto lo revise una persona del equipo. Te contactarán lo antes posible."

        link = f"https://wa.me/{phone}"
        if reply.strip() == "":
            return f"{message} {link}"

        separator = " " if not reply.endswith((" ", "\n")) else ""
        return f"{reply}{separator}{message} {link}"

    def _build_direct_handoff_reply(self, handoff_config: dict[str, Any]) -> str:
        message = self._normalize_text(handoff_config.get("message"))
        if message == "":
            message = "Prefiero que esto lo revise una persona del equipo. Te contactarán lo antes posible."

        phone = self._normalize_handoff_phone(handoff_config.get("whatsapp_public"))
        if phone == "":
            return message

        link = f"https://wa.me/{phone}"
        if link in message or "wa.me/" in message:
            return message

        return f"{message} {link}"

    def _build_local_handoff_response(self, handoff_config: dict[str, Any]) -> AgentResponse:
        return AgentResponse(
            reply=self._build_direct_handoff_reply(handoff_config),
            intent="handoff",
            score=1.0,
            action="handoff_to_human",
            needs_human=True,
            data_to_save={
                "local_response_short_circuited": True,
                "handoff_short_circuited": True,
            },
            provider="rule_based",
            model=None,
            latency_ms=0,
        )

    def _normalize_handoff_response(self, response: AgentResponse, explicit_handoff_request: bool) -> AgentResponse:
        if not explicit_handoff_request:
            return response

        if response.action != "handoff_to_human" or not response.needs_human:
            return response

        if response.intent == "handoff":
            return response

        return AgentResponse(
            reply=response.reply,
            intent="handoff",
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=response.data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _normalize_failed_appointment_confirmation(self, response: AgentResponse) -> AgentResponse:
        if self._should_skip_appointment_confirmation_postprocessing(response):
            return response

        if not self._appointment_confirmation_failed(response):
            return response

        reply = (response.reply or "").lower()
        if not any(keyword in reply for keyword in ("reserv", "confirmad", "he reservado", "ya está", "listo")):
            return response

        return AgentResponse(
            reply="No he podido confirmar la cita. Si quieres, puedo intentar otro horario o pasar el caso a una persona del equipo.",
            intent=response.intent,
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=response.data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _normalize_premature_appointment_confirmation(self, response: AgentResponse, normalized_message: str) -> AgentResponse:
        if self._should_skip_appointment_confirmation_postprocessing(response):
            return response

        if not self._is_slot_selection_followup(normalized_message):
            return response

        if self._appointment_confirmation_succeeded(response):
            return response

        reply = (response.reply or "").lower()
        if not any(keyword in reply for keyword in ("reserv", "confirmad", "he reservado", "ya está", "listo", "te reservo")):
            return response

        return AgentResponse(
            reply="Perfecto. Estoy revisando ese horario. Si necesito algún dato adicional para cerrarlo, te lo pediré ahora.",
            intent=response.intent,
            score=response.score,
            action=response.action,
            needs_human=response.needs_human,
            data_to_save=response.data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _normalize_successful_appointment_confirmation(self, response: AgentResponse, normalized_message: str) -> AgentResponse:
        if self._should_skip_appointment_confirmation_postprocessing(response):
            return response

        if not self._appointment_confirmation_succeeded(response):
            return response

        reply = self._successful_appointment_confirmation_reply(response)
        data_to_save = copy.deepcopy(response.data_to_save)
        data_to_save["appointment_confirmation_status"] = "confirmed"
        data_to_save["appointment_confirmed"] = True

        return AgentResponse(
            reply=reply,
            intent="agenda",
            score=response.score,
            action="completed",
            needs_human=False,
            data_to_save=data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _finalize_successful_appointment_confirmation(self, response: AgentResponse) -> AgentResponse:
        if self._should_skip_appointment_confirmation_postprocessing(response):
            return response

        if not self._appointment_confirmation_succeeded(response):
            return response

        reply = self._successful_appointment_confirmation_reply(response)
        data_to_save = copy.deepcopy(response.data_to_save)
        data_to_save["appointment_confirmation_status"] = "confirmed"
        data_to_save["appointment_confirmed"] = True
        data_to_save["appointment_confirm_post_processed"] = True

        return AgentResponse(
            reply=reply,
            intent="agenda",
            score=response.score,
            action="completed",
            needs_human=False,
            data_to_save=data_to_save,
            provider=response.provider,
            model=response.model,
            latency_ms=response.latency_ms,
        )

    def _successful_appointment_confirmation_reply(self, response: AgentResponse) -> str:
        trace = self._appointment_confirmation_trace(response)
        output = self._appointment_confirmation_output(trace)
        if output is None:
            return "Perfecto, tu cita quedó confirmada correctamente."

        start = self._normalize_text_value(
            output.get("start")
            or output.get("startAt")
            or self._appointment_confirmation_value_from_nested(output, ("appointment", "startAt"))
            or self._appointment_confirmation_value_from_nested(output, ("appointment", "start"))
        )
        title = self._normalize_text_value(
            output.get("title")
            or self._appointment_confirmation_value_from_nested(output, ("appointment", "service", "name"))
            or self._appointment_confirmation_value_from_nested(output, ("service", "name"))
        )
        message = self._normalize_text_value(output.get("message"))
        owner_name = self._extract_appointment_owner_name(trace, output)

        detail_segments: list[str] = []
        if start is not None:
            formatted_slot = self._format_appointment_datetime(start)
            if formatted_slot is not None:
                detail_segments.append(f"para {formatted_slot}")
            else:
                detail_segments.append(f"para {start}")

        if owner_name is not None:
            detail_segments.append(f"con {owner_name}")

        if title is not None:
            service_name = title
            prefix = "Cita para "
            if service_name.lower().startswith(prefix.lower()):
                service_name = service_name[len(prefix):].strip()
            if service_name != "":
                detail_segments.append(f"para {service_name}")

        if detail_segments:
            reply = f"Perfecto, tu cita queda confirmada {' '.join(detail_segments)}."
            return reply

        if message is not None and message != "":
            return message

        return "Perfecto, tu cita queda confirmada correctamente."

    def _appointment_confirmation_trace(self, response: AgentResponse) -> dict[str, Any] | None:
        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list):
            return None

        for trace in reversed(traces):
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if isinstance(tool_name, str) and tool_name.strip() == "appointment_confirm":
                return trace

        return None

    def _appointment_confirmation_output(self, trace: dict[str, Any] | None) -> dict[str, Any] | None:
        if not isinstance(trace, dict):
            return None

        for candidate in (trace.get("output"), self._trace_raw_output(trace)):
            parsed = self._coerce_json_object(candidate)
            if isinstance(parsed, dict):
                return parsed

        return None

    def _trace_raw_output(self, trace: dict[str, Any]) -> Any:
        raw = trace.get("raw")
        if isinstance(raw, str):
            return raw
        if not isinstance(raw, dict):
            return None

        return raw.get("output", raw)

    def _coerce_json_object(self, value: Any) -> dict[str, Any] | None:
        if isinstance(value, dict):
            return value

        if isinstance(value, str) and value.strip() != "":
            try:
                parsed = json.loads(value)
            except Exception:
                return None

            if isinstance(parsed, dict):
                return parsed

        return None

    def _appointment_confirmation_value_from_nested(self, data: dict[str, Any], path: tuple[str, ...]) -> Any:
        current: Any = data
        for key in path:
            if not isinstance(current, dict):
                return None
            current = current.get(key)
        return current

    def _extract_appointment_owner_name(self, trace: dict[str, Any], output: dict[str, Any]) -> str | None:
        candidates = (
            output.get("owner_name"),
            output.get("ownerName"),
            output.get("professional_name"),
            output.get("professionalName"),
            output.get("agent_name"),
            output.get("agentName"),
            output.get("staff_name"),
            output.get("staffName"),
            self._appointment_confirmation_value_from_nested(output, ("appointment", "ownerName")),
            self._appointment_confirmation_value_from_nested(output, ("appointment", "owner_name")),
            self._appointment_confirmation_value_from_nested(output, ("appointment", "owner", "name")),
        )
        for candidate in candidates:
            normalized = self._normalize_text_value(candidate)
            if normalized is not None:
                return normalized

        arguments = trace.get("arguments")
        if isinstance(arguments, dict):
            for candidate in (
                arguments.get("owner_name"),
                arguments.get("ownerName"),
                arguments.get("professional_name"),
                arguments.get("professionalName"),
                arguments.get("agent_name"),
                arguments.get("agentName"),
                arguments.get("staff_name"),
                arguments.get("staffName"),
            ):
                normalized = self._normalize_text_value(candidate)
                if normalized is not None:
                    return normalized

        return None

    def _format_appointment_datetime(self, value: str) -> str | None:
        try:
            parsed = datetime.fromisoformat(value)
        except Exception:
            return None

        if parsed.tzinfo is None:
            return None

        weekday_names = (
            "lunes",
            "martes",
            "miércoles",
            "jueves",
            "viernes",
            "sábado",
            "domingo",
        )
        weekday = weekday_names[parsed.weekday()]
        return f"{weekday} {parsed.day:02d}/{parsed.month:02d} a las {parsed.hour:02d}:{parsed.minute:02d}"

    def _appointment_confirmation_failed(self, response: AgentResponse) -> bool:
        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list):
            return False

        for trace in reversed(traces):
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(tool_name, str) or tool_name.strip() != "appointment_confirm":
                continue

            output = self._appointment_confirmation_output(trace)
            if not isinstance(output, dict):
                return False

            if any(output.get(key) is False for key in ("ok", "confirmed")):
                return True

            error_code = output.get("error_code") or output.get("errorCode")
            if isinstance(error_code, str) and error_code.strip() != "":
                return True

            status = output.get("status")
            if isinstance(status, str) and status.strip().lower() in {"error", "failed", "failure", "validation_error", "crm_error"}:
                return True

            return False

        return False

    def _appointment_confirmation_succeeded(self, response: AgentResponse) -> bool:
        traces = response.data_to_save.get("mcp_tool_traces")
        if not isinstance(traces, list):
            return False

        for trace in reversed(traces):
            if not isinstance(trace, dict):
                continue

            tool_name = trace.get("tool_name") or trace.get("toolName") or trace.get("name")
            if not isinstance(tool_name, str) or tool_name.strip() != "appointment_confirm":
                continue

            output = self._appointment_confirmation_output(trace)
            if not isinstance(output, dict):
                return False

            if any(output.get(key) is True for key in ("ok", "confirmed")):
                return True

            return False

        return False

    def _should_skip_appointment_confirmation_postprocessing(self, response: AgentResponse) -> bool:
        return response.data_to_save.get("new_llm_orchestration_appointment_availability_used") is True

    async def _send_handoff_webhook(
        self,
        response: AgentResponse,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext | None,
        conversation_result: dict[str, Any] | None,
        mcp_config: McpRemoteConfig | None,
        handoff_config: dict[str, Any],
    ) -> None:
        if backend_context is None:
            return

        tenant_id = routing.tenant_id.strip()
        tool = await self.backend_client.get_external_tool(tenant_id, "handoff_webhook")
        if tool is None:
            return

        provider = (tool.provider or "").strip().lower()
        webhook_url = (tool.webhook_url or "").strip()
        if provider != "n8n_webhook" or webhook_url == "":
            return

        if (tool.auth_type or "").strip().lower() not in {"", "none"}:
            logger.warning(
                "Handoff webhook auth ignored tenant_id=%s tool_id=%s reason=unsupported_auth_type",
                tenant_id,
                tool.id,
            )

        event_id = str(uuid.uuid4())
        started_at = time.perf_counter()
        payload_data = self._handoff_webhook_payload(
            event_id,
            payload,
            routing,
            backend_context,
            conversation_result,
            response,
        )

        timeout_seconds = tool.timeout_seconds if tool.timeout_seconds > 0 else 5
        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        headers = {"Accept": "application/json"}

        try:
            async with httpx.AsyncClient(timeout=timeout) as client:
                result = await client.post(webhook_url, json=payload_data, headers=headers)
                result.raise_for_status()
        except httpx.TimeoutException as exc:
            latency_ms = self._handoff_latency_ms(started_at)
            logger.warning(
                "Handoff webhook failed event_id=%s tenant_id=%s conversation_id=%s status_code=%s latency_ms=%s error=%s",
                event_id,
                tenant_id,
                self._conversation_id_from_result(conversation_result),
                "timeout",
                latency_ms,
                exc.__class__.__name__,
            )
            return
        except httpx.HTTPStatusError as exc:
            latency_ms = self._handoff_latency_ms(started_at)
            logger.warning(
                "Handoff webhook failed event_id=%s tenant_id=%s conversation_id=%s status_code=%s latency_ms=%s error=%s",
                event_id,
                tenant_id,
                self._conversation_id_from_result(conversation_result),
                exc.response.status_code,
                latency_ms,
                exc.__class__.__name__,
            )
            return
        except (httpx.HTTPError, ValueError) as exc:
            latency_ms = self._handoff_latency_ms(started_at)
            logger.warning(
                "Handoff webhook failed event_id=%s tenant_id=%s conversation_id=%s status_code=%s latency_ms=%s error=%s",
                event_id,
                tenant_id,
                self._conversation_id_from_result(conversation_result),
                "error",
                latency_ms,
                exc.__class__.__name__,
            )
            return

        latency_ms = self._handoff_latency_ms(started_at)
        logger.info(
            "Handoff webhook sent event_id=%s tenant_id=%s conversation_id=%s status_code=%s latency_ms=%s",
            event_id,
            tenant_id,
            self._conversation_id_from_result(conversation_result),
            200,
            latency_ms,
        )

    def _handoff_webhook_payload(
        self,
        event_id: str,
        payload: AgentRequest,
        routing: RoutingContext,
        backend_context: CommercialContext,
        conversation_result: dict[str, Any] | None,
        response: AgentResponse,
    ) -> dict[str, Any]:
        tenant = backend_context.tenant
        product = backend_context.selected_product
        entry_point = backend_context.entry_point
        conversation_id = self._conversation_id_from_result(conversation_result)
        last_messages = payload.conversation.last_messages[-8:]
        if len(last_messages) > 8:
            last_messages = last_messages[-8:]

        decision_reason = self._handoff_reason(response)
        decision_trigger = self._handoff_trigger(response)
        conversation_summary = None
        conversation = conversation_result.get("conversation") if isinstance(conversation_result, dict) else None
        if isinstance(conversation, dict):
            summary = conversation.get("summary")
            if isinstance(summary, str) and summary.strip() != "":
                conversation_summary = summary.strip()

        return {
            "event": "sales_agent.handoff_requested",
            "event_id": event_id,
            "occurred_at": datetime.now(timezone.utc).isoformat(),
            "tenant": {
                "id": tenant.id,
                "name": tenant.name,
                "slug": tenant.slug,
            },
            "conversation": {
                "id": conversation_id,
                "status": "pending_human",
                "channel": payload.channel_type or "whatsapp",
                "external_conversation_id": payload.conversation.external_id,
                "last_messages": last_messages,
                "summary": conversation_summary,
            },
            "contact": {
                "name": payload.contact.name,
                "phone": payload.contact.phone,
                "email": None,
                "external_id": None,
            },
            "entry_point": {
                "id": entry_point.id if entry_point is not None else routing.entry_point_id,
                "name": entry_point.name if entry_point is not None else None,
                "channel": payload.channel_type or "whatsapp",
                "external_ref": routing.entrypoint_ref,
            },
            "product": {
                "id": product.id if product is not None else routing.product_id,
                "name": product.name if product is not None else None,
                "slug": product.slug if product is not None else None,
                "external_ref": product.external_reference if product is not None else None,
                "source": self._handoff_product_source(backend_context),
            },
            "decision": {
                "intent": response.intent,
                "action": response.action,
                "needs_human": bool(response.needs_human),
                "score": response.score,
                "reason": decision_reason,
                "trigger": decision_trigger,
            },
            "llm": {
                "provider": response.provider,
                "model": response.model,
                "response_id": response.data_to_save.get("response_id"),
                "latency_ms": response.latency_ms,
            },
            "metadata": {
                "source": "sales-agent",
                "wa_phone_number_id": routing.external_channel_id,
                "wa_from": payload.contact.phone,
                "wa_message_id": self._raw_event_field(payload.raw_event, "whatsapp_message_id", "whatsappMessageId", "message_id", "id"),
            },
        }

    def _handoff_reason(self, response: AgentResponse) -> str:
        if response.action == "missing_routing_context":
            return "Missing routing context"
        if response.action == "ai_usage_limit_reached":
            return "AI usage limit reached"
        if response.action == "handoff_to_human":
            return "Human handoff requested"
        return "Human handoff requested"

    def _handoff_trigger(self, response: AgentResponse) -> str:
        if response.action == "missing_routing_context":
            return "missing_routing"
        if response.action == "ai_usage_limit_reached":
            return "ai_usage_limit"
        if response.intent == "handoff":
            return "user_requested_human"
        if response.needs_human:
            return "llm_requested"
        return "unknown"

    def _handoff_product_source(self, backend_context: CommercialContext) -> str:
        product = backend_context.selected_product
        if product is None:
            return "unknown"

        external_source = getattr(product, "external_source", None)
        if isinstance(external_source, str) and external_source.strip() != "":
            normalized = external_source.strip().lower()
            if normalized in {"local", "mcp", "crm"}:
                return normalized

        if backend_context.selected_product_is_fallback:
            return "local"

        return "unknown"

    def _normalize_handoff_strategy(self, value: Any) -> str:
        if not isinstance(value, str):
            return "disabled"

        strategy = value.strip().lower()
        if strategy in {"disabled", "manual_wa_link", "n8n_webhook", "manual_wa_link_and_n8n"}:
            return strategy

        return "disabled"

    def _normalize_handoff_phone(self, value: Any) -> str:
        if not isinstance(value, str):
            return ""

        return "".join(ch for ch in value if ch.isdigit())

    def _is_explicit_handoff_request(self, payload: AgentRequest) -> bool:
        message = self._normalize_text(payload.message.text).lower()
        if message == "":
            return False

        explicit_terms = (
            "hablar con una persona",
            "hablar con alguien",
            "hablar con un humano",
            "hablar con humano",
            "quiero hablar con una persona",
            "quiero hablar con alguien",
            "quiero hablar con un humano",
            "quiero hablar con humano",
            "necesito una persona",
            "necesito hablar con una persona",
            "asesor",
            "agente",
            "comercial",
            "humano",
            "persona",
        )
        return any(term in message for term in explicit_terms)

    def _requires_fresh_availability_turn(self, payload: AgentRequest) -> bool:
        message = self._normalize_text(payload.message.text)
        if message == "":
            return False

        normalized = unicodedata.normalize("NFKD", message).encode("ascii", "ignore").decode("ascii").lower().strip()
        if normalized == "":
            return False

        if self._is_slot_selection_followup(normalized):
            return False

        availability_terms = (
            "disponibilidad",
            "disponible",
            "disponibles",
            "hueco",
            "huecos",
            "agenda",
            "horario",
            "horarios",
            "cita",
            "citas",
            "reservar",
            "reserva",
            "agendar",
            "booking",
            "appointment",
        )
        temporal_terms = (
            "hoy",
            "manana",
            "pasado manana",
            "esta tarde",
            "por la tarde",
            "por la manana",
            "esta manana",
            "tarde",
            "manana por la tarde",
            "manana por la manana",
            "esta semana",
            "la semana que viene",
            "proxima semana",
        )

        return any(term in normalized for term in availability_terms) or any(term in normalized for term in temporal_terms)

    def _is_slot_selection_followup(self, normalized_message: str) -> bool:
        if normalized_message == "":
            return False

        direct_selection_terms = (
            "elijo",
            "prefiero",
            "quiero",
            "me quedo con",
            "me quedo con la",
            "me quedo con el",
            "la primera",
            "la segunda",
            "primer horario",
            "segundo horario",
            "ese horario",
            "esa hora",
            "ese hueco",
            "ese turno",
            "la de las",
            "la de la",
            "la de los",
            "la de el",
            "reservalo",
            "resérvalo",
            "reservala",
            "resérvala",
        )
        if any(term in normalized_message for term in direct_selection_terms):
            return True

        if normalized_message.startswith(("si, confirma", "si confirma", "si, reserva", "si reserva")):
            return True

        if self._contains_short_time_selection(normalized_message):
            return True

        if "confirmo" in normalized_message and any(
            marker in normalized_message
            for marker in ("primer", "primera", "segund", "ese", "esa", "horario", "hora", "hueco", "turno")
        ):
            return True

        if "confirma" in normalized_message and any(
            marker in normalized_message
            for marker in ("primer", "primera", "segund", "ese", "esa", "horario", "hora", "hueco", "turno")
        ):
            return True

        return False

    def _contains_short_time_selection(self, normalized_message: str) -> bool:
        if len(normalized_message) > 28:
            return False

        if re.search(r"\b([01]?\d|2[0-3]):[0-5]\d\b", normalized_message) is None:
            return False

        return any(token in normalized_message for token in ("por favor", "porfa", "gracias", "ok", "vale", "listo")) or normalized_message.startswith(("a las ", "las ", "el de ", "la de "))

    def _normalize_text(self, value: Any) -> str:
        if not isinstance(value, str):
            return ""

        return value.strip()

    def _audio_transcription_failure_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        error: Exception,
        handoff_config: dict[str, Any] | None = None,
    ) -> AgentResponse:
        data: dict[str, Any] = {
            "audio_transcription_failed": True,
            "audio_transcription_error": error.__class__.__name__,
            "audio_message_id": payload.message.id,
            "audio_media_id": self._audio_media_id(payload),
            "audio_mime_type": self._audio_media_mime_type(payload),
        }
        if routing.tenant_id.strip() != "":
            data["tenant_id"] = routing.tenant_id.strip()

        if handoff_config is None:
            return AgentResponse(
                reply="He recibido tu audio, pero no he podido transcribirlo ahora mismo. ¿Puedes enviármelo por escrito?",
                intent="audio",
                score=0.0,
                action="audio_transcription_failed",
                needs_human=False,
                data_to_save=data,
                provider="rule_based",
                model=None,
                latency_ms=0,
            )

        return AgentResponse(
            reply="He recibido tu audio, pero no he podido transcribirlo ahora mismo. Te paso con una persona del equipo.",
            intent="handoff",
            score=0.6,
            action="handoff_to_human",
            needs_human=True,
            data_to_save=data,
            provider="rule_based",
            model=None,
            latency_ms=0,
        )

    def _audio_duration_limit_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        duration_seconds: int,
        max_audio_seconds: int,
        policy: Any | None,
    ) -> AgentResponse:
        message = self._audio_limit_exceeded_message(policy)
        data: dict[str, Any] = {
            "audio_duration_limit_exceeded": True,
            "audio_message_id": payload.message.id,
            "audio_media_id": self._audio_media_id(payload),
            "audio_mime_type": self._audio_media_mime_type(payload),
            "audio_duration_seconds": duration_seconds,
            "max_audio_transcription_seconds": max_audio_seconds,
            "audio_limit_exceeded_message": message,
        }
        if routing.tenant_id.strip() != "":
            data["tenant_id"] = routing.tenant_id.strip()

        return AgentResponse(
            reply=message,
            intent="audio",
            score=0.0,
            action="audio_duration_limit_exceeded",
            needs_human=False,
            data_to_save=data,
        )

    def _audio_media_id(self, payload: AgentRequest) -> str | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None

        media_id = media.get("media_id") or media.get("mediaId")
        if isinstance(media_id, str) and media_id.strip() != "":
            return media_id.strip()

        return None

    def _audio_duration_seconds(self, payload: AgentRequest) -> int | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None

        duration = media.get("duration_seconds")
        if duration is None:
            duration = media.get("durationSeconds")

        if isinstance(duration, bool):
            return None
        if isinstance(duration, int):
            return max(0, duration)
        if isinstance(duration, float):
            return max(0, int(round(duration)))
        if isinstance(duration, str) and duration.strip().isdigit():
            return max(0, int(duration.strip()))

        return None

    def _effective_audio_limit_seconds(self, policy: Any | None) -> int:
        if policy is None:
            return 60

        value = getattr(policy, "max_audio_transcription_seconds", None)
        if isinstance(value, int) and value >= 1:
            return value

        return 60

    def _audio_limit_exceeded_message(self, policy: Any | None) -> str:
        if policy is None:
            return self.DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE

        value = getattr(policy, "audio_limit_exceeded_message", None)
        if isinstance(value, str):
            trimmed = value.strip()
            if trimmed != "":
                return trimmed

        return self.DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE

    def _audio_transcription_cost_limit_decision(
        self,
        policy: Any | None,
        usage: Any | None,
        duration_seconds: int | None,
        max_audio_seconds: int,
        audio_configuration: Any | None = None,
    ) -> dict[str, Any] | None:
        if policy is None or usage is None:
            return None

        estimated_duration_seconds = duration_seconds if duration_seconds is not None else max_audio_seconds
        estimated_duration_seconds = max(0, int(estimated_duration_seconds))
        estimated_audio_cost_eur = self._estimate_audio_transcription_cost_eur(estimated_duration_seconds, audio_configuration)
        reserve_cost_eur = self._audio_llm_followup_reserve_cost_eur(audio_configuration)
        required_cost_eur = estimated_audio_cost_eur + reserve_cost_eur

        daily_remaining_cost_eur = self._remaining_cost_eur(getattr(policy, "daily_cost_limit_eur", None), getattr(getattr(usage, "daily", None), "estimated_cost_eur", None))
        monthly_remaining_cost_eur = self._remaining_cost_eur(getattr(policy, "monthly_cost_limit_eur", None), getattr(getattr(usage, "monthly", None), "estimated_cost_eur", None))

        if daily_remaining_cost_eur is not None and daily_remaining_cost_eur < required_cost_eur:
            return {
                "reason": "daily_cost_limit_exceeded",
                "limit_type": "daily",
                "estimated_duration_seconds": estimated_duration_seconds,
                "estimated_cost_eur": estimated_audio_cost_eur,
                "reserve_cost_eur": reserve_cost_eur,
                "required_cost_eur": required_cost_eur,
                "remaining_daily_cost_eur": daily_remaining_cost_eur,
                "remaining_monthly_cost_eur": monthly_remaining_cost_eur,
            }

        if monthly_remaining_cost_eur is not None and monthly_remaining_cost_eur < required_cost_eur:
            return {
                "reason": "monthly_cost_limit_exceeded",
                "limit_type": "monthly",
                "estimated_duration_seconds": estimated_duration_seconds,
                "estimated_cost_eur": estimated_audio_cost_eur,
                "reserve_cost_eur": reserve_cost_eur,
                "required_cost_eur": required_cost_eur,
                "remaining_daily_cost_eur": daily_remaining_cost_eur,
                "remaining_monthly_cost_eur": monthly_remaining_cost_eur,
            }

        return None

    def _audio_transcription_usage_limit_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        max_audio_seconds: int,
        decision: dict[str, Any],
    ) -> AgentResponse:
        data: dict[str, Any] = {
            "audio_transcription_usage_limit_reached": True,
            "audio_transcription_limit_reason": decision.get("reason"),
            "audio_transcription_limit_type": decision.get("limit_type"),
            "audio_message_id": payload.message.id,
            "audio_media_id": self._audio_media_id(payload),
            "audio_mime_type": self._audio_media_mime_type(payload),
            "audio_duration_seconds": decision.get("estimated_duration_seconds"),
            "estimated_audio_transcription_cost_eur": decision.get("estimated_cost_eur"),
            "audio_llm_followup_reserve_cost_eur": decision.get("reserve_cost_eur"),
            "required_audio_processing_cost_eur": decision.get("required_cost_eur"),
            "remaining_daily_cost_eur": decision.get("remaining_daily_cost_eur"),
            "remaining_monthly_cost_eur": decision.get("remaining_monthly_cost_eur"),
            "max_audio_transcription_seconds": max_audio_seconds,
        }
        if routing.tenant_id.strip() != "":
            data["tenant_id"] = routing.tenant_id.strip()

        return AgentResponse(
            reply=self.AUDIO_TRANSCRIPTION_USAGE_LIMIT_MESSAGE,
            intent="handoff",
            score=0.95,
            action="audio_transcription_usage_limit_reached",
            needs_human=True,
            data_to_save=data,
        )

    def _audio_transcription_disabled_response(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
        *,
        reply: str | None = None,
        data_extra: dict[str, Any] | None = None,
    ) -> AgentResponse:
        data: dict[str, Any] = {
            "audio_transcription_disabled": True,
            "audio_message_id": payload.message.id,
            "audio_media_id": self._audio_media_id(payload),
            "audio_mime_type": self._audio_media_mime_type(payload),
        }
        if data_extra:
            data.update(data_extra)
        if routing.tenant_id.strip() != "":
            data["tenant_id"] = routing.tenant_id.strip()

        return AgentResponse(
            reply=reply or self.AUDIO_TRANSCRIPTION_USAGE_LIMIT_MESSAGE,
            intent="handoff",
            score=0.9,
            action="audio_transcription_disabled",
            needs_human=True,
            data_to_save=data,
        )

    def _audio_transcription_plan_disabled_message(self, policy: Any | None) -> str:
        value = getattr(policy, "audio_transcription_plan_message", None)
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return self.AUDIO_TRANSCRIPTION_PLAN_DISABLED_MESSAGE

    def _audio_media_mime_type(self, payload: AgentRequest) -> str | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None

        mime_type = media.get("mime_type") or media.get("mimeType")
        if isinstance(mime_type, str) and mime_type.strip() != "":
            return mime_type.strip()

        return None

    def _estimate_audio_transcription_cost_eur(self, duration_seconds: int, audio_configuration: Any | None = None) -> float:
        duration_seconds = max(0, int(duration_seconds))

        estimator = getattr(self.audio_transcription_client, "estimate_cost_eur", None)
        if callable(estimator):
            try:
                if audio_configuration is not None:
                    estimated = float(estimator(duration_seconds, audio_configuration))
                else:
                    estimated = float(estimator(duration_seconds))
                return max(0.0, estimated)
            except Exception:
                pass

        cost_per_unit = self._audio_transcription_cost_per_unit_eur(audio_configuration)
        cost_unit = self._audio_transcription_cost_unit(audio_configuration)
        if cost_unit == "second":
            return round(duration_seconds * cost_per_unit, 8)

        return round((duration_seconds / 60.0) * cost_per_unit, 8)

    def _audio_transcription_cost_per_unit_eur(self, audio_configuration: Any | None = None) -> float:
        if audio_configuration is not None:
            value = getattr(audio_configuration, "cost_per_unit_eur", None)
            if isinstance(value, (int, float)):
                return max(0.0, float(value))

        settings = getattr(self.audio_transcription_client, "settings", None)
        if settings is None:
            settings = getattr(self.backend_client, "settings", None)

        value = getattr(settings, "audio_transcription_cost_per_unit_eur", None)
        if value is None:
            value = getattr(settings, "openai_audio_transcription_cost_per_minute_eur", None)
        if isinstance(value, (int, float)):
            return max(0.0, float(value))

        return self.DEFAULT_AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR

    def _audio_transcription_cost_unit(self, audio_configuration: Any | None = None) -> str:
        if audio_configuration is not None:
            value = getattr(audio_configuration, "cost_unit", None)
            if isinstance(value, str) and value.strip() in {"minute", "second"}:
                return value.strip()

        settings = getattr(self.audio_transcription_client, "settings", None)
        if settings is None:
            settings = getattr(self.backend_client, "settings", None)

        value = getattr(settings, "audio_transcription_cost_unit", None)
        if isinstance(value, str) and value.strip() in {"minute", "second"}:
            return value.strip()

        return "minute"

    def _audio_llm_followup_reserve_cost_eur(self, audio_configuration: Any | None = None) -> float:
        if audio_configuration is not None:
            value = getattr(audio_configuration, "llm_followup_reserve_cost_eur", None)
            if isinstance(value, (int, float)):
                return max(0.0, float(value))

        settings = getattr(self.audio_transcription_client, "settings", None)
        if settings is None:
            settings = getattr(self.backend_client, "settings", None)

        value = getattr(settings, "audio_llm_followup_reserve_cost_eur", None)
        if isinstance(value, (int, float)):
            return max(0.0, float(value))

        return self.DEFAULT_AUDIO_LLM_FOLLOWUP_RESERVE_COST_EUR

    def _remaining_cost_eur(self, limit: Any, spent: Any) -> float | None:
        if not isinstance(limit, (int, float)):
            return None

        if limit < 0:
            return None

        if not isinstance(spent, (int, float)):
            spent = 0.0

        remaining = float(limit) - float(spent)
        return remaining

    def _handoff_latency_ms(self, started_at: float) -> int:
        return int(round((time.perf_counter() - started_at) * 1000))

    def _ai_usage_limit_response(self, decision: AiUsageGuardDecision) -> AgentResponse:
        data: dict[str, Any] = {
            "ai_usage_limit_reached": True,
            "ai_usage_limit_reason": decision.reason,
            "ai_usage_limit_type": decision.limit_type,
        }
        if decision.policy is not None:
            data["ai_usage_policy"] = decision.policy.model_dump(exclude_none=True)
            data["ai_usage_limit_action"] = decision.policy.limit_action
        if decision.usage is not None:
            data["ai_usage_usage"] = decision.usage.model_dump(exclude_none=True)

        return AgentResponse(
            reply="Ahora mismo no puedo responder automáticamente. He pasado tu caso a una persona del equipo.",
            intent="handoff",
            score=0.95,
            action="ai_usage_limit_reached",
            needs_human=True,
            data_to_save=data,
        )

    async def _report_audio_transcription_event(
        self,
        routing: RoutingContext,
        conversation_result: dict[str, Any] | None,
        inbound_result: object | None,
        transcription_result: AudioTranscriptionResult,
        audio_configuration: Any | None = None,
        duration_seconds: int | None = None,
    ) -> None:
        if routing.tenant_id.strip() == "":
            return

        conversation_id = self._conversation_id_from_result(conversation_result)
        conversation_message_id = None
        message = getattr(inbound_result, "message", None)
        if message is not None:
            message_id = getattr(message, "id", None)
            if isinstance(message_id, str) and message_id.strip() != "":
                conversation_message_id = message_id.strip()

        effective_duration_seconds = transcription_result.duration_seconds
        if effective_duration_seconds is None:
            effective_duration_seconds = duration_seconds

        estimated_cost = None
        if effective_duration_seconds is not None:
            estimated_cost = self._estimate_audio_transcription_cost_eur(effective_duration_seconds, audio_configuration)

        payload = BackendAiUsageEventPayload(
            tenant_id=routing.tenant_id.strip(),
            conversation_id=conversation_id,
            conversation_message_id=conversation_message_id,
            provider=getattr(transcription_result, "provider", None) or "openai",
            model=transcription_result.model,
            input_tokens=self._normalize_int_telemetry(getattr(transcription_result.usage, "input_tokens", None)),
            output_tokens=self._normalize_int_telemetry(getattr(transcription_result.usage, "output_tokens", None)),
            cached_tokens=self._normalize_int_telemetry(getattr(transcription_result.usage, "cached_tokens", None)),
            total_tokens=self._normalize_int_telemetry(getattr(transcription_result.usage, "total_tokens", None)),
            estimated_cost=estimated_cost,
            latency_ms=self._normalize_int_telemetry(transcription_result.latency_ms),
            usage_type="audio_transcription",
        )

        await self.backend_client.create_ai_usage_event(payload)

    async def _resolve_audio_transcription_configuration(self) -> Any | None:
        resolver = getattr(self.audio_transcription_client, "resolve_configuration", None)
        if not callable(resolver):
            return self._build_audio_transcription_configuration_from_settings()

        try:
            return await resolver()
        except Exception:
            logger.debug("Falling back to static audio transcription configuration", exc_info=True)
            return self._build_audio_transcription_configuration_from_settings()

    def _build_audio_transcription_configuration_from_settings(self) -> Any | None:
        settings = getattr(self.audio_transcription_client, "settings", None)
        if settings is None:
            settings = getattr(self.backend_client, "settings", None)
        if settings is None:
            return None

        provider = str(getattr(settings, "audio_transcription_provider", "openai")).strip() or "openai"
        model = str(
            getattr(
                settings,
                "openai_transcription_model",
                getattr(settings, "audio_transcription_model", ""),
            )
        ).strip()
        enabled = self._parse_bool_setting(getattr(settings, "audio_transcription_enabled", True), True)
        cost_unit = str(getattr(settings, "audio_transcription_cost_unit", "minute")).strip().lower() or "minute"
        if cost_unit not in {"minute", "second"}:
            cost_unit = "minute"
        cost_per_unit_eur = self._parse_float_setting(
            getattr(
                settings,
                "audio_transcription_cost_per_unit_eur",
                getattr(settings, "openai_audio_transcription_cost_per_minute_eur", self.DEFAULT_AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR),
            ),
            self.DEFAULT_AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR,
        )
        currency = str(getattr(settings, "audio_transcription_currency", "EUR")).strip() or "EUR"
        notes = getattr(settings, "audio_transcription_notes", None)
        if not isinstance(notes, str):
            notes = None
        base_url = str(getattr(settings, "openai_base_url", "https://api.openai.com/v1")).strip().rstrip("/")
        api_key = str(getattr(settings, "openai_api_key", "")).strip()
        timeout_seconds = 15
        raw_timeout_seconds = getattr(settings, "openai_timeout_seconds", 15)
        if isinstance(raw_timeout_seconds, int):
            timeout_seconds = max(1, raw_timeout_seconds)
        elif isinstance(raw_timeout_seconds, float):
            timeout_seconds = max(1, int(raw_timeout_seconds))
        elif isinstance(raw_timeout_seconds, str) and raw_timeout_seconds.strip() != "":
            try:
                timeout_seconds = max(1, int(float(raw_timeout_seconds.strip())))
            except ValueError:
                timeout_seconds = 15

        return type(
            "AudioTranscriptionConfigurationFallback",
            (),
            {
                "provider": provider,
                "model": model,
                "enabled": enabled,
                "cost_unit": cost_unit,
                "cost_per_unit_eur": cost_per_unit_eur,
                "currency": currency,
                "notes": notes,
                "base_url": base_url,
                "api_key": api_key,
                "timeout_seconds": timeout_seconds,
            },
        )()

    def _parse_bool_setting(self, raw_value: Any, fallback: bool) -> bool:
        if isinstance(raw_value, bool):
            return raw_value

        if isinstance(raw_value, str):
            normalized = raw_value.strip().lower()
            if normalized in {"1", "true", "yes", "on", "enabled"}:
                return True
            if normalized in {"0", "false", "no", "off", "disabled"}:
                return False

        return fallback

    def _parse_float_setting(self, raw_value: Any, fallback: float) -> float:
        if isinstance(raw_value, (int, float)):
            return max(0.0, float(raw_value))

        if isinstance(raw_value, str) and raw_value.strip() != "":
            try:
                return max(0.0, float(raw_value.strip().replace(",", ".")))
            except ValueError:
                return max(0.0, fallback)

        return max(0.0, fallback)

    async def _report_ai_usage_event(
        self,
        response: AgentResponse,
        routing: RoutingContext | None,
        conversation_result: dict[str, Any] | None,
        outbound_result: object | None,
    ) -> None:
        provider = response.data_to_save.get("provider") or response.provider
        model = response.data_to_save.get("model") or response.model
        if not isinstance(provider, str) or provider.strip() == "":
            return

        if routing is None or routing.tenant_id.strip() == "":
            return

        conversation_id = self._conversation_id_from_result(conversation_result)
        conversation_message_id = None
        message = getattr(outbound_result, "message", None)
        if message is not None:
            message_id = getattr(message, "id", None)
            if isinstance(message_id, str) and message_id.strip() != "":
                conversation_message_id = message_id.strip()

        payload = BackendAiUsageEventPayload(
            tenant_id=routing.tenant_id.strip(),
            conversation_id=conversation_id,
            conversation_message_id=conversation_message_id,
            provider=provider.strip(),
            model=model if isinstance(model, str) and model.strip() != "" else None,
            response_id=self._normalize_telemetry_value(response.data_to_save.get("response_id")),
            input_tokens=self._normalize_int_telemetry(response.data_to_save.get("input_tokens")),
            output_tokens=self._normalize_int_telemetry(response.data_to_save.get("output_tokens")),
            cached_tokens=self._normalize_int_telemetry(response.data_to_save.get("cached_tokens")),
            total_tokens=self._normalize_int_telemetry(response.data_to_save.get("total_tokens")),
            estimated_cost=self._normalize_float_telemetry(response.data_to_save.get("estimated_cost")),
            latency_ms=self._normalize_int_telemetry(response.data_to_save.get("latency_ms")),
            usage_type="llm_chat",
        )

        result = await self.backend_client.create_ai_usage_event(payload)
        if result is None:
            return

    def _normalize_telemetry_value(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        trimmed = value.strip()
        return trimmed if trimmed != "" else None

    def _normalize_int_telemetry(self, value: Any) -> int | None:
        if isinstance(value, bool):
            return None
        if isinstance(value, int):
            return value
        if isinstance(value, float):
            return int(round(value))
        return None

    def _normalize_float_telemetry(self, value: Any) -> float | None:
        if isinstance(value, bool):
            return None
        if isinstance(value, (int, float)):
            return float(value)
        return None
