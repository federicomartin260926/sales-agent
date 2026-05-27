from __future__ import annotations

from datetime import datetime, timezone
import httpx
import logging
import uuid
from typing import Any

import time

from app.schemas.agent import AgentRequest, AgentResponse
from app.schemas.llm import BackendAiUsageEventPayload
from app.schemas.llm import McpRemoteConfig
from app.services.ai_usage_guard import AiUsageGuard, AiUsageGuardDecision
from app.services.backend_client import (
    BackendClient,
    BackendConversationMessagePayload,
    BackendConversationUpsertPayload,
    CommercialContext,
)
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver


logger = logging.getLogger(__name__)


class AgentRuntime:
    def __init__(
        self,
        backend_client: BackendClient,
        routing_resolver: RuntimeRoutingResolver,
        decision_engine: DecisionEngine,
        ai_usage_guard: AiUsageGuard | None = None,
    ) -> None:
        self.backend_client = backend_client
        self.routing_resolver = routing_resolver
        self.decision_engine = decision_engine
        self.ai_usage_guard = ai_usage_guard or AiUsageGuard(backend_client)

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

        backend_context = await self.backend_client.fetch_tenant_context(
            routing.tenant_id,
            routing.product_id,
            routing.playbook_id,
            routing.entry_point_id,
            routing.entrypoint_ref,
            payload.contact.phone,
            routing.external_channel_id or payload.external_channel_id,
            payload.message.text,
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
                first_message=payload.message.text,
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
                    message_type="text",
                    body=payload.message.text,
                    external_message_id=self._raw_event_field(payload.raw_event, "whatsapp_message_id", "whatsappMessageId", "message_id", "id"),
                    external_timestamp=self._raw_event_field(payload.raw_event, "timestamp"),
                    raw_payload=payload.raw_event,
                    metadata={
                        "channel_type": payload.channel_type,
                        "external_channel_id": payload.external_channel_id,
                        "entrypoint_ref": payload.entrypoint_ref,
                        "contact": payload.contact.model_dump(),
                    },
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

        if isinstance(conversation_result, dict):
            conversation = conversation_result.get("conversation")
            if isinstance(conversation, dict) and isinstance(conversation.get("id"), str):
                routing.conversation_id = conversation["id"]

        agenda_response = self.decision_engine.resolve_agenda_response(
            payload,
            routing=routing,
            backend_context=backend_context,
            contact_context=None,
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
        response = await self.decision_engine.decide(
            payload,
            routing=routing,
            backend_context=backend_context,
            contact_context=None,
            mcp_config=mcp_config,
        )
        decision_latency_ms = int(round((time.perf_counter() - started_at) * 1000))
        latency_ms = response.latency_ms if response.latency_ms is not None else decision_latency_ms

        response = await self._apply_handoff_policy(
            response,
            payload,
            routing,
            backend_context,
            conversation_result,
            mcp_config,
        )

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
        else:
            await self._report_ai_usage_event(response, routing, conversation_result, None)

        return response

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

    def _score_to_integer(self, score: float) -> int | None:
        try:
            return int(round(score * 100))
        except Exception:
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
    ) -> AgentResponse:
        if not self._should_apply_handoff(response, backend_context):
            return response

        tenant = backend_context.tenant if backend_context is not None else None
        handoff_config = self._handoff_config_from_tenant(tenant)

        if handoff_config is None:
            return response

        updated_reply = response.reply
        if self._handoff_allows_manual_link(handoff_config):
            updated_reply = self._append_handoff_link(updated_reply, handoff_config)

        if self._handoff_allows_webhook(handoff_config):
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

    def _normalize_text(self, value: Any) -> str:
        if not isinstance(value, str):
            return ""

        return value.strip()

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
