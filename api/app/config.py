from functools import lru_cache

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    app_name: str = "sales-agent-api"
    app_env: str = Field(default="dev", alias="APP_ENV")
    api_port: int = Field(default=8000, alias="API_PORT")
    llm_provider: str = Field(default="openai", alias="LLM_PROVIDER")
    openai_api_key: str = Field(default="", alias="OPENAI_API_KEY")
    ollama_base_url: str = Field(default="", alias="OLLAMA_BASE_URL")
    backend_base_url: str = Field(default="http://sales-agent-nginx/backend", alias="BACKEND_BASE_URL")
    crm_base_url: str = Field(default="", alias="CRM_BASE_URL")
    rag_api_url: str = Field(default="", alias="RAG_API_URL")
    sales_agent_bearer_token: str = Field(default="", alias="SALES_AGENT_BEARER_TOKEN")


@lru_cache
def get_settings() -> Settings:
    return Settings()
