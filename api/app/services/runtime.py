from __future__ import annotations

import time
from typing import Any

from app.schemas.agent import AgentRequest, AgentResponse
from app.schemas.llm import McpRemoteConfig
from app.services.backend_client import (
    BackendClient,
    BackendConversationMessagePayload,
    BackendConversationUpsertPayload,
    CommercialContext,
)
from app.services.decision_engine import DecisionEngine
from app.services.routing_resolver import RoutingContext, RuntimeRoutingResolver

class AgentRuntime:
    def __init__(
        self,
        backend_client: BackendClient,
        routing_resolver: RuntimeRoutingResolver,
        decision_engine: DecisionEngine,
    ) -> None:
        self.backend_client = backend_client
        self.routing_resolver = routing_resolver
        self.decision_engine = decision_engine

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
        )
        mcp_config = await self.backend_client.fetch_mcp_config(routing.tenant_id)

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

        if conversation_id is not None:
            await self.backend_client.create_conversation_message(
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

    def _outbound_metadata(self, response: AgentResponse, mcp_config: McpRemoteConfig | None) -> dict[str, Any]:
        metadata: dict[str, Any] = {
            "data_to_save": response.data_to_save,
        }

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
