from __future__ import annotations

from typing import Any

from app.schemas.agent import AgentRequest
from app.schemas.llm import McpRemoteConfig
from app.services.audio_preprocessor import AudioMessagePreprocessor
from app.services.audio_clients import AudioTranscriptionResult
from app.services.backend_client import (
    BackendClient,
    BackendConversationMessagePayload,
    BackendConversationUpsertPayload,
)
from app.services.routing_resolver import RoutingContext


class ConversationMessagePersistence:
    def __init__(self, backend_client: BackendClient, audio_preprocessor: AudioMessagePreprocessor | None = None) -> None:
        self.backend_client = backend_client
        self.audio_preprocessor = audio_preprocessor

    async def upsert_conversation(self, payload: AgentRequest, routing: RoutingContext) -> dict[str, Any] | None:
        return await self.backend_client.upsert_conversation(
            BackendConversationUpsertPayload(
                tenant_id=routing.tenant_id,
                product_id=routing.product_id,
                entry_point_id=routing.entry_point_id,
                entry_point_utm_id=routing.entry_point_utm_id,
                customer_phone=payload.contact.phone,
                customer_name=payload.contact.name,
                first_message=payload.message.text or self._first_message_placeholder(payload),
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

    async def persist_inbound(
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
            metadata["audio_transcription"] = self._audio_result_payload(audio_result)

        return await self.backend_client.create_conversation_message(
            BackendConversationMessagePayload(
                conversation_id=routing.conversation_id,
                direction="inbound",
                role="user",
                message_type=payload.message.type or "text",
                body=payload.message.text or self._first_message_placeholder(payload),
                external_message_id=payload.message.id,
                external_timestamp=payload.message.timestamp,
                raw_payload=payload.model_dump(exclude_none=True),
                metadata=metadata,
            )
        )

    async def persist_outbound(
        self,
        response: Any,
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

    def conversation_id(self, conversation_result: dict[str, Any] | None) -> str | None:
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

    def routing_payload(self, routing: RoutingContext) -> dict[str, Any]:
        return {
            "tenant_id": routing.tenant_id,
            "source": routing.source,
            "external_channel_id": routing.external_channel_id,
            "entrypoint_ref": routing.entrypoint_ref,
            "status": routing.status,
        }

    def _first_message_placeholder(self, payload: AgentRequest) -> str:
        if self.audio_preprocessor is None:
            return payload.message.text or ""
        return self.audio_preprocessor.first_message_placeholder(payload)

    def _audio_result_payload(self, audio_result: AudioTranscriptionResult) -> dict[str, Any]:
        if self.audio_preprocessor is None:
            return {}
        return self.audio_preprocessor.audio_result_payload(audio_result)
