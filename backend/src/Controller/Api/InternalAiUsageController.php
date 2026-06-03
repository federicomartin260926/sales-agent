<?php

namespace App\Controller\Api;

use App\Entity\AiUsageEvent;
use App\Entity\CommercialPlan;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Entity\TenantAiUsagePolicy;
use App\Repository\AiModelCostReferenceRepository;
use App\Repository\AiUsageEventRepository;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use App\Service\PlanEntitlementResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/ai-usage')]
final class InternalAiUsageController extends AbstractApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantAiUsagePolicyRepository $policies,
        private readonly AiUsageEventRepository $events,
        private readonly ConversationRepository $conversations,
        private readonly ConversationMessageRepository $conversationMessages,
        private readonly InternalBearerTokenValidator $validator,
        private readonly ?TenantAiTopUpRequestRepository $topUpRequests = null,
        private readonly ?PlanEntitlementResolver $planEntitlementResolver = null,
        private readonly ?AiModelCostReferenceRepository $aiModelCosts = null,
    ) {
    }

    #[Route('/{tenantId}/policy', methods: ['GET'])]
    public function policy(string $tenantId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $policy = $this->policies->findOneByTenant($tenant);
        if (!$policy instanceof TenantAiUsagePolicy) {
            $policy = new TenantAiUsagePolicy($tenant);
            $payload = $policy->toArray();
            $payload = $this->augmentPolicyPayload($tenant, $policy, $payload);
            $payload['created_at'] = null;
            $payload['updated_at'] = null;
            $payload['exists'] = false;

            return $this->json($payload);
        }

        $payload = $this->augmentPolicyPayload($tenant, $policy, $policy->toArray());
        $payload['exists'] = true;

        return $this->json($payload);
    }

    #[Route('/{tenantId}/usage', methods: ['GET'])]
    public function usage(string $tenantId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $now = new \DateTimeImmutable('now');
        $dayStart = $now->setTime(0, 0);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0);

        return $this->json([
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'daily' => $this->events->summarizeSince($tenant, $dayStart),
            'monthly' => $this->events->summarizeSince($tenant, $monthStart),
        ]);
    }

    #[Route('/events', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $this->readJson($request);
        $tenantId = trim((string) ($data['tenant_id'] ?? ''));
        if ($tenantId === '') {
            return $this->badRequest('tenant_id is required');
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $event = new AiUsageEvent($tenant);
        $event->setConversation($this->resolveConversation($data['conversation_id'] ?? null, $tenant));
        $event->setConversationMessage($this->resolveConversationMessage($data['conversation_message_id'] ?? null, $tenant));
        $event->setProvider($this->normalizeNullableString($data['provider'] ?? null));
        $event->setModel($this->normalizeNullableString($data['model'] ?? null));
        $event->setResponseId($this->normalizeNullableString($data['response_id'] ?? null));
        $event->setInputTokens(isset($data['input_tokens']) && is_numeric($data['input_tokens']) ? (int) $data['input_tokens'] : null);
        $event->setOutputTokens(isset($data['output_tokens']) && is_numeric($data['output_tokens']) ? (int) $data['output_tokens'] : null);
        $event->setCachedTokens(isset($data['cached_tokens']) && is_numeric($data['cached_tokens']) ? (int) $data['cached_tokens'] : null);
        $event->setTotalTokens(isset($data['total_tokens']) && is_numeric($data['total_tokens']) ? (int) $data['total_tokens'] : null);
        $event->setEstimatedCost(isset($data['estimated_cost']) && is_numeric($data['estimated_cost']) ? (float) $data['estimated_cost'] : null);
        $event->setLatencyMs(isset($data['latency_ms']) && is_numeric($data['latency_ms']) ? (int) $data['latency_ms'] : null);
        $event->setUsageType($this->normalizeUsageType($data['usage_type'] ?? null));

        $this->events->save($event);

        return $this->json([
            'created' => true,
            'event' => $event->toArray(),
        ], JsonResponse::HTTP_CREATED);
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = $this->tenants->find(trim($tenantId));
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            return null;
        }

        return $tenant;
    }

    private function resolveConversation(mixed $conversationId, Tenant $tenant): ?Conversation
    {
        if (!is_string($conversationId) || trim($conversationId) === '') {
            return null;
        }

        $conversation = $this->conversations->find(trim($conversationId));
        if (!$conversation instanceof Conversation) {
            return null;
        }

        if ($conversation->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return null;
        }

        return $conversation;
    }

    private function resolveConversationMessage(mixed $conversationMessageId, Tenant $tenant): ?ConversationMessage
    {
        if (!is_string($conversationMessageId) || trim($conversationMessageId) === '') {
            return null;
        }

        $conversationMessage = $this->conversationMessages->find(trim($conversationMessageId));
        if (!$conversationMessage instanceof ConversationMessage) {
            return null;
        }

        if ($conversationMessage->getConversation()->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return null;
        }

        return $conversationMessage;
    }

    private function normalizeUsageType(mixed $usageType): string
    {
        if (!is_string($usageType) || trim($usageType) === '') {
            return AiUsageEvent::USAGE_TYPE_LLM_CHAT;
        }

        $normalized = strtolower(trim($usageType));
        if (!in_array($normalized, [AiUsageEvent::USAGE_TYPE_LLM_CHAT, AiUsageEvent::USAGE_TYPE_AUDIO_TRANSCRIPTION], true)) {
            return AiUsageEvent::USAGE_TYPE_LLM_CHAT;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function augmentPolicyPayload(Tenant $tenant, TenantAiUsagePolicy $policy, array $payload): array
    {
        $tokenRate = $this->tenantAiUsageTokenRate($policy);
        $commercialPlanContext = $this->tenantCommercialPlanContext($tenant, $policy, $tokenRate);
        $audioPlanContext = $this->tenantAudioPlanContext($tenant);

        $effectiveMonthlyTokens = $commercialPlanContext['effectiveTokens'];
        $payload['commercial_plan_code'] = $commercialPlanContext['commercialPlan']['code'];
        $payload['commercial_plan_name'] = $commercialPlanContext['commercialPlan']['name'];
        $payload['plan_monthly_ai_tokens'] = $commercialPlanContext['baseTokens'];
        $payload['approved_extra_tokens_current_month'] = $commercialPlanContext['extraTokens'];
        $payload['effective_monthly_ai_token_limit'] = $effectiveMonthlyTokens;
        $payload['monthly_limit_source'] = $commercialPlanContext['source'];
        $payload['monthly_cost_limit_eur'] = $effectiveMonthlyTokens !== null ? $this->costAmountFromTokens($effectiveMonthlyTokens, $tokenRate) : $payload['monthly_cost_limit_eur'];
        $payload['audio_transcription_enabled_by_plan'] = $audioPlanContext['enabled'];
        $payload['audio_transcription_plan_message'] = $audioPlanContext['message'];

        return $payload;
    }

    /**
     * @return array{enabled: bool, message: string|null}
     */
    private function tenantAudioPlanContext(Tenant $tenant): array
    {
        $plan = $tenant->getCommercialPlan();
        if (!$plan instanceof CommercialPlan) {
            return [
                'enabled' => false,
                'message' => 'Este negocio no tiene un plan comercial asignado.',
            ];
        }

        $features = $this->planEntitlementResolver instanceof PlanEntitlementResolver
            ? ($this->planEntitlementResolver->resolve($tenant)['features'] ?? [])
            : $plan->getFeatures();

        $enabled = $this->truthyFeatureValue($features['audio_transcription'] ?? false);
        if ($enabled) {
            return [
                'enabled' => true,
                'message' => null,
            ];
        }

        return [
            'enabled' => false,
            'message' => 'Tu plan actual no incluye procesamiento automático de audios. Por favor, escribe el mensaje en texto o contacta con el equipo para ampliar el plan.',
        ];
    }

    /**
     * @return array{
     *     baseTokens: int|null,
     *     extraTokens: int,
     *     effectiveTokens: int|null,
     *     source: 'plan'|'manual_policy'|'none',
     *     commercialPlan: array{code: string, name: string}
     * }
     */
    private function tenantCommercialPlanContext(Tenant $tenant, TenantAiUsagePolicy $policy, float $tokenRate): array
    {
        $plan = $tenant->getCommercialPlan();
        $planTokens = $this->planMonthlyTokens($tenant);
        $manualBaseTokens = $this->tokenAmountFromCost($policy->getMonthlyCostLimitEur(), $tokenRate);
        $extraTokens = $this->topUpRequests instanceof TenantAiTopUpRequestRepository ? $this->topUpRequests->sumApprovedTokensByTenantAndPeriod($tenant, $this->tenantAiCurrentPeriodKey()) : 0;

        $source = 'none';
        $baseTokens = null;
        if ($planTokens !== null) {
            $source = 'plan';
            $baseTokens = $planTokens;
        } elseif ($manualBaseTokens !== null) {
            $source = 'manual_policy';
            $baseTokens = $manualBaseTokens;
        }

        $effectiveTokens = ($baseTokens !== null || $extraTokens > 0) ? (($baseTokens ?? 0) + $extraTokens) : null;

        return [
            'baseTokens' => $baseTokens,
            'extraTokens' => $extraTokens,
            'effectiveTokens' => $effectiveTokens,
            'source' => $source,
            'commercialPlan' => [
                'code' => $plan instanceof \App\Entity\CommercialPlan ? $plan->getCode() : '',
                'name' => $plan instanceof \App\Entity\CommercialPlan ? $plan->getName() : 'Sin plan comercial asignado',
            ],
        ];
    }

    private function planMonthlyTokens(Tenant $tenant): ?int
    {
        $plan = $tenant->getCommercialPlan();
        if (!$plan instanceof \App\Entity\CommercialPlan) {
            return null;
        }

        if ($this->planEntitlementResolver instanceof PlanEntitlementResolver) {
            $resolved = $this->planEntitlementResolver->resolve($tenant);
            $limits = $resolved['limits'] ?? [];
            return $this->extractCommercialLimitTokens($limits['included_monthly_ai_tokens'] ?? null);
        }

        return $this->extractCommercialLimitTokens($plan->getLimits()['included_monthly_ai_tokens'] ?? null);
    }

    private function tenantAiUsageTokenRate(?TenantAiUsagePolicy $policy): float
    {
        return $this->tenantAiUsageModelAverageCostPerToken($policy?->getDefaultModel() ?? $policy?->getFallbackModel());
    }

    private function tenantAiUsageModelAverageCostPerToken(?string $model): float
    {
        $normalized = strtolower(trim((string) $model));
        if ($normalized === '') {
            return 0.000001;
        }

        if ($this->aiModelCosts instanceof AiModelCostReferenceRepository) {
            $reference = $this->aiModelCosts->findOneByUsageTypeAndModel(\App\Entity\AiModelCostReference::USAGE_TYPE_LLM_CHAT, $normalized);
            if ($reference instanceof \App\Entity\AiModelCostReference && $reference->isActive()) {
                return (($reference->getInputCostPerMillion() ?? 0.0) + ($reference->getOutputCostPerMillion() ?? 0.0) + ($reference->getCachedInputCostPerMillion() ?? 0.0)) / 3 / 1_000_000;
            }
        }

        $pricingTable = [
            'gpt-4.1' => ['input' => 2.0, 'output' => 8.0, 'cached_input' => 0.5],
            'gpt-4.1-mini' => ['input' => 0.4, 'output' => 1.6, 'cached_input' => 0.1],
            'gpt-4o' => ['input' => 2.5, 'output' => 10.0, 'cached_input' => 0.625],
            'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.6, 'cached_input' => 0.0375],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.5, 'cached_input' => 0.075],
        ];

        if (isset($pricingTable[$normalized])) {
            $pricing = $pricingTable[$normalized];
            return (($pricing['input'] + $pricing['output'] + $pricing['cached_input']) / 3) / 1_000_000;
        }

        foreach ($pricingTable as $key => $pricing) {
            if (str_starts_with($normalized, $key)) {
                return (($pricing['input'] + $pricing['output'] + $pricing['cached_input']) / 3) / 1_000_000;
            }
        }

        return 0.000001;
    }

    private function tokenAmountFromCost(?float $cost, ?float $costPerToken): ?int
    {
        if ($cost === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (int) round($cost);
        }

        return (int) round($cost / $costPerToken);
    }

    private function costAmountFromTokens(?int $tokens, ?float $costPerToken): ?float
    {
        if ($tokens === null) {
            return null;
        }

        if ($costPerToken === null || $costPerToken <= 0.0) {
            return (float) $tokens;
        }

        return round($tokens * $costPerToken, 8);
    }

    private function extractCommercialLimitTokens(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $tokens = (int) round((float) $value);

        return $tokens > 0 ? $tokens : null;
    }

    private function truthyFeatureValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return !in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', 'none', 'null'], true);
    }

    private function tenantAiCurrentPeriodKey(?\DateTimeImmutable $date = null): string
    {
        $date ??= new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid'));

        return $date->format('Y-m');
    }
}
