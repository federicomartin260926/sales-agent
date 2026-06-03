<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalAiUsageController;
use App\Entity\AiUsageEvent;
use App\Entity\CommercialPlan;
use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use App\Repository\AiUsageEventRepository;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalAiUsageControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    public function testPolicyDefaultsExposeAudioLimitSettingsWhenPolicyIsMissing(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);

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
            new class extends TenantAiUsagePolicyRepository {
                public function __construct()
                {
                }

                public function findOneByTenant(Tenant $tenant): ?\App\Entity\TenantAiUsagePolicy
                {
                    return null;
                }
            },
            $this->createStub(AiUsageEventRepository::class),
            $this->createStub(ConversationRepository::class),
            $this->createStub(ConversationMessageRepository::class),
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        $response = $controller->policy(
            $tenant->getId()->toRfc4122(),
            Request::create('/api/internal/ai-usage/'.$tenant->getId()->toRfc4122().'/policy', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(60, $payload['max_audio_transcription_seconds']);
        self::assertSame(
            'El audio es demasiado largo para procesarlo automáticamente. Por favor, envíame un audio más corto o escríbeme el mensaje por texto.',
            $payload['audio_limit_exceeded_message']
        );
    }

    public function testPolicyPayloadIncludesCommercialPlanAndCurrentMonthTopUps(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);
        $plan = new CommercialPlan('starter', 'Starter');
        $plan->setLimits(['included_monthly_ai_tokens' => 1000000]);
        $tenant->setCommercialPlan($plan);

        $policy = new \App\Entity\TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled(true);
        $policy->setDefaultModel('gpt-4.1-mini');
        $policy->setFallbackModel('gpt-4.1-mini');

        $currentPeriodKey = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m');
        $topUpRequest = new TenantAiTopUpRequest($tenant, 25.0, 'Ampliación');
        $topUpRequest->approve(new \App\Entity\User('owner@example.com', ['super_admin'], 'Owner'), 2000000, $currentPeriodKey);

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
            new class($policy) extends TenantAiUsagePolicyRepository {
                public function __construct(private readonly \App\Entity\TenantAiUsagePolicy $policy)
                {
                }

                public function findOneByTenant(Tenant $tenant): ?\App\Entity\TenantAiUsagePolicy
                {
                    return $this->policy;
                }
            },
            $this->createStub(AiUsageEventRepository::class),
            $this->createStub(ConversationRepository::class),
            $this->createStub(ConversationMessageRepository::class),
            new InternalBearerTokenValidator(self::TOKEN),
            new class($tenant, $topUpRequest) extends TenantAiTopUpRequestRepository {
                public function __construct(private readonly Tenant $tenant, private readonly TenantAiTopUpRequest $topUpRequest)
                {
                }

                public function sumApprovedTokensByTenantAndPeriod(Tenant $tenant, string $periodKey): int
                {
                    return $this->topUpRequest->getApprovedPeriodKey() === $periodKey ? $this->topUpRequest->getApprovedTokens() ?? 0 : 0;
                }
            }
        );
        $controller->setContainer(new Container());

        $response = $controller->policy(
            $tenant->getId()->toRfc4122(),
            Request::create('/api/internal/ai-usage/'.$tenant->getId()->toRfc4122().'/policy', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('starter', $payload['commercial_plan_code']);
        self::assertSame('Starter', $payload['commercial_plan_name']);
        self::assertSame(1000000, $payload['plan_monthly_ai_tokens']);
        self::assertSame(2000000, $payload['approved_extra_tokens_current_month']);
        self::assertSame(3000000, $payload['effective_monthly_ai_token_limit']);
        self::assertSame('plan', $payload['monthly_limit_source']);
        self::assertGreaterThan(2.0, $payload['monthly_cost_limit_eur']);
        self::assertLessThan(2.2, $payload['monthly_cost_limit_eur']);
        self::assertSame(false, $payload['audio_transcription_enabled_by_plan']);
        self::assertSame(
            'Tu plan actual no incluye procesamiento automático de audios. Por favor, escribe el mensaje en texto o contacta con el equipo para ampliar el plan.',
            $payload['audio_transcription_plan_message']
        );
        self::assertTrue($payload['exists']);
    }

    public function testPolicyPayloadEnablesAudioTranscriptionWhenPlanIncludesIt(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);
        $plan = new CommercialPlan('growth', 'Growth');
        $plan->setFeatures(['audio_transcription' => true]);
        $tenant->setCommercialPlan($plan);

        $policy = new \App\Entity\TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled(true);
        $policy->setDefaultModel('gpt-4.1-mini');
        $policy->setFallbackModel('gpt-4.1-mini');

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
            new class($policy) extends TenantAiUsagePolicyRepository {
                public function __construct(private readonly \App\Entity\TenantAiUsagePolicy $policy)
                {
                }

                public function findOneByTenant(Tenant $tenant): ?\App\Entity\TenantAiUsagePolicy
                {
                    return $this->policy;
                }
            },
            $this->createStub(AiUsageEventRepository::class),
            $this->createStub(ConversationRepository::class),
            $this->createStub(ConversationMessageRepository::class),
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        $response = $controller->policy(
            $tenant->getId()->toRfc4122(),
            Request::create('/api/internal/ai-usage/'.$tenant->getId()->toRfc4122().'/policy', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame(true, $payload['audio_transcription_enabled_by_plan']);
        self::assertSame(null, $payload['audio_transcription_plan_message']);
    }

    public function testPolicyPayloadFallsBackToManualMonthlyLimitWhenNoPlanIsAssigned(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);

        $policy = new \App\Entity\TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled(true);
        $policy->setDefaultModel('gpt-4.1-mini');
        $policy->setFallbackModel('gpt-4.1-mini');
        $policy->setMonthlyCostLimitEur(0.0);

        $currentPeriodKey = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m');
        $topUpRequest = new TenantAiTopUpRequest($tenant, 25.0, 'Ampliación');
        $topUpRequest->approve(new \App\Entity\User('owner@example.com', ['super_admin'], 'Owner'), 2000000, $currentPeriodKey);

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
            new class($policy) extends TenantAiUsagePolicyRepository {
                public function __construct(private readonly \App\Entity\TenantAiUsagePolicy $policy)
                {
                }

                public function findOneByTenant(Tenant $tenant): ?\App\Entity\TenantAiUsagePolicy
                {
                    return $this->policy;
                }
            },
            $this->createStub(AiUsageEventRepository::class),
            $this->createStub(ConversationRepository::class),
            $this->createStub(ConversationMessageRepository::class),
            new InternalBearerTokenValidator(self::TOKEN),
            new class($tenant, $topUpRequest) extends TenantAiTopUpRequestRepository {
                public function __construct(private readonly Tenant $tenant, private readonly TenantAiTopUpRequest $topUpRequest)
                {
                }

                public function sumApprovedTokensByTenantAndPeriod(Tenant $tenant, string $periodKey): int
                {
                    return $this->topUpRequest->getApprovedPeriodKey() === $periodKey ? $this->topUpRequest->getApprovedTokens() ?? 0 : 0;
                }
            }
        );
        $controller->setContainer(new Container());

        $response = $controller->policy(
            $tenant->getId()->toRfc4122(),
            Request::create('/api/internal/ai-usage/'.$tenant->getId()->toRfc4122().'/policy', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('', $payload['commercial_plan_code']);
        self::assertSame('Sin plan comercial asignado', $payload['commercial_plan_name']);
        self::assertSame(0, $payload['plan_monthly_ai_tokens']);
        self::assertSame(2000000, $payload['approved_extra_tokens_current_month']);
        self::assertSame(2000000, $payload['effective_monthly_ai_token_limit']);
        self::assertSame('manual_policy', $payload['monthly_limit_source']);
        self::assertGreaterThan(1.3, $payload['monthly_cost_limit_eur']);
        self::assertLessThan(1.5, $payload['monthly_cost_limit_eur']);
    }

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
                'usage_type' => 'audio_transcription',
            ])
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $eventsRepository->savedEvents);
        self::assertSame('openai', $eventsRepository->savedEvents[0]->getProvider());
        self::assertSame('gpt-4.1-mini', $eventsRepository->savedEvents[0]->getModel());
        self::assertSame(0.000123, $eventsRepository->savedEvents[0]->getEstimatedCost());
        self::assertSame('audio_transcription', $eventsRepository->savedEvents[0]->getUsageType());
    }
}
