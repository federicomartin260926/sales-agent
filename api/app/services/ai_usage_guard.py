from __future__ import annotations

from pydantic import BaseModel, ConfigDict

from app.services.backend_client import BackendAiUsagePolicy, BackendAiUsageSnapshot, BackendClient


class AiUsageGuardDecision(BaseModel):
    model_config = ConfigDict(extra="ignore")

    allowed: bool
    reason: str | None = None
    limit_type: str | None = None
    policy: BackendAiUsagePolicy | None = None
    usage: BackendAiUsageSnapshot | None = None


class AiUsageGuard:
    def __init__(self, backend_client: BackendClient) -> None:
        self.backend_client = backend_client

    async def evaluate(self, tenant_id: str | None) -> AiUsageGuardDecision:
        if tenant_id is None or tenant_id.strip() == "":
            return AiUsageGuardDecision(allowed=True)

        policy = await self.backend_client.fetch_ai_usage_policy(tenant_id.strip())
        if policy is None:
            return AiUsageGuardDecision(allowed=True)

        if not policy.ai_enabled:
            return AiUsageGuardDecision(allowed=False, reason="ai_disabled", limit_type="disabled", policy=policy)

        usage = await self.backend_client.fetch_ai_usage_snapshot(tenant_id.strip())
        if usage is None:
            return AiUsageGuardDecision(allowed=True, policy=policy)

        daily_limit = policy.daily_cost_limit_eur
        if daily_limit is not None and usage.daily.estimated_cost_eur >= daily_limit:
            return AiUsageGuardDecision(
                allowed=False,
                reason="daily_cost_limit_exceeded",
                limit_type="daily",
                policy=policy,
                usage=usage,
            )

        monthly_limit = policy.monthly_cost_limit_eur
        if monthly_limit is not None and usage.monthly.estimated_cost_eur >= monthly_limit:
            return AiUsageGuardDecision(
                allowed=False,
                reason="monthly_cost_limit_exceeded",
                limit_type="monthly",
                policy=policy,
                usage=usage,
            )

        return AiUsageGuardDecision(allowed=True, policy=policy, usage=usage)
