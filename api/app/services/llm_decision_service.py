from __future__ import annotations

import json
import logging
from typing import Any

from pydantic import BaseModel, ConfigDict, Field, ValidationError

from app.config import Settings
from app.schemas.agent import AgentRequest
from app.services.backend_client import CommercialContext
from app.services.crm_client import CRMContactContext
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
        crm_context: CRMContactContext | None,
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

        system_prompt, user_prompt = self.prompt_builder.build(payload, routing, backend_context, crm_context)

        for provider in provider_order:
            if not self._provider_config_ready(provider, configuration):
                reason = f"missing configuration for {provider}"
                logger.info("LLM fallback reason=%s", reason)
                if provider_profile != "auto":
                    return None
                continue

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

            logger.info("LLM provider used=%s", result.provider)
            return draft

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
            "qualify": "ask_question",
            "offer_booking": "propose_meeting",
            "propose_meeting": "propose_meeting",
            "continue_conversation": "ask_question",
            "needs_human": "handoff_to_human",
            "handoff": "handoff_to_human",
            "handoff_to_human": "handoff_to_human",
        }
        return mapping.get(normalized, "ask_question")
