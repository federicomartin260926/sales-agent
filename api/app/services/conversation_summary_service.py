from __future__ import annotations

import json
import logging
from typing import Any

from app.config import Settings
from app.services.backend_client import BackendClient, BackendConversationSummaryContext
from app.services.llm_client import LLMClient
from app.services.llm_context_helper import LLMContextHelper


logger = logging.getLogger(__name__)


class ConversationSummaryService:
    MAX_CONTEXT_MESSAGES = 20
    MAX_CONTEXT_BODY_CHARS = 600
    MAX_SUMMARY_CHARS = 1200

    def __init__(
        self,
        settings: Settings,
        backend_client: BackendClient,
        llm_client: LLMClient | None = None,
        context_helper: LLMContextHelper | None = None,
    ) -> None:
        self.settings = settings
        self.backend_client = backend_client
        self.llm_client = llm_client or LLMClient(settings)
        self.context_helper = context_helper or LLMContextHelper()

    async def generate_and_persist(
        self,
        conversation_id: str,
        reason: str,
        limit: int = MAX_CONTEXT_MESSAGES,
    ) -> str | None:
        conversation_context = await self.backend_client.get_conversation_summary_context(conversation_id, limit=limit)
        if conversation_context is None:
            return None

        summary = await self.generate_summary(conversation_context, reason=reason)
        if summary is None:
            return None

        try:
            await self.backend_client.update_conversation_summary(conversation_id, summary)
        except Exception:
            logger.warning("Conversation summary persistence failed conversation_id=%s", conversation_id, exc_info=True)
            return summary

        return summary

    async def generate_summary(
        self,
        conversation_context: BackendConversationSummaryContext,
        reason: str | None = None,
    ) -> str | None:
        configuration = await self.llm_client.resolve_configuration()
        provider_profile = (configuration.get("llm_default_profile") or "").strip().lower()
        provider_order = self._provider_order(provider_profile)
        if provider_order == []:
            return None

        system_prompt, user_prompt = self._build_prompt(conversation_context, reason=reason)

        for provider in provider_order:
            if not self._provider_config_ready(provider, configuration):
                continue

            try:
                result = await self.llm_client.generate(provider, system_prompt, user_prompt, configuration)
            except Exception:
                logger.warning(
                    "Conversation summary generation failed provider=%s conversation_id=%s",
                    provider,
                    self._conversation_id(conversation_context),
                    exc_info=True,
                )
                continue

            summary = self._extract_summary(result.content)
            if summary is None:
                continue

            normalized_summary = self.context_helper.sanitize_text(summary, max_chars=self.MAX_SUMMARY_CHARS)
            if normalized_summary is None:
                continue

            return normalized_summary

        return None

    def _build_prompt(
        self,
        conversation_context: BackendConversationSummaryContext,
        *,
        reason: str | None = None,
    ) -> tuple[str, str]:
        system_prompt = (
            "Eres un servicio interno de resumen de conversación para sales-agent. "
            "Devuelve solo JSON válido, sin markdown ni texto adicional, con una única clave: summary. "
            "El valor de summary debe ser un texto compacto, útil para un humano o para CRM, en español, "
            "con un máximo aproximado de 800 a 1200 caracteres. "
            "Usa únicamente la información proporcionada. No inventes datos. "
            "No copies la conversación completa. "
            "Si la conversación ya tiene un summary previo, actualízalo con la nueva información sin perder contexto relevante. "
            "No incluyas tokens, headers, Authorization, downstream auth, raw payloads, tool traces gigantes ni detalles técnicos internos. "
            "Incluye solo si existen: necesidad principal del cliente, servicio o producto de interés, datos conocidos del cliente, "
            "fecha/hora/profesional si hubo reserva o intento, objeciones o dudas, último estado, motivo de handoff si aplica "
            "y siguiente acción recomendada."
        )

        messages = self._prepare_messages(conversation_context.messages)
        payload: dict[str, Any] = {
            "conversation": {
                "id": conversation_context.conversation.get("id"),
                "tenant_id": conversation_context.conversation.get("tenant_id"),
                "status": conversation_context.conversation.get("status"),
                "summary": conversation_context.conversation.get("summary"),
                "last_message_at": conversation_context.conversation.get("lastMessageAt") or conversation_context.conversation.get("last_message_at"),
            },
            "reason": reason,
            "messages": messages,
        }

        return system_prompt, json.dumps(payload, ensure_ascii=False, indent=2, default=str)

    def _prepare_messages(self, messages: list[Any]) -> list[dict[str, Any]]:
        prepared: list[dict[str, Any]] = []
        for message in messages[-self.MAX_CONTEXT_MESSAGES :]:
            if hasattr(message, "model_dump"):
                message = message.model_dump()
            if not isinstance(message, dict):
                continue

            body = self.context_helper.sanitize_text(message.get("body"), max_chars=self.MAX_CONTEXT_BODY_CHARS)
            if body is None:
                continue

            prepared.append(
                {
                    "id": message.get("id"),
                    "direction": message.get("direction"),
                    "role": message.get("role"),
                    "message_type": message.get("message_type") or message.get("messageType"),
                    "body": body,
                    "created_at": message.get("created_at") or message.get("createdAt"),
                    "intent": message.get("intent"),
                    "action": message.get("action"),
                    "needs_human": message.get("needs_human"),
                }
            )

        return prepared

    def _extract_summary(self, content: str) -> str | None:
        candidate = content.strip()
        if candidate == "":
            return None

        try:
            payload = json.loads(candidate)
        except json.JSONDecodeError:
            return candidate

        if isinstance(payload, dict):
            summary = payload.get("summary")
            if isinstance(summary, str) and summary.strip() != "":
                return summary.strip()

        if isinstance(payload, str) and payload.strip() != "":
            return payload.strip()

        return None

    def _provider_order(self, provider_profile: str) -> list[str]:
        if provider_profile == "auto":
            return ["openai", "ollama"]
        if provider_profile in {"openai", "ollama"}:
            return [provider_profile]
        return []

    def _provider_config_ready(self, provider: str, configuration: dict[str, str]) -> bool:
        if provider == "openai":
            return all(
                configuration.get(key, "").strip() != ""
                for key in ("openai_base_url", "openai_model", "openai_api_key")
            )

        if provider == "ollama":
            return all(configuration.get(key, "").strip() != "" for key in ("ollama_base_url", "ollama_model"))

        return False

    def _conversation_id(self, conversation_context: BackendConversationSummaryContext) -> str:
        conversation_id = conversation_context.conversation.get("id")
        return conversation_id if isinstance(conversation_id, str) else "-"
