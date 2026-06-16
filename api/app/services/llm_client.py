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
        self.last_previous_response_id_invalid: bool = False

    async def resolve_configuration(self) -> dict[str, str]:
        return await self.runtime_settings_client.effective_values()

    async def generate(
        self,
        provider: str,
        system_prompt: str,
        user_prompt: str,
        configuration: dict[str, str] | None = None,
    ) -> LLMResponseResult:
        self.last_previous_response_id_invalid = False
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
        previous_response_id: str | None = None,
        tool_choice: Any | None = None,
        parallel_tool_calls: bool | None = None,
        single_tool_call: bool = False,
        max_tool_rounds: int | None = None,
    ) -> LLMResponseResult:
        self.last_mcp_error = None
        self.last_previous_response_id_invalid = False
        config = configuration if configuration is not None else await self.resolve_configuration()
        normalized_provider = provider.strip().lower()
        if normalized_provider != "openai" or not mcp_config.enabled:
            return await self.generate(provider, system_prompt, user_prompt, config)

        try:
            return await self._generate_openai_responses(
                system_prompt,
                user_prompt,
                config,
                mcp_config,
                previous_response_id=previous_response_id,
                tool_choice=tool_choice,
                parallel_tool_calls=parallel_tool_calls,
                single_tool_call=single_tool_call,
                max_tool_rounds=max_tool_rounds,
            )
        except Exception as exc:
            if single_tool_call:
                self.last_mcp_error = f"responses_mcp_path_failed:{exc.__class__.__name__}"
                raise
            if previous_response_id is not None and self._is_previous_response_id_error(exc):
                try:
                    result = await self._generate_openai_responses(
                        system_prompt,
                        user_prompt,
                        config,
                        mcp_config,
                        previous_response_id=None,
                        tool_choice=tool_choice,
                        parallel_tool_calls=parallel_tool_calls,
                        single_tool_call=single_tool_call,
                        max_tool_rounds=max_tool_rounds,
                    )
                except Exception as retry_exc:
                    self.last_previous_response_id_invalid = True
                    self.last_mcp_error = f"responses_mcp_path_failed:{retry_exc.__class__.__name__}"
                    logger.warning(
                        "OpenAI Responses MCP path failed after previous_response_id retry, falling back to legacy chat completions: type=%s repr=%r",
                        retry_exc.__class__.__name__,
                        retry_exc,
                        exc_info=True,
                    )
                    return await self._generate_openai(system_prompt, user_prompt, config)

                return result

            self.last_mcp_error = f"responses_mcp_path_failed:{exc.__class__.__name__}"
            logger.warning(
                "OpenAI Responses MCP path failed, falling back to legacy chat completions: type=%s repr=%r",
                exc.__class__.__name__,
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
        previous_response_id: str | None = None,
        tool_choice: Any | None = None,
        parallel_tool_calls: bool | None = None,
        single_tool_call: bool = False,
        max_tool_rounds: int | None = None,
    ) -> LLMResponseResult:
        base_url = configuration.get("openai_base_url", "").strip().rstrip("/")
        model = configuration.get("openai_model", "").strip()
        api_key = configuration.get("openai_api_key", "").strip()
        timeout_seconds = self._parse_timeout(
            configuration.get("openai_responses_timeout_seconds"),
            self.settings.openai_responses_timeout_seconds,
        )

        if base_url == "" or model == "" or api_key == "":
            raise ValueError("OpenAI configuration is incomplete")

        payload: dict[str, Any] = {
            "model": model,
            "instructions": system_prompt,
            "input": self._build_responses_input(user_prompt),
            "temperature": 0.2,
            "text": {"format": {"type": "json_object"}},
        }
        normalized_previous_response_id = self._normalize_previous_response_id(previous_response_id)
        if single_tool_call or max_tool_rounds == 1:
            normalized_previous_response_id = None
        if normalized_previous_response_id is not None:
            payload["previous_response_id"] = normalized_previous_response_id

        tools = self._build_openai_mcp_tools(mcp_config)
        if tools != []:
            payload["tools"] = tools
        if tool_choice is not None:
            payload["tool_choice"] = tool_choice
        if parallel_tool_calls is not None:
            payload["parallel_tool_calls"] = parallel_tool_calls
        sanitized_payload = self._sanitize_openai_responses_payload(payload)
        sanitized_payload["single_tool_call"] = single_tool_call
        self._log_openai_responses_mcp_request(model, tools, mcp_config, sanitized_payload)

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
                exc,
            )

            raise RuntimeError(self._format_openai_responses_error(exc, status_code, response_body)) from exc
        except httpx.TimeoutException as exc:
            self._log_openai_responses_error(
                None,
                None,
                self._sanitize_openai_responses_payload(payload),
                exc,
            )
            raise RuntimeError(self._format_openai_responses_error(exc)) from exc
        except (httpx.HTTPError, ValueError) as exc:
            self._log_openai_responses_error(
                None,
                None,
                self._sanitize_openai_responses_payload(payload),
                exc,
            )
            raise RuntimeError(self._format_openai_responses_error(exc)) from exc

        content = self._extract_responses_content(payload_json)
        if content is None:
            response_summary = self._sanitize_openai_responses_response_summary(payload_json)
            logger.info(
                "OpenAI Responses payload missing message content response_summary=%s",
                json.dumps(response_summary, ensure_ascii=False, default=str),
            )
            raise ValueError("OpenAI responses payload did not include message content")

        tool_traces = self._extract_tool_traces(payload_json)
        self._log_openai_responses_tool_traces(model, tool_traces)
        if single_tool_call and len(tool_traces) > 1:
            raise RuntimeError("Bounded single tool call violated: multiple tool traces returned")
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
        visited: set[int] = set()
        for item in output:
            for candidate in self._iter_tool_trace_candidates(item, visited):
                traces.append(self._build_tool_trace(candidate))

        return traces

    def _iter_tool_trace_candidates(self, value: Any, visited: set[int]) -> list[dict[str, Any]]:
        if isinstance(value, dict):
            object_id = id(value)
            if object_id in visited:
                return []
            visited.add(object_id)

            candidates: list[dict[str, Any]] = []
            if self._is_tool_trace_candidate(value):
                candidates.append(value)

            for nested_value in value.values():
                candidates.extend(self._iter_tool_trace_candidates(nested_value, visited))

            return candidates

        if isinstance(value, list):
            candidates: list[dict[str, Any]] = []
            for nested_value in value:
                candidates.extend(self._iter_tool_trace_candidates(nested_value, visited))
            return candidates

        return []

    def _is_tool_trace_candidate(self, item: dict[str, Any]) -> bool:
        for key in ("tool_name", "toolName", "name"):
            value = item.get(key)
            if isinstance(value, str) and value.strip() != "":
                return True

        return False

    def _build_tool_trace(self, item: dict[str, Any]) -> LLMToolTrace:
        item_type = str(item.get("type") or "").strip().lower()
        return LLMToolTrace(
            type=item_type or None,
            server_label=self._string_or_none(item.get("server_label") or item.get("serverLabel")),
            tool_name=self._string_or_none(item.get("tool_name") or item.get("toolName") or item.get("name")),
            arguments=self._normalize_arguments(item.get("arguments") or item.get("input")),
            output=item.get("output") if item.get("output") is not None else item.get("result"),
            status=self._string_or_none(item.get("status")),
            raw=item,
        )

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

        authorization = self._mcp_authorization_token(mcp_config)
        if authorization != "":
            tool["authorization"] = authorization

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

    def _mcp_authorization_token(self, mcp_config: McpRemoteConfig) -> str:
        override = self._normalize_mcp_authorization(self.settings.mcp_test_authorization)
        if override != "":
            return override

        downstream_token = self._normalize_mcp_authorization(getattr(mcp_config, "downstream_authorization_token", None))
        if downstream_token != "":
            return downstream_token

        bearer_token = self._normalize_mcp_authorization(mcp_config.bearer_token)
        return bearer_token

    def _normalize_mcp_authorization(self, value: str | None) -> str:
        normalized = (value or "").strip()
        if normalized == "":
            return ""

        if normalized.lower().startswith("bearer "):
            normalized = normalized[7:].strip()

        return normalized

    def _sanitize_openai_responses_payload(self, payload: dict[str, Any]) -> dict[str, Any]:
        sanitized: dict[str, Any] = {
            "model": payload.get("model"),
            "instructions": self._preview_sensitive_text(payload.get("instructions")),
            "input": self._preview_sensitive_text(payload.get("input")),
        }

        input_value = payload.get("input")
        if isinstance(input_value, str):
            sanitized["input_length"] = len(input_value)
        instructions_value = payload.get("instructions")
        if isinstance(instructions_value, str):
            sanitized["instructions_length"] = len(instructions_value)

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
        if "previous_response_id" in payload:
            sanitized["previous_response_id_present"] = payload.get("previous_response_id") is not None
        if "tool_choice" in payload:
            sanitized["tool_choice"] = payload.get("tool_choice")
        if "parallel_tool_calls" in payload:
            sanitized["parallel_tool_calls"] = payload.get("parallel_tool_calls")
        if "text" in payload:
            sanitized["text"] = payload.get("text")

        return sanitized

    def _sanitize_openai_responses_response_summary(self, payload: Any) -> dict[str, Any]:
        summary: dict[str, Any] = {}
        if not isinstance(payload, dict):
            summary["response_type"] = type(payload).__name__
            return summary

        response_id = payload.get("id")
        if isinstance(response_id, str) and response_id.strip() != "":
            summary["response_id"] = self._preview_identifier(response_id)

        output = payload.get("output")
        output_item_types: list[str] = []
        output_item_summaries: list[dict[str, Any]] = []
        mcp_call_count = 0
        message_count = 0
        reasoning_count = 0
        has_error = payload.get("error") is not None

        if isinstance(output, list):
            for item in output:
                if not isinstance(item, dict):
                    continue

                item_keys = sorted(str(key) for key in item.keys())
                item_type_raw = item.get("type")
                item_type = self._preview_sensitive_text(item_type_raw) if isinstance(item_type_raw, str) else None
                item_type_normalized = str(item_type_raw or "").strip().lower()
                if item_type is not None:
                    output_item_types.append(item_type)

                if item_type_normalized == "mcp_call":
                    mcp_call_count += 1
                elif item_type_normalized == "message":
                    message_count += 1
                elif item_type_normalized == "reasoning":
                    reasoning_count += 1

                content_value = item.get("content")
                content_item_types: list[str] = []
                has_content = content_value is not None
                if isinstance(content_value, list):
                    for content_item in content_value:
                        if not isinstance(content_item, dict):
                            continue
                        content_type = content_item.get("type")
                        if isinstance(content_type, str) and content_type.strip() != "":
                            content_item_types.append(content_type.strip())
                    has_content = True
                elif isinstance(content_value, str):
                    has_content = content_value.strip() != ""

                item_summary: dict[str, Any] = {
                    "type": item_type,
                    "keys": item_keys,
                    "status": self._preview_sensitive_text(item.get("status")) if isinstance(item.get("status"), str) else item.get("status"),
                    "name": self._preview_sensitive_text(item.get("name")) if isinstance(item.get("name"), str) else None,
                    "tool_name": self._preview_sensitive_text(item.get("tool_name")) if isinstance(item.get("tool_name"), str) else None,
                    "call_id": self._preview_identifier(item.get("call_id")) if isinstance(item.get("call_id"), str) else None,
                    "server_label": self._preview_sensitive_text(item.get("server_label")) if isinstance(item.get("server_label"), str) else None,
                    "has_content": has_content,
                }
                if content_item_types != []:
                    item_summary["content_item_types"] = content_item_types

                if "output" in item:
                    output_value = item.get("output")
                    item_summary["has_output"] = True
                    item_summary["output_type"] = type(output_value).__name__
                    if isinstance(output_value, str):
                        item_summary["output_length"] = len(output_value)
                        item_summary["output_preview"] = self._preview_sensitive_text(output_value)
                    elif isinstance(output_value, dict):
                        output_keys = sorted(str(key) for key in output_value.keys())
                        item_summary["output_keys"] = output_keys
                        if "ok" in output_value:
                            item_summary["output_ok"] = output_value.get("ok")
                        if "found" in output_value:
                            item_summary["output_found"] = output_value.get("found")
                        if "available" in output_value:
                            item_summary["output_available"] = output_value.get("available")
                        if "count" in output_value:
                            item_summary["output_count"] = output_value.get("count")
                        item_summary["output_has_items"] = isinstance(output_value.get("items"), list) and output_value.get("items") != []
                        item_summary["output_has_slots"] = isinstance(output_value.get("slots"), list) and output_value.get("slots") != []

                if "error" in item:
                    error_value = item.get("error")
                    item_summary["has_error_field"] = True
                    if isinstance(error_value, dict):
                        for key in ("type", "status", "code"):
                            if key in error_value:
                                value = error_value.get(key)
                                item_summary[f"error_{key}"] = self._preview_identifier(value) if isinstance(value, str) else value
                    elif isinstance(error_value, str):
                        item_summary["error_type"] = self._preview_identifier(error_value)
                output_item_summaries.append(item_summary)

        summary["output_item_types"] = output_item_types
        summary["output_item_count"] = len(output_item_summaries)
        summary["output_item_summaries"] = output_item_summaries
        summary["mcp_call_count"] = mcp_call_count
        summary["message_count"] = message_count
        summary["reasoning_count"] = reasoning_count
        summary["has_error"] = has_error

        return summary

    def _normalize_previous_response_id(self, value: str | None) -> str | None:
        if not isinstance(value, str):
            return None

        normalized = value.strip()
        if normalized == "":
            return None

        return normalized

    def _is_previous_response_id_error(self, exc: Exception) -> bool:
        normalized = ""
        status_code = None

        if isinstance(exc, httpx.HTTPStatusError):
            status_code = exc.response.status_code if exc.response is not None else None
            body = self._response_body_text(exc.response)
            if isinstance(body, str):
                normalized = body.lower()
        else:
            cause = exc.__cause__
            if isinstance(cause, httpx.HTTPStatusError):
                status_code = cause.response.status_code if cause.response is not None else None
                body = self._response_body_text(cause.response)
                if isinstance(body, str):
                    normalized = body.lower()

            if normalized == "":
                normalized = f"{exc!r} {exc}".lower()
                if status_code is None and "status_code=400" in normalized:
                    status_code = 400

        if status_code != 400 or normalized == "":
            return False

        if "previous_response_id" not in normalized and "previous response id" not in normalized:
            return False

        return any(
            marker in normalized
            for marker in (
                "invalid",
                "expired",
                "not found",
                "unknown",
                "missing",
                "does not exist",
                "nonexistent",
                "stale",
            )
        )

    def _log_openai_responses_mcp_request(self, model: str, tools: list[dict[str, Any]], mcp_config: McpRemoteConfig, sanitized_payload: dict[str, Any]) -> None:
        if tools == []:
            logger.info(
                "OpenAI Responses MCP request starting model=%s mcp_enabled=%s tools=0 tool_choice=%s parallel_tool_calls=%s previous_response_id_present=%s single_tool_call=%s",
                model,
                mcp_config.enabled,
                sanitized_payload.get("tool_choice") or "-",
                sanitized_payload.get("parallel_tool_calls"),
                sanitized_payload.get("previous_response_id_present", False),
                sanitized_payload.get("single_tool_call", False),
            )
            logger.debug("OpenAI Responses MCP sanitized payload=%s", json.dumps(sanitized_payload, ensure_ascii=False, default=str))
            return

        tool = tools[0]
        allowed_tools = tool.get("allowed_tools") if isinstance(tool.get("allowed_tools"), list) else []
        authorization_present = isinstance(tool.get("authorization"), str) and tool.get("authorization") != ""
        logger.info(
            "OpenAI Responses MCP request starting model=%s mcp_enabled=%s server_label=%s server_url=%s allowed_tools=%d authorization_present=%s require_approval=%s tool_choice=%s parallel_tool_calls=%s previous_response_id_present=%s single_tool_call=%s",
            model,
            mcp_config.enabled,
            tool.get("server_label") or "-",
            tool.get("server_url") or "-",
            len(allowed_tools),
            authorization_present,
            tool.get("require_approval") or "-",
            sanitized_payload.get("tool_choice") or "-",
            sanitized_payload.get("parallel_tool_calls"),
            sanitized_payload.get("previous_response_id_present", False),
            sanitized_payload.get("single_tool_call", False),
        )
        logger.debug("OpenAI Responses MCP sanitized payload=%s", json.dumps(sanitized_payload, ensure_ascii=False, default=str))

    def _log_openai_responses_tool_traces(self, model: str, tool_traces: list[LLMToolTrace]) -> None:
        if tool_traces == []:
            logger.info("LLM openai responses tool traces model=%s count=0 repeated_tool_call_detected=false", model)
            return

        trace_summaries: list[dict[str, Any]] = []
        fingerprints: list[tuple[str | None, str]] = []
        for trace in tool_traces:
            arguments = trace.arguments if isinstance(trace.arguments, dict) else {}
            sanitized_arguments = self._sanitize_trace_arguments_for_log(arguments)
            try:
                fingerprint_arguments = json.dumps(sanitized_arguments, sort_keys=True, ensure_ascii=False, default=str)
            except Exception:
                fingerprint_arguments = repr(sanitized_arguments)

            fingerprints.append((trace.tool_name, fingerprint_arguments))
            trace_summaries.append(
                {
                    "tool_name": trace.tool_name,
                    "status": trace.status,
                    "arguments": sanitized_arguments,
                }
            )

        repeated_tool_call_detected = len(fingerprints) != len(set(fingerprints))
        logger.info(
            "LLM openai responses tool traces model=%s count=%d repeated_tool_call_detected=%s trace_summaries=%s",
            model,
            len(tool_traces),
            repeated_tool_call_detected,
            json.dumps(trace_summaries, ensure_ascii=False, default=str),
        )

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

    def _preview_sensitive_text(self, value: Any) -> str | None:
        if not isinstance(value, str):
            return None

        compact = " ".join(value.strip().split())
        compact = re.sub(r"\bBearer\s+[A-Za-z0-9._-]+\b", "Bearer ***REDACTED***", compact, flags=re.IGNORECASE)
        compact = re.sub(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}", "[REDACTED_EMAIL]", compact)
        compact = re.sub(r"\+?\d[\d\s().-]{7,}\d", "[REDACTED_PHONE]", compact)
        if len(compact) > 240:
            compact = compact[:240].rstrip() + "…"
        return compact

    def _preview_identifier(self, value: Any, max_chars: int = 64) -> str | None:
        if not isinstance(value, str):
            return None

        compact = " ".join(value.strip().split())
        if len(compact) > max_chars:
            compact = compact[:max_chars].rstrip() + "…"
        return compact

    def _sanitize_trace_arguments_for_log(self, value: Any) -> Any:
        if isinstance(value, dict):
            return {key: self._sanitize_trace_arguments_for_log(item) for key, item in value.items()}

        if isinstance(value, list):
            return [self._sanitize_trace_arguments_for_log(item) for item in value]

        if isinstance(value, tuple):
            return [self._sanitize_trace_arguments_for_log(item) for item in value]

        if isinstance(value, set):
            return [self._sanitize_trace_arguments_for_log(item) for item in sorted(value, key=lambda item: repr(item))]

        if isinstance(value, str):
            return self._preview_sensitive_text(value)

        return value

    def _redact_secret_like_values(self, value: str) -> str:
        redacted = re.sub(r'("Authorization"\\s*:\\s*"Bearer\\s+)[^"]+(")', r'\\1[REDACTED]\\2', value, flags=re.IGNORECASE)
        redacted = re.sub(r'(Bearer\\s+)[A-Za-z0-9._~-]+', r'\\1[REDACTED]', redacted, flags=re.IGNORECASE)
        return redacted

    def _format_openai_responses_error(
        self,
        exc: Exception,
        status_code: int | None = None,
        body: str | None = None,
    ) -> str:
        parts = [
            f"OpenAI responses request failed [{exc.__class__.__name__}]: {exc!r}",
        ]
        if status_code is not None:
            parts.append(f"status_code={status_code}")
        if body is not None:
            parts.append(f"response_body={body}")
        return "; ".join(parts)

    def _log_openai_responses_error(
        self,
        status_code: int | None,
        body: str | None,
        sanitized_payload: dict[str, Any],
        exc: Exception,
    ) -> None:
        logger.warning(
            "OpenAI Responses MCP request failed exception_type=%s exception_repr=%r status_code=%s body=%s sanitized_payload=%s",
            exc.__class__.__name__,
            exc,
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
