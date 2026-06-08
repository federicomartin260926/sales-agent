<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalConversationSummaryController;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalConversationSummaryControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    private function createTenant(): Tenant
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');
        $tenant->setActive(true);

        return $tenant;
    }

    private function createConversation(Tenant $tenant): Conversation
    {
        return new Conversation($tenant, '+34999999999');
    }

    private function controller(
        ConversationRepository $conversations,
        ConversationMessageRepository $conversationMessages,
    ): InternalConversationSummaryController {
        $controller = new InternalConversationSummaryController(
            $conversations,
            $conversationMessages,
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testSummaryContextReturnsSafeMessages(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->createConversation($tenant);
        $conversation->setSummary('Resumen previo');
        $conversation->setStatus('pending_human');

        $firstMessage = new ConversationMessage($conversation, 'inbound', 'Hola, quiero reservar');
        $firstMessage->setRole('user');
        $firstMessage->setMessageType('text');
        $firstMessage->setProvider('whatsapp');
        $firstMessage->setModel('n/a');
        $firstMessage->setIntent('agenda');
        $firstMessage->setAction('offer_booking');
        $firstMessage->setNeedsHuman(false);
        $firstMessage->setRawPayload([
            'authorization' => 'Bearer secret-token',
            'downstream_authorization' => 'downstream-secret-token',
            'secret' => 'nope',
        ]);
        $firstMessage->setMetadata([
            'token' => 'nope',
            'authorization' => 'Bearer secret-token',
        ]);

        $secondMessage = new ConversationMessage($conversation, 'outbound', 'Te paso con una persona');
        $secondMessage->setRole('assistant');
        $secondMessage->setMessageType('text');
        $secondMessage->setIntent('handoff');
        $secondMessage->setAction('handoff_to_human');
        $secondMessage->setNeedsHuman(true);

        $conversations = new class($conversation) extends ConversationRepository {
            public function __construct(private readonly Conversation $conversation)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->conversation;
            }
        };

        $conversationMessages = new class([$firstMessage, $secondMessage]) extends ConversationMessageRepository {
            /**
             * @param list<ConversationMessage> $messages
             */
            public function __construct(private readonly array $messages)
            {
            }

            public function findRecentByConversation(Conversation $conversation, int $limit = 20): array
            {
                return $this->messages;
            }
        };

        $controller = $this->controller($conversations, $conversationMessages);

        $response = $controller->summaryContext(
            $conversation->getId()->toRfc4122(),
            Request::create('/api/internal/conversations/'.$conversation->getId()->toRfc4122().'/summary-context', 'GET', [
                'limit' => 2,
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('Resumen previo', $payload['conversation']['summary']);
        self::assertCount(2, $payload['messages']);
        self::assertSame('inbound', $payload['messages'][0]['direction']);
        self::assertSame('user', $payload['messages'][0]['role']);
        self::assertSame('Hola, quiero reservar', $payload['messages'][0]['body']);
        self::assertIsString($payload['messages'][0]['created_at']);
        self::assertArrayNotHasKey('rawPayload', $payload['messages'][0]);
        self::assertArrayNotHasKey('metadata', $payload['messages'][0]);
        self::assertStringNotContainsString('Bearer secret-token', (string) $response->getContent());
        self::assertStringNotContainsString('downstream-secret-token', (string) $response->getContent());
    }

    public function testUpdateSummaryPersistsSummary(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->createConversation($tenant);

        $conversations = new class($conversation) extends ConversationRepository {
            public ?Conversation $savedConversation = null;

            public function __construct(private readonly Conversation $conversation)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->conversation;
            }

            public function save(Conversation $conversation, bool $flush = true): void
            {
                $this->savedConversation = $conversation;
            }
        };

        $conversationMessages = new class extends ConversationMessageRepository {
            public function __construct()
            {
            }

            public function findRecentByConversation(Conversation $conversation, int $limit = 20): array
            {
                return [];
            }
        };

        $controller = $this->controller($conversations, $conversationMessages);

        $response = $controller->updateSummary(
            $conversation->getId()->toRfc4122(),
            Request::create(
                '/api/internal/conversations/'.$conversation->getId()->toRfc4122().'/summary',
                'POST',
                [],
                [],
                [],
                [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
                ],
                json_encode([
                    'summary' => 'Cliente interesado en depilación láser de cuerpo entero. Pidió cita para mañana a las 9:00 con María.',
                ])
            )
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('Cliente interesado en depilación láser de cuerpo entero. Pidió cita para mañana a las 9:00 con María.', $payload['conversation']['summary']);
        self::assertNotNull($conversations->savedConversation);
        self::assertSame($payload['conversation']['summary'], $conversations->savedConversation?->getSummary());
    }
}
