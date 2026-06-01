<?php

namespace App\Controller\Api;

use App\Entity\AiUsageEvent;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Entity\TenantAiUsagePolicy;
use App\Repository\AiUsageEventRepository;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
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
            $payload['created_at'] = null;
            $payload['updated_at'] = null;
            $payload['exists'] = false;

            return $this->json($payload);
        }

        $payload = $policy->toArray();
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
}
