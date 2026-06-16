<?php

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Psr\Log\LoggerInterface;

#[Route('/api/internal/conversations')]
final class InternalConversationSummaryController extends AbstractApiController
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ConversationMessageRepository $conversationMessages,
        private readonly TenantRepository $tenants,
        private readonly InternalBearerTokenValidator $validator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/{conversationId}/summary-context', methods: ['GET'])]
    public function summaryContext(string $conversationId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $conversation = $this->resolveConversation($conversationId, $request);
        if (!$conversation instanceof Conversation) {
            return $this->notFound('Conversation not found');
        }

        $limit = $this->normalizeLimit($request->query->get('limit'));
        $messages = $this->conversationMessages->findRecentByConversation($conversation, $limit);

        return $this->json([
            'conversation' => $conversation->toArray(),
            'messages' => array_map(
                fn (ConversationMessage $message): array => [
                    'id' => $message->getId()->toRfc4122(),
                    'conversation_id' => $message->getConversation()->getId()->toRfc4122(),
                    'direction' => $message->getDirection(),
                    'role' => $message->getRole(),
                    'message_type' => $message->getMessageType(),
                    'body' => $message->getBody(),
                    'provider' => $message->getProvider(),
                    'model' => $message->getModel(),
                    'latency_ms' => $message->getLatencyMs(),
                    'intent' => $message->getIntent(),
                    'score' => $message->getScore(),
                    'action' => $message->getAction(),
                    'needs_human' => $message->isNeedsHuman(),
                    'raw_payload' => $this->normalizeRawPayload($message->getRawPayload()),
                    'metadata' => $this->normalizeMetadata($message->getMetadata()),
                    'created_at' => $message->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
                $messages,
            ),
            'limit' => $limit,
        ]);
    }

    #[Route('/{conversationId}/summary', methods: ['POST', 'PATCH'])]
    public function updateSummary(string $conversationId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $conversation = $this->resolveConversation($conversationId, $request);
        if (!$conversation instanceof Conversation) {
            return $this->notFound('Conversation not found');
        }

        $data = $this->readJson($request);
        $summary = $this->normalizeNullableString($data['summary'] ?? null);
        $conversation->setSummary($summary);
        $this->conversations->save($conversation);

        return $this->json([
            'updated' => true,
            'conversation' => $conversation->toArray(),
        ]);
    }

    private function resolveConversation(string $conversationId, Request $request): ?Conversation
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return $this->resolveConversationByExternalId($request);
        }

        $conversation = $this->conversations->find($conversationId);
        if (!$conversation instanceof Conversation) {
            return $this->resolveConversationByExternalId($request);
        }

        return $conversation;
    }

    private function resolveConversationByExternalId(Request $request): ?Conversation
    {
        $tenantId = $this->normalizeNullableString($request->query->get('tenant_id'));
        $externalConversationId = $this->normalizeNullableString($request->query->get('external_conversation_id'));
        if ($tenantId === null || $externalConversationId === null) {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant) {
            return null;
        }

        $customerPhone = $this->normalizeNullableString($request->query->get('customer_phone'));
        $candidates = $this->conversations->findByTenantAndExternalConversationId($tenant, $externalConversationId, $customerPhone, 2);

        if (count($candidates) !== 1) {
            if (count($candidates) > 1) {
                $this->logger->warning('Conversation summary context resolution ambiguous for tenant/external conversation id', [
                    'tenant_id' => $tenantId,
                    'external_conversation_id' => $externalConversationId,
                    'customer_phone_present' => $customerPhone !== null,
                    'candidate_count' => count($candidates),
                ]);
            }

            return null;
        }

        return $candidates[0];
    }

    private function normalizeLimit(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 20;
        }

        $limit = (int) $value;

        return max(1, min(20, $limit));
    }

    /**
     * @param array<string, mixed>|null $metadata
     *
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if (!is_array($metadata) || $metadata === []) {
            return null;
        }

        $safeKeys = [
            'mcp_tool_traces',
            'mcp_response_id',
            'mcp_errors',
            'mcp_enabled',
            'mcp_server_label',
            'mcp_server_url',
            'mcp_allowed_tools',
            'mcp_require_approval',
            'openai_previous_response_id_invalid',
        ];

        $safeMetadata = [];
        foreach ($safeKeys as $key) {
            if (array_key_exists($key, $metadata)) {
                $safeMetadata[$key] = $metadata[$key];
            }
        }

        return $safeMetadata === [] ? null : $safeMetadata;
    }

    /**
     * @param array<string, mixed>|null $rawPayload
     *
     * @return array<string, mixed>|null
     */
    private function normalizeRawPayload(?array $rawPayload): ?array
    {
        if (!is_array($rawPayload) || $rawPayload === []) {
            return null;
        }

        return $this->sanitizeArray($rawPayload);
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $value): array
    {
        $sensitiveKeys = ['authorization', 'downstream_authorization', 'bearer', 'bearer_token', 'token', 'secret'];
        $sanitized = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $sensitiveKeys, true)) {
                continue;
            }

            if (is_array($item)) {
                $sanitized[$key] = $this->sanitizeArray($item);
                continue;
            }

            $sanitized[$key] = $item;
        }

        return $sanitized;
    }
}
