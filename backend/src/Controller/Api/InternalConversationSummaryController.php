<?php

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Security\InternalBearerTokenValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/conversations')]
final class InternalConversationSummaryController extends AbstractApiController
{
    public function __construct(
        private readonly ConversationRepository $conversations,
        private readonly ConversationMessageRepository $conversationMessages,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    #[Route('/{conversationId}/summary-context', methods: ['GET'])]
    public function summaryContext(string $conversationId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $conversation = $this->resolveConversation($conversationId);
        if (!$conversation instanceof Conversation) {
            return $this->notFound('Conversation not found');
        }

        $limit = $this->normalizeLimit($request->query->get('limit'));
        $messages = $this->conversationMessages->findRecentByConversation($conversation, $limit);

        return $this->json([
            'conversation' => $conversation->toArray(),
            'messages' => array_map(
                static fn (ConversationMessage $message): array => [
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

        $conversation = $this->resolveConversation($conversationId);
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

    private function resolveConversation(string $conversationId): ?Conversation
    {
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return null;
        }

        $conversation = $this->conversations->find($conversationId);
        if (!$conversation instanceof Conversation) {
            return null;
        }

        return $conversation;
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
}
