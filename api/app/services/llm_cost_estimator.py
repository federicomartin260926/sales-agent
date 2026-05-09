from __future__ import annotations

from app.schemas.llm import LLMUsage


class LLMCostEstimator:
    # Approximate USD pricing per 1M tokens. Keep this table small and easy to tune.
    MODEL_PRICING: dict[str, dict[str, float]] = {
        "gpt-4.1": {"input": 2.0, "output": 8.0, "cached_input": 0.5},
        "gpt-4.1-mini": {"input": 0.4, "output": 1.6, "cached_input": 0.1},
        "gpt-4o": {"input": 2.5, "output": 10.0, "cached_input": 0.625},
        "gpt-4o-mini": {"input": 0.15, "output": 0.6, "cached_input": 0.0375},
    }

    def estimate(self, provider: str, model: str | None, usage: LLMUsage | None) -> float | None:
        if usage is None:
            return None

        normalized_provider = provider.strip().lower()
        if normalized_provider != "openai":
            return self._ollama_estimate(usage)

        normalized_model = (model or usage.model or "").strip().lower()
        pricing = self._pricing_for_model(normalized_model)
        if pricing is None:
            return None

        input_tokens = usage.input_tokens
        if input_tokens is None:
            input_tokens = usage.prompt_tokens

        output_tokens = usage.output_tokens
        if output_tokens is None:
            output_tokens = usage.completion_tokens

        if input_tokens is None or output_tokens is None:
            return None

        cached_tokens = max(0, usage.cached_tokens or 0)
        uncached_input_tokens = max(0, input_tokens - cached_tokens)
        estimated_cost = (
            uncached_input_tokens * pricing["input"]
            + cached_tokens * pricing["cached_input"]
            + output_tokens * pricing["output"]
        ) / 1_000_000

        return round(estimated_cost, 8)

    def _ollama_estimate(self, usage: LLMUsage) -> float | None:
        if usage.prompt_tokens is None or usage.completion_tokens is None:
            return None

        # Ollama is often self-hosted, so keep the estimate at zero while still recording token usage.
        return 0.0

    def _pricing_for_model(self, model: str) -> dict[str, float] | None:
        normalized = model.lower()
        if normalized in self.MODEL_PRICING:
            return self.MODEL_PRICING[normalized]

        for key, pricing in self.MODEL_PRICING.items():
            if normalized.startswith(key):
                return pricing

        return None
