<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalConversationSummaryController;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

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
        ?TenantRepository $tenants = null,
    ): InternalConversationSummaryController {
        $controller = new InternalConversationSummaryController(
            $conversations,
            $conversationMessages,
            $tenants ?? $this->createStub(TenantRepository::class),
            new InternalBearerTokenValidator(self::TOKEN),
            $this->createStub(LoggerInterface::class),
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
        $secondMessage->setMetadata([
            'mcp_tool_traces' => [
                [
                    'tool_name' => 'appointment_availability',
                    'output' => [
                        'available' => true,
                        'slots' => [
                            [
                                'start' => '2026-06-11T17:35:00+02:00',
                                'owner_id' => 'owner-uuid',
                                'owner_ref' => 'owner-ref-1',
                            ],
                        ],
                    ],
                ],
            ],
            'authorization' => 'Bearer secret-token',
        ]);

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
        self::assertNull($payload['messages'][0]['metadata']);
        self::assertSame('appointment_availability', $payload['messages'][1]['metadata']['mcp_tool_traces'][0]['tool_name']);
        self::assertSame('owner-uuid', $payload['messages'][1]['metadata']['mcp_tool_traces'][0]['output']['slots'][0]['owner_id']);
        self::assertArrayNotHasKey('authorization', $payload['messages'][1]['metadata']);
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

    public function testSummaryContextResolvesExternalConversationByTenantWithoutCrossingTenants(): void
    {
        $tenantOne = $this->createTenant();
        $tenantTwo = new Tenant('Negocio Alternativo', 'negocio-alternativo');
        $tenantTwo->setActive(true);

        $conversationOne = $this->createConversation($tenantOne);
        $conversationTwo = $this->createConversation($tenantTwo);
        $conversationOne->setExternalConversationId('shared-external-id');
        $conversationTwo->setExternalConversationId('shared-external-id');

        $conversations = new class($tenantOne, $tenantTwo, $conversationOne, $conversationTwo) extends ConversationRepository {
            public array $externalLookupCalls = [];

            public function __construct(
                private readonly Tenant $tenantOne,
                private readonly Tenant $tenantTwo,
                private readonly Conversation $conversationOne,
                private readonly Conversation $conversationTwo,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return null;
            }

            /**
             * @return list<Conversation>
             */
            public function findByTenantAndExternalConversationId(Tenant $tenant, string $externalConversationId, ?string $customerPhone = null, int $limit = 2): array
            {
                $this->externalLookupCalls[] = [$tenant->getName(), $externalConversationId, $customerPhone];

                if ($tenant === $this->tenantOne) {
                    return [$this->conversationOne];
                }

                if ($tenant === $this->tenantTwo) {
                    return [$this->conversationTwo];
                }

                return [];
            }
        };

        $tenants = new class($tenantOne, $tenantTwo) extends TenantRepository {
            public function __construct(
                private readonly Tenant $tenantOne,
                private readonly Tenant $tenantTwo,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                if ((string) $id === (string) $this->tenantOne->getId()) {
                    return $this->tenantOne;
                }

                if ((string) $id === (string) $this->tenantTwo->getId()) {
                    return $this->tenantTwo;
                }

                return null;
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

        $controller = $this->controller($conversations, $conversationMessages, $tenants);

        $responseTenantOne = $controller->summaryContext(
            'external-shared-id',
            Request::create('/api/internal/conversations/external-shared-id/summary-context', 'GET', [
                'limit' => 2,
                'tenant_id' => (string) $tenantOne->getId(),
                'external_conversation_id' => 'shared-external-id',
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $responseTenantOne->getStatusCode());
        $payloadTenantOne = json_decode((string) $responseTenantOne->getContent(), true);
        self::assertSame($conversationOne->getId()->toRfc4122(), $payloadTenantOne['conversation']['id']);

        $responseTenantTwo = $controller->summaryContext(
            'external-shared-id',
            Request::create('/api/internal/conversations/external-shared-id/summary-context', 'GET', [
                'limit' => 2,
                'tenant_id' => (string) $tenantTwo->getId(),
                'external_conversation_id' => 'shared-external-id',
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $responseTenantTwo->getStatusCode());
        $payloadTenantTwo = json_decode((string) $responseTenantTwo->getContent(), true);
        self::assertSame($conversationTwo->getId()->toRfc4122(), $payloadTenantTwo['conversation']['id']);
    }

    public function testSummaryContextByExternalIdEndpointResolvesWithoutUuidPath(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->createConversation($tenant);
        $conversation->setExternalConversationId('shared-external-id');

        $conversations = new class($tenant, $conversation) extends ConversationRepository {
            public function __construct(
                private readonly Tenant $tenant,
                private readonly Conversation $conversation,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return null;
            }

            /**
             * @return list<Conversation>
             */
            public function findByTenantAndExternalConversationId(Tenant $tenant, string $externalConversationId, ?string $customerPhone = null, int $limit = 2): array
            {
                if ($tenant === $this->tenant && $externalConversationId === 'shared-external-id') {
                    return [$this->conversation];
                }

                return [];
            }
        };

        $tenants = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                if ((string) $id === (string) $this->tenant->getId()) {
                    return $this->tenant;
                }

                return null;
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

        $controller = $this->controller($conversations, $conversationMessages, $tenants);

        $response = $controller->summaryContextByExternalId(
            Request::create('/api/internal/conversations/summary-context', 'GET', [
                'limit' => 2,
                'tenant_id' => (string) $tenant->getId(),
                'external_conversation_id' => 'shared-external-id',
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame($conversation->getId()->toRfc4122(), $payload['conversation']['id']);
        self::assertSame('shared-external-id', $payload['conversation']['externalConversationId']);
    }

    public function testSummaryContextFallsBackToExternalIdWithoutInternalConversationId(): void
    {
        $tenant = $this->createTenant();
        $conversation = $this->createConversation($tenant);
        $conversation->setExternalConversationId('shared-external-id');

        $conversations = new class($tenant, $conversation) extends ConversationRepository {
            public function __construct(
                private readonly Tenant $tenant,
                private readonly Conversation $conversation,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return null;
            }

            /**
             * @return list<Conversation>
             */
            public function findByTenantAndExternalConversationId(Tenant $tenant, string $externalConversationId, ?string $customerPhone = null, int $limit = 2): array
            {
                if ($tenant === $this->tenant && $externalConversationId === 'shared-external-id') {
                    return [$this->conversation];
                }

                return [];
            }
        };

        $tenants = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                if ((string) $id === (string) $this->tenant->getId()) {
                    return $this->tenant;
                }

                return null;
            }
        };

        $message = new ConversationMessage($conversation, 'outbound', 'Para las 16:00 tengo una opción disponible');
        $message->setRole('assistant');
        $message->setMessageType('text');
        $message->setRawPayload([
            'data_to_save' => [
                'new_llm_orchestration_offered_slots' => [
                    [
                        'start' => '2026-06-16T16:00:00+01:00',
                        'owner' => [
                            'id' => 'owner-uuid',
                            'name' => 'Claudia Estética',
                        ],
                    ],
                ],
            ],
        ]);

        $conversationMessages = new class($message) extends ConversationMessageRepository {
            public function __construct(private readonly ConversationMessage $message)
            {
            }

            public function findRecentByConversation(Conversation $conversation, int $limit = 20): array
            {
                return [$this->message];
            }
        };

        $controller = $this->controller($conversations, $conversationMessages, $tenants);

        $response = $controller->summaryContext(
            'missing-internal-id',
            Request::create('/api/internal/conversations/missing-internal-id/summary-context', 'GET', [
                'limit' => 2,
                'tenant_id' => (string) $tenant->getId(),
                'external_conversation_id' => 'shared-external-id',
                'customer_phone' => '+34999999999',
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame($conversation->getId()->toRfc4122(), $payload['conversation']['id']);
    }

    public function testSummaryContextReturnsNotFoundWhenExternalLookupIsAmbiguous(): void
    {
        $tenant = $this->createTenant();
        $conversationOne = $this->createConversation($tenant);
        $conversationTwo = $this->createConversation($tenant);
        $conversationOne->setExternalConversationId('shared-external-id');
        $conversationTwo->setExternalConversationId('shared-external-id');

        $conversations = new class($tenant, $conversationOne, $conversationTwo) extends ConversationRepository {
            public function __construct(
                private readonly Tenant $tenant,
                private readonly Conversation $conversationOne,
                private readonly Conversation $conversationTwo,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return null;
            }

            /**
             * @return list<Conversation>
             */
            public function findByTenantAndExternalConversationId(Tenant $tenant, string $externalConversationId, ?string $customerPhone = null, int $limit = 2): array
            {
                if ($tenant === $this->tenant && $externalConversationId === 'shared-external-id') {
                    return [$this->conversationOne, $this->conversationTwo];
                }

                return [];
            }
        };

        $tenants = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                if ((string) $id === (string) $this->tenant->getId()) {
                    return $this->tenant;
                }

                return null;
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

        $controller = $this->controller($conversations, $conversationMessages, $tenants);

        $response = $controller->summaryContext(
            'missing-internal-id',
            Request::create('/api/internal/conversations/missing-internal-id/summary-context', 'GET', [
                'limit' => 2,
                'tenant_id' => (string) $tenant->getId(),
                'external_conversation_id' => 'shared-external-id',
            ], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
