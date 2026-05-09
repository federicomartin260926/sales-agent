<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalAiUsageController;
use App\Entity\AiUsageEvent;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Repository\AiUsageEventRepository;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalAiUsageControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    public function testCreateEventPersistsUsageEvent(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);

        $conversation = new Conversation($tenant, '+34999999999');
        $message = new ConversationMessage($conversation, 'outbound', 'Respuesta');

        $eventsRepository = new class extends AiUsageEventRepository {
            public array $savedEvents = [];

            public function __construct()
            {
            }

            public function save(AiUsageEvent $event, bool $flush = true): void
            {
                $this->savedEvents[] = $event;
            }
        };

        $controller = new InternalAiUsageController(
            new class($tenant) extends TenantRepository {
                public function __construct(private readonly Tenant $tenant)
                {
                }

                public function find($id, $lockMode = null, $lockVersion = null): ?object
                {
                    return $this->tenant;
                }
            },
            $this->createStub(TenantAiUsagePolicyRepository::class),
            $eventsRepository,
            new class($conversation) extends ConversationRepository {
                public function __construct(private readonly Conversation $conversation)
                {
                }

                public function find($id, $lockMode = null, $lockVersion = null): ?object
                {
                    return $this->conversation;
                }
            },
            new class($message) extends ConversationMessageRepository {
                public function __construct(private readonly ConversationMessage $message)
                {
                }

                public function find($id, $lockMode = null, $lockVersion = null): ?object
                {
                    return $this->message;
                }
            },
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        $response = $controller->createEvent(Request::create(
            '/api/internal/ai-usage/events',
            'POST',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ],
            json_encode([
                'tenant_id' => $tenant->getId()->toRfc4122(),
                'conversation_id' => $conversation->getId()->toRfc4122(),
                'conversation_message_id' => $message->getId()->toRfc4122(),
                'provider' => 'openai',
                'model' => 'gpt-4.1-mini',
                'response_id' => 'resp_1',
                'input_tokens' => 120,
                'output_tokens' => 32,
                'cached_tokens' => 40,
                'total_tokens' => 152,
                'estimated_cost' => 0.000123,
                'latency_ms' => 200,
            ])
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $eventsRepository->savedEvents);
        self::assertSame('openai', $eventsRepository->savedEvents[0]->getProvider());
        self::assertSame('gpt-4.1-mini', $eventsRepository->savedEvents[0]->getModel());
        self::assertSame(0.000123, $eventsRepository->savedEvents[0]->getEstimatedCost());
    }
}
