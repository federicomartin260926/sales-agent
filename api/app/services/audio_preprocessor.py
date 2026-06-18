from __future__ import annotations

import logging
from typing import Any

from app.config import Settings
from app.schemas.agent import AgentRequest, AgentResponse
from app.schemas.llm import BackendAiUsageEventPayload
from app.services.ai_usage_guard import AiUsageGuard, AiUsageGuardDecision
from app.services.audio_clients import AudioGatewayClient, AudioTranscriptionClient, AudioTranscriptionResult
from app.services.backend_client import BackendClient
from app.services.routing_resolver import RoutingContext
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


class AudioMessagePreprocessor:
    """Turns supported audio messages into text before the main LLM flow.

    Audio is intentionally not a separate conversational path. This service
    handles technical/audio concerns only: limits, download, transcription and
    usage reporting. Once it writes the transcript into payload.message.text,
    runtime continues with the same LLM-led context/tools workflow used for
    normal text messages.
    """

    DEFAULT_AUDIO_FAILURE_MESSAGE = "He recibido tu audio, pero no he podido transcribirlo ahora mismo. ¿Puedes enviármelo por escrito?"
    DEFAULT_AUDIO_DISABLED_MESSAGE = "Ahora mismo no puedo procesar audios. Por favor, envíame tu consulta por escrito."
    DEFAULT_AUDIO_PLAN_DISABLED_MESSAGE = "Tu plan actual no incluye procesamiento automático de audios. Por favor, escribe el mensaje en texto o contacta con el equipo para ampliar el plan."
    DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE = "El audio es demasiado largo para procesarlo automáticamente. Por favor, envíame un audio más corto o escríbeme el mensaje por texto."
    DEFAULT_AUDIO_USAGE_LIMIT_MESSAGE = "Ahora mismo no puedo procesar este audio automáticamente por límite de uso. Te paso con una persona del equipo."

    def __init__(
        self,
        settings: Settings,
        backend_client: BackendClient,
        ai_usage_guard: AiUsageGuard | None,
        runtime_settings_client: RuntimeSettingsClient | None = None,
        audio_gateway_client: AudioGatewayClient | None = None,
        audio_transcription_client: AudioTranscriptionClient | None = None,
    ) -> None:
        self.settings = settings
        self.backend_client = backend_client
        self.ai_usage_guard = ai_usage_guard
        self.runtime_settings_client = runtime_settings_client or RuntimeSettingsClient(settings)
        self.audio_gateway_client = audio_gateway_client or AudioGatewayClient(settings, self.runtime_settings_client)
        self.audio_transcription_client = audio_transcription_client or AudioTranscriptionClient(settings, self.runtime_settings_client)

    # Entry point used by runtime: validate/download/transcribe or return a local response.
    async def prepare(
        self,
        payload: AgentRequest,
        routing: RoutingContext,
    ) -> tuple[AgentResponse | None, AudioTranscriptionResult | None, Any | None]:
        if self.audio_gateway_client is None or self.audio_transcription_client is None:
            return self._audio_disabled_response(payload, routing, self.DEFAULT_AUDIO_DISABLED_MESSAGE), None, None

        usage_decision = await self._evaluate_ai_usage(routing.tenant_id)
        if usage_decision is not None and not usage_decision.allowed:
            return self._audio_usage_limit_response(payload, routing, usage_decision), None, None
        if usage_decision is not None and getattr(usage_decision.policy, "audio_transcription_enabled_by_plan", True) is False:
            return self._audio_disabled_response(payload, routing, self._audio_plan_disabled_message(usage_decision.policy)), None, None

        audio_config = await self._resolve_audio_configuration()
        if audio_config is not None and getattr(audio_config, "enabled", True) is False:
            return self._audio_disabled_response(payload, routing, self.DEFAULT_AUDIO_DISABLED_MESSAGE), None, audio_config

        duration_seconds = self.audio_duration_seconds(payload)
        max_seconds = self._effective_audio_limit_seconds(getattr(usage_decision, "policy", None) if usage_decision is not None else None)
        if duration_seconds is not None and duration_seconds > max_seconds:
            return self._audio_duration_limit_response(payload, routing, duration_seconds, max_seconds, getattr(usage_decision, "policy", None) if usage_decision is not None else None), None, audio_config

        cost_decision = self._audio_cost_limit_decision(getattr(usage_decision, "policy", None) if usage_decision is not None else None, getattr(usage_decision, "usage", None) if usage_decision is not None else None, duration_seconds, max_seconds, audio_config)
        if cost_decision is not None:
            return self._audio_cost_limit_response(payload, routing, cost_decision), None, audio_config

        try:
            result = await self._transcribe_audio_message(payload, audio_config, duration_seconds)
        except Exception as exc:
            logger.warning("Audio transcription failed message_id=%s media_id=%s error=%s", payload.message.id, self.audio_media_id(payload), exc, exc_info=True)
            return self._audio_failure_response(payload, routing, exc), None, audio_config

        return None, result, audio_config

    # Report the transcription usage event after runtime has persisted the inbound message.
    async def report_transcription_event(
        self,
        routing: RoutingContext,
        conversation_result: dict[str, Any] | None,
        inbound_result: Any | None,
        transcription_result: AudioTranscriptionResult,
        audio_config: Any | None,
    ) -> None:
        if not self._clean(routing.tenant_id):
            return

        conversation_message_id = None
        message = getattr(inbound_result, "message", None)
        if message is not None:
            candidate = getattr(message, "id", None)
            if isinstance(candidate, str) and candidate.strip():
                conversation_message_id = candidate.strip()

        estimated_cost = None
        if transcription_result.duration_seconds is not None:
            estimated_cost = self._estimate_audio_cost(transcription_result.duration_seconds, audio_config)

        payload = BackendAiUsageEventPayload(
            tenant_id=routing.tenant_id.strip(),
            conversation_id=self._conversation_id(conversation_result),
            conversation_message_id=conversation_message_id,
            provider=transcription_result.provider,
            model=transcription_result.model,
            input_tokens=self._normalize_int(getattr(transcription_result.usage, "input_tokens", None)),
            output_tokens=self._normalize_int(getattr(transcription_result.usage, "output_tokens", None)),
            cached_tokens=self._normalize_int(getattr(transcription_result.usage, "cached_tokens", None)),
            total_tokens=self._normalize_int(getattr(transcription_result.usage, "total_tokens", None)),
            estimated_cost=estimated_cost,
            latency_ms=self._normalize_int(transcription_result.latency_ms),
            usage_type="audio_transcription",
        )
        await self.backend_client.create_ai_usage_event(payload)

    # Detect whether the inbound payload represents an audio message.
    def is_audio_message(self, payload: AgentRequest) -> bool:
        if str(payload.message.type or "").strip().lower() == "audio":
            return True
        media = payload.message.media
        return isinstance(media, dict) and str(media.get("kind") or "").strip().lower() == "audio"

    # Build a placeholder used before transcription or when audio text is unavailable.
    def first_message_placeholder(self, payload: AgentRequest) -> str:
        if self.is_audio_message(payload):
            return "[audio]"
        return ""

    # Small safe payload stored in message metadata for debugging audio behavior.
    def audio_result_payload(self, result: AudioTranscriptionResult) -> dict[str, Any]:
        return {
            "provider": result.provider,
            "model": result.model,
            "duration_seconds": result.duration_seconds,
            "audio_bytes": result.audio_bytes,
            "latency_ms": result.latency_ms,
            "text": result.text,
        }

    # Download the WhatsApp media and run the transcription client.
    async def _transcribe_audio_message(
        self,
        payload: AgentRequest,
        audio_config: Any | None,
        duration_seconds: int | None,
    ) -> AudioTranscriptionResult:
        media_id = self.audio_media_id(payload)
        if media_id is None:
            raise RuntimeError("Audio message does not include a media reference.")

        download_result = await self.audio_gateway_client.download_whatsapp_media(media_id)
        result = await self.audio_transcription_client.transcribe(
            download_result.content,
            download_result.content_type,
            download_result.media_id,
            duration_seconds=duration_seconds,
        )
        payload.message.text = result.text

        if isinstance(payload.message.media, dict):
            payload.message.media["transcript"] = result.text
            payload.message.media["transcription_model"] = result.model
            payload.message.media.setdefault("media_id", download_result.media_id)
            if download_result.content_type is not None:
                payload.message.media.setdefault("content_type", download_result.content_type)

        return result

    # Check current tenant AI usage policy before spending transcription tokens/cost.
    async def _evaluate_ai_usage(self, tenant_id: str | None) -> AiUsageGuardDecision | None:
        if self.ai_usage_guard is None:
            return None
        try:
            return await self.ai_usage_guard.evaluate(tenant_id)
        except Exception:
            logger.debug("AI usage guard failed; continuing", exc_info=True)
            return None

    # Read runtime audio configuration when the transcription client supports it.
    async def _resolve_audio_configuration(self) -> Any | None:
        resolver = getattr(self.audio_transcription_client, "resolve_configuration", None)
        if not callable(resolver):
            return None
        try:
            return await resolver()
        except Exception:
            logger.debug("Audio configuration resolution failed", exc_info=True)
            return None

    # Extract the WhatsApp media id from snake_case or camelCase payloads.
    def audio_media_id(self, payload: AgentRequest) -> str | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None
        media_id = media.get("media_id") or media.get("mediaId")
        return self._clean(media_id)

    # Extract the media MIME type for diagnostics.
    def audio_mime_type(self, payload: AgentRequest) -> str | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None
        return self._clean(media.get("mime_type") or media.get("mimeType"))

    # Extract duration in seconds from snake_case or camelCase payloads.
    def audio_duration_seconds(self, payload: AgentRequest) -> int | None:
        media = payload.message.media
        if not isinstance(media, dict):
            return None
        raw = media.get("duration_seconds") if media.get("duration_seconds") is not None else media.get("durationSeconds")
        if isinstance(raw, bool):
            return None
        if isinstance(raw, int):
            return max(0, raw)
        if isinstance(raw, float):
            return max(0, int(round(raw)))
        if isinstance(raw, str) and raw.strip().isdigit():
            return max(0, int(raw.strip()))
        return None

    # Resolve tenant-specific max audio duration, falling back to 60 seconds.
    def _effective_audio_limit_seconds(self, policy: Any | None) -> int:
        value = getattr(policy, "max_audio_transcription_seconds", None)
        return value if isinstance(value, int) and value >= 1 else 60

    # Get plan-disabled copy from policy or default Spanish message.
    def _audio_plan_disabled_message(self, policy: Any | None) -> str:
        value = getattr(policy, "audio_transcription_plan_message", None)
        return value.strip() if isinstance(value, str) and value.strip() else self.DEFAULT_AUDIO_PLAN_DISABLED_MESSAGE

    # Get audio length-limit copy from policy or default Spanish message.
    def _audio_limit_message(self, policy: Any | None) -> str:
        value = getattr(policy, "audio_limit_exceeded_message", None)
        return value.strip() if isinstance(value, str) and value.strip() else self.DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE

    # Build a local response when transcription fails unexpectedly.
    def _audio_failure_response(self, payload: AgentRequest, routing: RoutingContext, error: Exception) -> AgentResponse:
        return self._local_response(
            reply=self.DEFAULT_AUDIO_FAILURE_MESSAGE,
            intent="audio",
            action="audio_transcription_failed",
            needs_human=False,
            data_to_save={
                "audio_transcription_failed": True,
                "audio_transcription_error": error.__class__.__name__,
                "audio_message_id": payload.message.id,
                "audio_media_id": self.audio_media_id(payload),
                "audio_mime_type": self.audio_mime_type(payload),
                "tenant_id": routing.tenant_id,
            },
        )

    # Build a local response when audio is disabled by config or plan.
    def _audio_disabled_response(self, payload: AgentRequest, routing: RoutingContext, reply: str) -> AgentResponse:
        return self._local_response(
            reply=reply,
            intent="audio",
            action="audio_transcription_disabled",
            needs_human=False,
            data_to_save={
                "audio_transcription_disabled": True,
                "audio_message_id": payload.message.id,
                "audio_media_id": self.audio_media_id(payload),
                "audio_mime_type": self.audio_mime_type(payload),
                "tenant_id": routing.tenant_id,
            },
        )

    # Build a local response when the audio exceeds configured max duration.
    def _audio_duration_limit_response(self, payload: AgentRequest, routing: RoutingContext, duration_seconds: int, max_seconds: int, policy: Any | None) -> AgentResponse:
        return self._local_response(
            reply=self._audio_limit_message(policy),
            intent="audio",
            action="audio_duration_limit_exceeded",
            needs_human=False,
            data_to_save={
                "audio_duration_limit_exceeded": True,
                "audio_duration_seconds": duration_seconds,
                "max_audio_transcription_seconds": max_seconds,
                "audio_message_id": payload.message.id,
                "audio_media_id": self.audio_media_id(payload),
                "audio_mime_type": self.audio_mime_type(payload),
                "tenant_id": routing.tenant_id,
            },
        )

    # Build a handoff response when tenant AI limits do not allow audio processing.
    def _audio_usage_limit_response(self, payload: AgentRequest, routing: RoutingContext, decision: AiUsageGuardDecision) -> AgentResponse:
        return self._local_response(
            reply=self.DEFAULT_AUDIO_USAGE_LIMIT_MESSAGE,
            intent="handoff",
            action="audio_transcription_usage_limit_reached",
            needs_human=True,
            data_to_save={
                "audio_transcription_usage_limit_reached": True,
                "audio_transcription_limit_reason": decision.reason,
                "audio_transcription_limit_type": decision.limit_type,
                "audio_message_id": payload.message.id,
                "audio_media_id": self.audio_media_id(payload),
                "audio_mime_type": self.audio_mime_type(payload),
                "tenant_id": routing.tenant_id,
            },
        )

    # Build a handoff response when estimated audio+LLM cost exceeds policy.
    def _audio_cost_limit_response(self, payload: AgentRequest, routing: RoutingContext, decision: dict[str, Any]) -> AgentResponse:
        data = dict(decision)
        data.update(
            {
                "audio_transcription_usage_limit_reached": True,
                "audio_message_id": payload.message.id,
                "audio_media_id": self.audio_media_id(payload),
                "audio_mime_type": self.audio_mime_type(payload),
                "tenant_id": routing.tenant_id,
            }
        )
        return self._local_response(
            reply=self.DEFAULT_AUDIO_USAGE_LIMIT_MESSAGE,
            intent="handoff",
            action="audio_transcription_usage_limit_reached",
            needs_human=True,
            data_to_save=data,
        )

    # Estimate whether transcription plus reserve LLM cost fits tenant policy.
    def _audio_cost_limit_decision(self, policy: Any | None, usage: Any | None, duration_seconds: int | None, max_seconds: int, audio_config: Any | None) -> dict[str, Any] | None:
        if policy is None or usage is None:
            return None
        estimated_duration = duration_seconds if duration_seconds is not None else max_seconds
        estimated_cost = self._estimate_audio_cost(estimated_duration, audio_config)
        reserve_cost = self._audio_llm_reserve_cost(audio_config)
        required_cost = estimated_cost + reserve_cost
        daily_remaining = self._remaining_cost(getattr(policy, "daily_cost_limit_eur", None), getattr(getattr(usage, "daily", None), "estimated_cost_eur", None))
        monthly_remaining = self._remaining_cost(getattr(policy, "monthly_cost_limit_eur", None), getattr(getattr(usage, "monthly", None), "estimated_cost_eur", None))
        if daily_remaining is not None and daily_remaining < required_cost:
            return {"reason": "daily_cost_limit_exceeded", "limit_type": "daily", "estimated_duration_seconds": estimated_duration, "estimated_cost_eur": estimated_cost, "reserve_cost_eur": reserve_cost, "required_cost_eur": required_cost, "remaining_daily_cost_eur": daily_remaining, "remaining_monthly_cost_eur": monthly_remaining}
        if monthly_remaining is not None and monthly_remaining < required_cost:
            return {"reason": "monthly_cost_limit_exceeded", "limit_type": "monthly", "estimated_duration_seconds": estimated_duration, "estimated_cost_eur": estimated_cost, "reserve_cost_eur": reserve_cost, "required_cost_eur": required_cost, "remaining_daily_cost_eur": daily_remaining, "remaining_monthly_cost_eur": monthly_remaining}
        return None

    # Estimate transcription cost using provider config or env defaults.
    def _estimate_audio_cost(self, duration_seconds: int, audio_config: Any | None) -> float:
        estimator = getattr(self.audio_transcription_client, "estimate_cost_eur", None)
        if callable(estimator):
            try:
                return max(0.0, float(estimator(duration_seconds, audio_config) if audio_config is not None else estimator(duration_seconds)))
            except Exception:
                pass
        cost_per_unit = getattr(audio_config, "cost_per_unit_eur", self.settings.audio_transcription_cost_per_unit_eur) if audio_config is not None else self.settings.audio_transcription_cost_per_unit_eur
        unit = getattr(audio_config, "cost_unit", self.settings.audio_transcription_cost_unit) if audio_config is not None else self.settings.audio_transcription_cost_unit
        return round(duration_seconds * float(cost_per_unit), 8) if unit == "second" else round((duration_seconds / 60.0) * float(cost_per_unit), 8)

    # Reserve a small cost for the follow-up LLM turn after transcription.
    def _audio_llm_reserve_cost(self, audio_config: Any | None) -> float:
        value = getattr(audio_config, "llm_followup_reserve_cost_eur", self.settings.audio_llm_followup_reserve_cost_eur) if audio_config is not None else self.settings.audio_llm_followup_reserve_cost_eur
        return max(0.0, float(value)) if isinstance(value, (int, float)) else 0.0

    # Calculate remaining cost if a limit exists; None means unlimited/unknown.
    def _remaining_cost(self, limit: Any, spent: Any) -> float | None:
        if not isinstance(limit, (int, float)) or limit < 0:
            return None
        if not isinstance(spent, (int, float)):
            spent = 0.0
        return float(limit) - float(spent)

    # Extract the backend conversation id from accepted response shapes.
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

    # Normalize numeric values that may come from providers as int/float/string.
    def _normalize_int(self, value: Any) -> int | None:
        if isinstance(value, bool):
            return None
        if isinstance(value, int):
            return value
        if isinstance(value, float):
            return int(round(value))
        if isinstance(value, str) and value.strip().isdigit():
            return int(value.strip())
        return None

    # Build a deterministic response without going through LLM orchestration.
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

    # Normalize optional strings from loose external payloads.
    def _clean(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None
