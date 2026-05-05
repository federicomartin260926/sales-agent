from __future__ import annotations

import json
import logging
import time
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, ValidationError

from app.config import Settings
from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.services.llm_client import LLMClient
from app.services.llm_prompt_builder import LLMPromptBuilder
from app.services.routing_resolver import RoutingContext


logger = logging.getLogger(__name__)


class LLMDecisionDraft(BaseModel):
    model_config = ConfigDict(extra="ignore")

    reply: str = Field(min_length=1)
    intent: str = Field(min_length=1)
    score: float = Field(ge=0, le=1)
    action: str = Field(min_length=1)
    needs_human: bool
    data_to_save: dict[str, Any] = Field(default_factory=dict)
    provider: str | None = None
    model: str | None = None
    latency_ms: int | None = None


class LLMDecisionService:
    def __init__(
        self,
        settings: Settings,
        llm_client: LLMClient | None = None,
        prompt_builder: LLMPromptBuilder | None = None,
    ) -> None:
        self.settings = settings
        self.llm_client = llm_client or LLMClient(settings)
        self.prompt_builder = prompt_builder or LLMPromptBuilder()

    async def propose(
        self,
        payload: AgentRequest,
        routing: RoutingContext | None,
        backend_context: CommercialContext | None,
        contact_context: dict[str, Any] | None = None,
    ) -> LLMDecisionDraft | None:
        configuration = await self.llm_client.resolve_configuration()
        provider_profile = configuration.get("llm_default_profile", "").strip().lower()
        if provider_profile == "heuristic":
            logger.debug("LLM heuristics mode selected; skipping provider calls")
            return None

        provider_order = self._provider_order(provider_profile)
        if not provider_order:
            logger.debug("LLM heuristics fallback: provider profile disabled or unrecognized (%s)", provider_profile or "empty")
            return None

        system_prompt, user_prompt = self.prompt_builder.build(payload, routing, backend_context, contact_context)

        for provider in provider_order:
            if not self._provider_config_ready(provider, configuration):
                reason = f"missing configuration for {provider}"
                logger.info("LLM fallback reason=%s", reason)
                if provider_profile != "auto":
                    return None
                continue

            started_at = time.perf_counter()
            try:
                result = await self.llm_client.generate(provider, system_prompt, user_prompt, configuration)
            except Exception as exc:
                reason = f"{provider} request failed: {exc.__class__.__name__}"
                logger.warning("LLM fallback reason=%s", reason)
                if provider_profile != "auto":
                    return None
                continue

            draft = self._parse_draft(result.content)
            if draft is None:
                reason = f"{provider} returned invalid JSON payload"
                logger.warning("LLM fallback reason=%s", reason)
                if provider_profile != "auto":
                    return None
                continue

            agenda_action = self._agenda_action_from_context(payload.message.text, contact_context, draft)
            if agenda_action is not None and draft.intent in {"open_question", "unknown"}:
                draft = LLMDecisionDraft(
                    reply=draft.reply,
                    intent="agenda",
                    score=draft.score,
                    action=agenda_action,
                    needs_human=draft.needs_human,
                    data_to_save=draft.data_to_save,
                    provider=draft.provider,
                    model=draft.model,
                    latency_ms=draft.latency_ms,
                )

            logger.info("LLM provider used=%s", result.provider)
            return LLMDecisionDraft(
                reply=draft.reply,
                intent=draft.intent,
                score=draft.score,
                action=draft.action,
                needs_human=draft.needs_human,
                data_to_save=draft.data_to_save,
                provider=result.provider,
                model=result.model,
                latency_ms=int(round((time.perf_counter() - started_at) * 1000)),
            )

        logger.info("LLM fallback reason=no provider succeeded")
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

    def _parse_draft(self, content: str) -> LLMDecisionDraft | None:
        payload: Any = None
        candidate = content.strip()

        try:
            payload = json.loads(candidate)
        except json.JSONDecodeError:
            json_blob = self._extract_json_object(candidate)
            if json_blob is None:
                return None

            try:
                payload = json.loads(json_blob)
            except json.JSONDecodeError:
                return None

        try:
            draft = LLMDecisionDraft.model_validate(payload)
        except ValidationError:
            return None

        normalized = self._normalize_draft(draft)
        if normalized is None:
            return None

        return normalized

    def _extract_json_object(self, content: str) -> str | None:
        start = content.find("{")
        end = content.rfind("}")
        if start == -1 or end == -1 or end <= start:
            return None

        return content[start : end + 1]

    def _normalize_draft(self, draft: LLMDecisionDraft) -> LLMDecisionDraft | None:
        reply = draft.reply.strip()
        if reply == "":
            return None

        intent = self._normalize_intent(draft.intent)
        action = self._normalize_action(draft.action)
        needs_human = bool(draft.needs_human)
        if needs_human:
            action = "handoff_to_human"

        data_to_save = draft.data_to_save if isinstance(draft.data_to_save, dict) else {}

        return LLMDecisionDraft(
            reply=reply,
            intent=intent,
            score=max(0.0, min(1.0, float(draft.score))),
            action=action,
            needs_human=needs_human,
            data_to_save=data_to_save,
        )

    def _normalize_intent(self, intent: str) -> str:
        normalized = intent.strip().lower()
        mapping = {
            "greeting": "greeting",
            "info": "open_question",
            "pricing": "qualification",
            "qualification": "qualification",
            "meeting": "agenda",
            "appointment": "agenda",
            "booking": "agenda",
            "calendar": "agenda",
            "reservation": "agenda",
            "schedule": "agenda",
            "objection": "open_question",
            "handoff": "handoff",
            "not_interested": "open_question",
            "open_question": "open_question",
            "unknown": "open_question",
        }
        return mapping.get(normalized, "open_question")

    def _normalize_action(self, action: str) -> str:
        normalized = action.strip().lower()
        mapping = {
            "greet": "greet",
            "ask_question": "ask_question",
            "answer_question": "answer_question",
            "answer": "answer_question",
            "respond_question": "answer_question",
            "reply_question": "answer_question",
            "qualify": "ask_question",
            "offer_booking": "propose_meeting",
            "propose_meeting": "propose_meeting",
            "continue_conversation": "ask_question",
            "needs_human": "handoff_to_human",
            "handoff": "handoff_to_human",
            "handoff_to_human": "handoff_to_human",
        }
        return mapping.get(normalized, "ask_question")

    def _agenda_action_from_context(
        self,
        message: str,
        contact_context: dict[str, Any] | None,
        draft: LLMDecisionDraft,
    ) -> str | None:
        if not self._is_agenda_message(message):
            return None

        state = self._agenda_context_state(contact_context)
        if state in {"not_configured", "unavailable"}:
            return "offer_booking_or_handoff"

        if self._agenda_next_appointment(contact_context) is not None:
            return "answer_question"

        return "offer_booking"

    def _is_agenda_message(self, message: str) -> bool:
        normalized = message.lower().strip()
        return any(
            keyword in normalized
            for keyword in (
                "próxima cita",
                "proxima cita",
                "mi cita",
                "cuándo era mi cita",
                "cuando era mi cita",
                "qué día tengo cita",
                "que dia tengo cita",
                "hora de la cita",
                "tengo cita",
                "reunión",
                "reunion",
                "reserva",
                "agenda",
                "cita",
            )
        )

    def _agenda_context_state(self, contact_context: dict[str, Any] | None) -> str:
        if not isinstance(contact_context, dict):
            return "unavailable"

        if not bool(contact_context.get("configured", False)):
            return "not_configured"

        if not bool(contact_context.get("available", False)) or not bool(contact_context.get("ok", False)):
            return "unavailable"

        error_code = contact_context.get("error_code")
        if isinstance(error_code, str) and error_code.strip() != "":
            return "unavailable"

        return "available"

    def _agenda_next_appointment(self, contact_context: dict[str, Any] | None) -> dict[str, Any] | None:
        if not isinstance(contact_context, dict):
            return None

        data = contact_context.get("data")
        if not isinstance(data, dict):
            return None

        appointments = data.get("appointments")
        if not isinstance(appointments, dict):
            return None

        next_appointment = appointments.get("next")
        return next_appointment if isinstance(next_appointment, dict) else None
