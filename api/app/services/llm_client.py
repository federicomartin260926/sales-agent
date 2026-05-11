from __future__ import annotations

import json
import logging
import re
from typing import Any

import httpx

from app.config import Settings
from app.schemas.llm import LLMResponseResult, LLMToolTrace, LLMUsage, McpRemoteConfig
from app.services.llm_cost_estimator import LLMCostEstimator
from app.services.runtime_settings_client import RuntimeSettingsClient


logger = logging.getLogger(__name__)


class LLMClient:
    def __init__(self, settings: Settings, runtime_settings_client: RuntimeSettingsClient | None = None, transport: httpx.AsyncBaseTransport | None = None) -> None:
        self.settings = settings
        self.runtime_settings_client = runtime_settings_client or RuntimeSettingsClient(settings)
        self.transport = transport
        self.cost_estimator = LLMCostEstimator()
        self.last_mcp_error: str | None = None

    async def resolve_configuration(self) -> dict[str, str]:
        return await self.runtime_settings_client.effective_values()

    async def generate(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        configuration: dict[str, str] | None = None,
    ) -> LLMResponseResult:
        config = configuration if configuration is not None else await self.resolve_configuration()
        normalized_provider = provider.strip().lower()
        if normalized_provider == "openai":
            return await self._generate_openai(system_prompt, user_prompt, config)
        if normalized_provider == "ollama":
            return await self._generate_ollama(system_prompt, user_prompt, config)

        raise ValueError(f"Unsupported LLM provider '{provider}'")

    async def generate_with_mcp(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        mcp_config: McpRemoteConfig,
        configuration: dict[str, str] | None = None,
    ) -> LLMResponseResult:
        self.last_mcp_error = None
        config = configuration if configuration is not None else await self.resolve_configuration()
        normalized_provider = provider.strip().lower()
        if normalized_provider != "openai" or not mcp_config.enabled:
            return await self.generate(provider, system_prompt, user_prompt, config)

        try:
            return await self._generate_openai_responses(system_prompt, user_prompt, config, mcp_config)
        except Exception as exc:
            self.last_mcp_error = f"responses_mcp_path_failed:{exc.__class__.__name__}"
            logger.warning(
                "OpenAI Responses MCP path failed, falling back to legacy chat completions: %r",
                exc,
                exc_info=True,
            )
            return await self._generate_openai(system_prompt, user_prompt, config)

    async def _generate_openai(self, system_prompt: str, user_prompt: str, configuration: dict[str, str]) -> LLMResponseResult:
        base_url = configuration.get("openai_base_url", "").strip().rstrip("/")
        model = configuration.get("openai_model", "").strip()
        api_key = configuration.get("openai_api_key", "").strip()
        timeout_seconds = self._parse_timeout(configuration.get("openai_timeout_seconds"), self.settings.openai_timeout_seconds)

        if base_url == "" or model == "" or api_key == "":
            raise ValueError("OpenAI configuration is incomplete")

        payload = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "temperature": 0.2,
            "response_format": {"type": "json_object"},
        }

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        }

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post("/chat/completions", json=payload, headers=headers)
                response.raise_for_status()
                payload_json = response.json()
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError(f"OpenAI request failed: {exc}") from exc

        content = self._extract_openai_content(payload_json)
        if content is None:
            raise ValueError("OpenAI response did not include message content")

        response_id = self._extract_response_id(payload_json)
        usage = self._extract_usage("openai", model, payload_json)
        estimated_cost = self.cost_estimator.estimate("openai", model, usage)

        logger.debug("LLM openai generation completed model=%s", model)
        return LLMResponseResult(provider="openai", model=model, content=content, response_id=response_id, usage=usage, estimated_cost=estimated_cost, raw_payload=payload_json)

    async def _generate_openai_responses(
        self,
        system_prompt: str,
        user_prompt: str,
        configuration: dict[str, str],
        mcp_config: McpRemoteConfig,
    ) -> LLMResponseResult:
        base_url = configuration.get("openai_base_url", "").strip().rstrip("/")
        model = configuration.get("openai_model", "").strip()
        api_key = configuration.get("openai_api_key", "").strip()
        timeout_seconds = self._parse_timeout(configuration.get("openai_timeout_seconds"), self.settings.openai_timeout_seconds)

        if base_url == "" or model == "" or api_key == "":
            raise ValueError("OpenAI configuration is incomplete")

        payload: dict[str, Any] = {
            "model": model,
            "instructions": system_prompt,
            "input": self._build_responses_input(user_prompt),
            "temperature": 0.2,
            "text": {"format": {"type": "json_object"}},
        }

        tools = self._build_openai_mcp_tools(mcp_config)
        if tools != []:
            payload["tools"] = tools

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        headers = {
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
        }

        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post("/responses", json=payload, headers=headers)
                response.raise_for_status()
                payload_json = response.json()
        except httpx.HTTPStatusError as exc:
            status_code = exc.response.status_code if exc.response is not None else None
            response_body = self._response_body_text(exc.response)

            self._log_openai_responses_error(
                status_code,
                response_body,
                self._sanitize_openai_responses_payload(payload),
            )

            raise RuntimeError(
                f"OpenAI responses request failed: {exc}; "
                f"status_code={status_code}; response_body={response_body}"
            ) from exc
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError(f"OpenAI responses request failed: {exc}") from exc

        content = self._extract_responses_content(payload_json)
        if content is None:
            raise ValueError("OpenAI responses payload did not include message content")

        tool_traces = self._extract_tool_traces(payload_json)
        response_id = self._extract_response_id(payload_json)
        usage = self._extract_usage("openai", model, payload_json)
        estimated_cost = self.cost_estimator.estimate("openai", model, usage)

        logger.debug("LLM openai responses generation completed model=%s tools=%d", model, len(tool_traces))
        return LLMResponseResult(
            provider="openai",
            model=model,
            content=content,
            response_id=response_id,
            usage=usage,
            estimated_cost=estimated_cost,
            raw_payload=payload_json,
            tool_traces=tool_traces,
        )

    async def _generate_ollama(self, system_prompt: str, user_prompt: str, configuration: dict[str, str]) -> LLMResponseResult:
        base_url = configuration.get("ollama_base_url", "").strip().rstrip("/")
        model = configuration.get("ollama_model", "").strip()
        timeout_seconds = self._parse_timeout(configuration.get("ollama_timeout_seconds"), self.settings.ollama_timeout_seconds)

        if base_url == "" or model == "":
            raise ValueError("Ollama configuration is incomplete")

        payload = {
            "model": model,
            "messages": [
                {"role": "system", "content": system_prompt},
                {"role": "user", "content": user_prompt},
            ],
            "stream": False,
            "format": "json",
            "options": {"temperature": 0.2},
        }

        timeout = httpx.Timeout(timeout_seconds, connect=2.0)
        try:
            async with httpx.AsyncClient(base_url=base_url, timeout=timeout, transport=self.transport) as client:
                response = await client.post("/api/chat", json=payload)
                response.raise_for_status()
                payload_json = response.json()
        except (httpx.HTTPError, ValueError) as exc:
            raise RuntimeError(f"Ollama request failed: {exc}") from exc

        content = self._extract_ollama_content(payload_json)
        if content is None:
            raise ValueError("Ollama response did not include message content")

        response_id = self._extract_response_id(payload_json)
        usage = self._extract_usage("ollama", model, payload_json)
        estimated_cost = self.cost_estimator.estimate("ollama", model, usage)

        logger.debug("LLM ollama generation completed model=%s", model)
        return LLMResponseResult(
            provider="ollama",
            model=model,
            content=content,
            response_id=response_id,
            usage=usage,
            estimated_cost=estimated_cost,
            raw_payload=payload_json,
        )

    def _extract_openai_content(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        choices = payload.get("choices")
        if not isinstance(choices, list) or not choices:
            return None

        first_choice = choices[0]
        if not isinstance(first_choice, dict):
            return None

        message = first_choice.get("message")
        if not isinstance(message, dict):
            return None

        content = message.get("content")
        if isinstance(content, str) and content.strip() != "":
            return content.strip()

        return None

    def _extract_ollama_content(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        message = payload.get("message")
        if isinstance(message, dict):
            content = message.get("content")
            if isinstance(content, str) and content.strip() != "":
                return content.strip()

        response_content = payload.get("response")
        if isinstance(response_content, str) and response_content.strip() != "":
            return response_content.strip()

        return None

    def _extract_responses_content(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        output_text = payload.get("output_text")
        if isinstance(output_text, str) and output_text.strip() != "":
            return output_text.strip()

        output = payload.get("output")
        if not isinstance(output, list):
            return None

        text_chunks: list[str] = []
        for item in output:
            if not isinstance(item, dict):
                continue

            item_type = str(item.get("type") or "").strip().lower()
            if item_type not in {"message", "output_text"}:
                continue

            content = item.get("content")
            if isinstance(content, str) and content.strip() != "":
                text_chunks.append(content.strip())
                continue

            if isinstance(content, list):
                for block in content:
                    if not isinstance(block, dict):
                        continue
                    block_type = str(block.get("type") or "").strip().lower()
                    if block_type in {"output_text", "text"}:
                        text = block.get("text")
                        if isinstance(text, str) and text.strip() != "":
                            text_chunks.append(text.strip())

        if text_chunks == []:
            return None

        return "\n".join(text_chunks).strip()

    def _extract_tool_traces(self, payload: Any) -> list[LLMToolTrace]:
        if not isinstance(payload, dict):
            return []

        output = payload.get("output")
        if not isinstance(output, list):
            return []

        traces: list[LLMToolTrace] = []
        for item in output:
            if not isinstance(item, dict):
                continue

            item_type = str(item.get("type") or "").strip().lower()
            if not item_type.startswith("mcp_") and item_type not in {"tool_call", "function_call"}:
                continue

            traces.append(
                LLMToolTrace(
                    type=item_type or None,
                    server_label=self._string_or_none(item.get("server_label") or item.get("serverLabel")),
                    tool_name=self._string_or_none(item.get("tool_name") or item.get("toolName") or item.get("name")),
                    arguments=self._normalize_arguments(item.get("arguments") or item.get("input")),
                    output=item.get("output") if item.get("output") is not None else item.get("result"),
                    status=self._string_or_none(item.get("status")),
                    raw=item,
                )
            )

        return traces

    def _extract_response_id(self, payload: Any) -> str | None:
        if not isinstance(payload, dict):
            return None

        response_id = payload.get("id")
        if isinstance(response_id, str) and response_id.strip() != "":
            return response_id.strip()

        return None

    def _extract_usage(self, provider: str, model: str | None, payload: Any) -> LLMUsage | None:
        if not isinstance(payload, dict):
            return None

        if provider.strip().lower() == "ollama":
            prompt_tokens = self._int_or_none(payload.get("prompt_eval_count"))
            completion_tokens = self._int_or_none(payload.get("eval_count"))
            if prompt_tokens is None and completion_tokens is None:
                return None

            usage = LLMUsage(
                provider=provider,
                model=model,
                input_tokens=prompt_tokens,
                output_tokens=completion_tokens,
                prompt_tokens=prompt_tokens,
                completion_tokens=completion_tokens,
                total_tokens=prompt_tokens + completion_tokens,
            )
            return usage

        usage_payload = payload.get("usage")
        if not isinstance(usage_payload, dict):
            return None

        input_tokens = self._int_or_none(usage_payload.get("input_tokens"))
        if input_tokens is None:
            input_tokens = self._int_or_none(usage_payload.get("prompt_tokens"))

        output_tokens = self._int_or_none(usage_payload.get("output_tokens"))
        if output_tokens is None:
            output_tokens = self._int_or_none(usage_payload.get("completion_tokens"))

        total_tokens = self._int_or_none(usage_payload.get("total_tokens"))

        cached_tokens = self._int_or_none(usage_payload.get("cached_tokens"))
        if cached_tokens is None and isinstance(usage_payload.get("input_tokens_details"), dict):
            cached_tokens = self._int_or_none(usage_payload["input_tokens_details"].get("cached_tokens"))
        if cached_tokens is None and isinstance(usage_payload.get("prompt_tokens_details"), dict):
            cached_tokens = self._int_or_none(usage_payload["prompt_tokens_details"].get("cached_tokens"))

        if input_tokens is None and total_tokens is not None and output_tokens is not None:
            input_tokens = max(0, total_tokens - output_tokens)
        if output_tokens is None and total_tokens is not None and input_tokens is not None:
            output_tokens = max(0, total_tokens - input_tokens)

        if input_tokens is None and output_tokens is None and total_tokens is None and cached_tokens is None:
            return None

        return LLMUsage(
            provider=provider,
            model=model,
            input_tokens=input_tokens,
            output_tokens=output_tokens,
            cached_tokens=cached_tokens,
            total_tokens=total_tokens,
            prompt_tokens=self._int_or_none(usage_payload.get("prompt_tokens")),
            completion_tokens=self._int_or_none(usage_payload.get("completion_tokens")),
        )

    def _build_openai_mcp_tools(self, mcp_config: McpRemoteConfig) -> list[dict[str, Any]]:
        server_url = (mcp_config.server_url or "").strip()
        server_label = (mcp_config.server_label or "").strip()
        if server_url == "" or server_label == "":
            return []

        tool: dict[str, Any] = {
            "type": "mcp",
            "server_label": server_label,
            "server_url": server_url,
        }

        allowed_tools = [tool_name for tool_name in mcp_config.allowed_tools if tool_name.strip() != ""]
        if allowed_tools != []:
            tool["allowed_tools"] = allowed_tools

        approval = self._normalize_approval(mcp_config.require_approval)
        if approval is not None:
            tool["require_approval"] = approval

        bearer_token = (mcp_config.bearer_token or "").strip()
        if bearer_token != "":
            tool["headers"] = {
                "Authorization": f"Bearer {bearer_token}",
            }

        return [tool]

    def _normalize_approval(self, value: str | None) -> str | None:
        if value is None:
            return "never"

        normalized = value.strip().lower()
        if normalized in {"never", "always"}:
            return normalized

        if normalized in {"", "auto"}:
            return "never"

        return None

    def _sanitize_openai_responses_payload(self, payload: dict[str, Any]) -> dict[str, Any]:
        sanitized: dict[str, Any] = {
            "model": payload.get("model"),
            "instructions": payload.get("instructions"),
            "input": payload.get("input"),
        }

        tools = payload.get("tools")
        if isinstance(tools, list):
            sanitized_tools: list[dict[str, Any]] = []
            for item in tools:
                if not isinstance(item, dict):
                    continue
                sanitized_tool: dict[str, Any] = {
                    "type": item.get("type"),
                    "server_label": item.get("server_label"),
                    "server_url": item.get("server_url"),
                    "allowed_tools": item.get("allowed_tools"),
                    "require_approval": item.get("require_approval"),
                }
                sanitized_tools.append(sanitized_tool)

            sanitized["tools"] = sanitized_tools

        return sanitized

    def _build_responses_input(self, user_prompt: str) -> str:
        prompt = user_prompt.strip()
        if prompt == "":
            prompt = "Responde en formato json válido siguiendo exactamente el contrato indicado."
        elif "json" not in prompt.lower():
            prompt = f"Responde en formato json válido siguiendo exactamente el contrato indicado.\n\n{prompt}"

        return prompt

    def _response_body_text(self, response: httpx.Response | None) -> str | None:
        if response is None:
            return None

        try:
            body = response.text
        except Exception:
            try:
                body = response.content.decode("utf-8", errors="replace")
            except Exception:
                return None

        if isinstance(body, str):
            body = body.strip()
            if body != "":
                return self._redact_secret_like_values(body)

        return None

    def _redact_secret_like_values(self, value: str) -> str:
        redacted = re.sub(r'("Authorization"\\s*:\\s*"Bearer\\s+)[^"]+(")', r'\\1[REDACTED]\\2', value, flags=re.IGNORECASE)
        redacted = re.sub(r'(Bearer\\s+)[A-Za-z0-9._~-]+', r'\\1[REDACTED]', redacted, flags=re.IGNORECASE)
        return redacted

    def _log_openai_responses_error(self, status_code: int | None, body: str | None, sanitized_payload: dict[str, Any]) -> None:
        logger.warning(
            "OpenAI Responses MCP request failed status_code=%s body=%s sanitized_payload=%s",
            status_code,
            body or "null",
            json.dumps(sanitized_payload, ensure_ascii=False, default=str),
        )

    def _normalize_arguments(self, value: Any) -> dict[str, Any]:
        if isinstance(value, dict):
            return value

        if isinstance(value, str) and value.strip() != "":
            try:
                parsed = json.loads(value)
                if isinstance(parsed, dict):
                    return parsed
            except Exception:
                return {"raw": value}

        return {}

    def _string_or_none(self, value: Any) -> str | None:
        if isinstance(value, str) and value.strip() != "":
            return value.strip()

        return None

    def _int_or_none(self, value: Any) -> int | None:
        if isinstance(value, bool):
            return None

        if isinstance(value, int):
            return value

        if isinstance(value, float):
            return int(value)

        return None

    def _parse_timeout(self, value: str | None, fallback: int) -> int:
        if value is None:
            return fallback

        try:
            parsed = int(value)
        except ValueError:
            return fallback

        return parsed if parsed > 0 else fallback
