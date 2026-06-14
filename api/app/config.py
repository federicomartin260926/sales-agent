from functools import lru_cache
from typing import ClassVar

from zoneinfo import ZoneInfo

from pydantic import AliasChoices, Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    SAFE_DEFAULT_BUSINESS_TIMEZONE: ClassVar[str] = "Europe/Madrid"

    app_name: str = "sales-agent-api"
    app_env: str = Field(default="dev", alias="APP_ENV")
    api_port: int = Field(default=8000, alias="API_PORT")
    llm_provider: str = Field(default="openai", alias="LLM_PROVIDER")
    openai_api_key: str = Field(default="", alias="OPENAI_API_KEY")
    openai_timeout_seconds: int = Field(default=15, alias="OPENAI_TIMEOUT_SECONDS")
    openai_responses_timeout_seconds: int = Field(default=60, alias="OPENAI_RESPONSES_TIMEOUT_SECONDS")
    openai_transcription_model: str = Field(default="gpt-4o-mini-transcribe", alias="OPENAI_TRANSCRIPTION_MODEL")
    openai_conversation_state_enabled: bool = Field(default=True, alias="OPENAI_CONVERSATION_STATE_ENABLED")
    openai_conversation_state_ttl_hours: int = Field(default=24, alias="OPENAI_CONVERSATION_STATE_TTL_HOURS")
    default_business_timezone: str = Field(default="Europe/Madrid", alias="SA_DEFAULT_BUSINESS_TIMEZONE")
    contact_context_cache_ttl_minutes: int = Field(default=360, alias="CONTACT_CONTEXT_CACHE_TTL_MINUTES")
    audio_transcription_provider: str = Field(default="openai", alias="AUDIO_TRANSCRIPTION_PROVIDER")
    audio_transcription_model: str = Field(default="gpt-4o-mini-transcribe", alias="AUDIO_TRANSCRIPTION_MODEL")
    audio_transcription_cost_unit: str = Field(default="minute", alias="AUDIO_TRANSCRIPTION_COST_UNIT")
    audio_transcription_cost_per_unit_eur: float = Field(
        default=0.02,
        validation_alias=AliasChoices(
            "OPENAI_AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR",
            "AUDIO_TRANSCRIPTION_COST_PER_MINUTE_EUR",
            "AUDIO_TRANSCRIPTION_COST_PER_UNIT_EUR",
        ),
    )
    audio_transcription_enabled: bool = Field(default=True, alias="AUDIO_TRANSCRIPTION_ENABLED")
    audio_transcription_currency: str = Field(default="EUR", alias="AUDIO_TRANSCRIPTION_CURRENCY")
    audio_transcription_notes: str = Field(default="", alias="AUDIO_TRANSCRIPTION_NOTES")
    audio_llm_followup_reserve_cost_eur: float = Field(default=0.01, alias="AUDIO_LLM_FOLLOWUP_RESERVE_COST_EUR")
    ai_billing_mode: str = Field(default="byok", alias="AI_BILLING_MODE")
    ollama_base_url: str = Field(default="http://ollama-vpn-bridge:11434", alias="OLLAMA_BASE_URL")
    ollama_timeout_seconds: int = Field(default=15, alias="OLLAMA_TIMEOUT_SECONDS")
    audio_timeout_seconds: int = Field(default=15, alias="AUDIO_TIMEOUT_SECONDS")
    audio_gateway_base_url: str = Field(default="", alias="AUDIO_GATEWAY_BASE_URL")
    audio_gateway_bearer_token: str = Field(default="", alias="AUDIO_GATEWAY_BEARER_TOKEN")
    audio_max_bytes: int = Field(default=25 * 1024 * 1024, alias="AUDIO_MAX_BYTES")
    backend_base_url: str = Field(default="http://sales-agent-nginx", alias="BACKEND_BASE_URL")
    rag_api_url: str = Field(default="", alias="RAG_API_URL")
    sales_agent_bearer_token: str = Field(default="", alias="SALES_AGENT_BEARER_TOKEN")
    mcp_test_authorization: str = Field(default="", alias="MCP_TEST_AUTHORIZATION")
    new_llm_orchestration_enabled: bool = Field(default=False, alias="NEW_LLM_ORCHESTRATION_ENABLED")

    def safe_default_business_timezone(self) -> str:
        candidate = self.default_business_timezone.strip()
        if candidate:
            try:
                ZoneInfo(candidate)
            except Exception:
                return self.SAFE_DEFAULT_BUSINESS_TIMEZONE
            return candidate

        return self.SAFE_DEFAULT_BUSINESS_TIMEZONE


@lru_cache
def get_settings() -> Settings:
    return Settings()
