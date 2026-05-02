<?php

namespace App\Tests\Functional;

use App\Controller\Api\InternalRuntimeSettingsController;
use App\Controller\Api\RoutingController;
use App\Entity\Conversation;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Kernel;
use App\Repository\ConversationRepository;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use App\Service\ConversationService;
use App\Service\EntryPointUtmFactory;
use App\Service\RoutingResolver;
use App\Service\RuntimeConfigurationService;
use App\Service\WhatsAppRedirectUrlBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class InternalApiSecurityTest extends WebTestCase
{
    private const TOKEN = 'test-internal-token';

    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel('test', true);
    }

    protected function setUp(): void
    {
        parent::setUp();
        static::ensureKernelShutdown();
        $this->configureInternalToken(self::TOKEN);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function internalPathsProvider(): iterable
    {
        yield 'runtime settings' => ['/api/internal/runtime-settings', 'GET'];
        yield 'entry point ref' => ['/api/internal/routing/entrypoint-ref/abc123', 'GET'];
        yield 'whatsapp phone' => ['/api/internal/routing/whatsapp-phone/phone-number-id-1', 'GET'];
        yield 'conversation upsert' => ['/api/internal/conversations/upsert', 'POST'];
    }

    #[DataProvider('internalPathsProvider')]
    public function testInternalRoutesRejectRequestsWithoutBearerToken(string $path, string $method): void
    {
        $client = static::createClient();

        $client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringNotContainsString('Invalid JWT Token', (string) $client->getResponse()->getContent());
    }

    #[DataProvider('internalPathsProvider')]
    public function testInternalRoutesRejectRequestsWithWrongBearerToken(string $path, string $method): void
    {
        $client = static::createClient();

        $client->request($method, $path, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertStringNotContainsString('Invalid JWT Token', (string) $client->getResponse()->getContent());
    }

    public function testRuntimeSettingsAcceptsSalesAgentBearerToken(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $container->set(
            RuntimeConfigurationService::class,
            new class extends RuntimeConfigurationService {
                public function __construct()
                {
                }

                public function snapshot(): array
                {
                    return [
                        'values' => [
                            'llm_default_profile' => 'auto',
                        ],
                    ];
                }
            }
        );
        $container->set(
            InternalRuntimeSettingsController::class,
            $this->createRuntimeSettingsController([
                'values' => ['llm_default_profile' => 'auto'],
            ])
        );

        $client->request('GET', '/api/internal/runtime-settings', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('application/json', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testResolveEntryPointRefAcceptsSalesAgentBearerToken(): void
    {
        $client = static::createClient();
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $entryPoint = $this->createEntryPoint($tenant, $product);
        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $entryPointUtm->setUtmSource('google');
        $entryPointUtm->setUtmMedium('cpc');
        $entryPointUtm->setUtmCampaign('demo');

        $container = static::getContainer();
        $container->set(RoutingController::class, $this->createRoutingController(
            entryPoint: $entryPoint,
            entryPointUtm: $entryPointUtm,
            tenant: $entryPoint->getTenant(),
            product: $entryPoint->getProduct(),
        ));

        $client->request('GET', '/api/internal/routing/entrypoint-ref/abc123', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('abc123', $data['ref']);
    }

    public function testResolveWhatsappPhoneAcceptsSalesAgentBearerToken(): void
    {
        $client = static::createClient();
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $entryPoint = $this->createEntryPoint($tenant, $product);

        $container = static::getContainer();
        $container->set(RoutingController::class, $this->createRoutingController(
            tenant: $tenant,
            product: $product,
            entryPoint: $entryPoint,
            entryPointUtm: $this->createEntryPointUtm($entryPoint),
            resolveTenantByPhone: $tenant,
        ));

        $client->request('GET', '/api/internal/routing/whatsapp-phone/phone-number-id-1', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame($tenant->getSlug(), $data['tenant_slug']);
    }

    public function testUpsertConversationAcceptsSalesAgentBearerToken(): void
    {
        $client = static::createClient();
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $entryPoint = $this->createEntryPoint($tenant, $product);
        $entryPointUtm = $this->createEntryPointUtm($entryPoint);

        $container = static::getContainer();
        $container->set(RoutingController::class, $this->createRoutingController(
            tenant: $tenant,
            product: $product,
            entryPoint: $entryPoint,
            entryPointUtm: $entryPointUtm,
        ));

        $client->request('POST', '/api/internal/conversations/upsert', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ], content: json_encode([
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'customer_phone' => '+34999999999',
            'customer_name' => 'Ana García',
            'first_message' => 'Hola, quiero información.',
            'external_conversation_id' => 'wa-conversation-1',
            'entry_point_id' => $entryPoint->getId()->toRfc4122(),
            'entry_point_utm_id' => $entryPointUtm->getId()->toRfc4122(),
            'product_id' => $product->getId()->toRfc4122(),
        ]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['created']);
    }

    private function configureInternalToken(string $token): void
    {
        putenv('SALES_AGENT_BEARER_TOKEN='.$token);
        $_ENV['SALES_AGENT_BEARER_TOKEN'] = $token;
        $_SERVER['SALES_AGENT_BEARER_TOKEN'] = $token;
    }

    private function createRuntimeSettingsController(array $snapshot): InternalRuntimeSettingsController
    {
        $runtimeConfigurationService = new class($snapshot) extends RuntimeConfigurationService {
            public function __construct(private readonly array $snapshot)
            {
            }

            public function snapshot(): array
            {
                return $this->snapshot;
            }
        };

        $controller = new InternalRuntimeSettingsController(
            $runtimeConfigurationService,
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(static::getContainer());

        return $controller;
    }

    private function createRoutingController(
        ?Tenant $tenant = null,
        ?Product $product = null,
        ?EntryPoint $entryPoint = null,
        ?EntryPointUtm $entryPointUtm = null,
        ?Tenant $resolveTenantByPhone = null,
    ): RoutingController {
        $tenant ??= $this->createTenant();
        $product ??= $this->createProduct($tenant);
        $entryPoint ??= $this->createEntryPoint($tenant, $product);
        $entryPointUtm ??= $this->createEntryPointUtm($entryPoint);
        $resolveTenantByPhone ??= $tenant;

        $entryPointUtmRepository = new class($entryPointUtm) extends EntryPointUtmRepository {
            public function __construct(private readonly ?EntryPointUtm $entryPointUtm)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): object|null
            {
                return $this->entryPointUtm;
            }

            public function findByRef(string $ref): ?EntryPointUtm
            {
                return $this->entryPointUtm;
            }

            public function save(EntryPointUtm $entryPointUtm, bool $flush = true): void
            {
            }
        };

        $entryPointRepository = new class($entryPoint) extends EntryPointRepository {
            public function __construct(private readonly ?EntryPoint $entryPoint)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): object|null
            {
                return $this->entryPoint;
            }

            public function findActiveByCode(string $code): ?EntryPoint
            {
                return $this->entryPoint;
            }
        };

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly ?Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }

            public function findOneBy(array $criteria, array|null $orderBy = null): ?object
            {
                return $this->tenant;
            }
        };

        $productRepository = new class($product) extends ProductRepository {
            public function __construct(private readonly ?Product $product)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->product;
            }
        };

        $routingResolver = new RoutingResolver(
            $entryPointRepository,
            $entryPointUtmRepository,
            $tenantRepository,
        );

        $conversationRepository = new class extends ConversationRepository {
            public array $savedConversations = [];

            public function __construct()
            {
            }

            public function findActiveByTenantPhone(Tenant $tenant, string $customerPhone): ?Conversation
            {
                return null;
            }

            public function save(Conversation $conversation, bool $flush = true): void
            {
                $this->savedConversations[] = $conversation;
            }
        };

        $controller = new RoutingController(
            $routingResolver,
            new EntryPointUtmFactory($entryPointUtmRepository),
            new WhatsAppRedirectUrlBuilder(),
            $entryPointRepository,
            $entryPointUtmRepository,
            $tenantRepository,
            $productRepository,
            new ConversationService($conversationRepository),
            $conversationRepository,
        );
        $controller->setContainer(static::getContainer());

        return $controller;
    }

    private function createTenant(): Tenant
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');
        $tenant->setWhatsappPublicPhone('+34600000000');
        $tenant->setWhatsappPhoneNumberId('phone-number-id-1');

        return $tenant;
    }

    private function createProduct(Tenant $tenant): Product
    {
        $product = new Product($tenant, 'CRM Automation');
        $product->setDescription('Automatizacion de CRM.');
        $product->setValueProposition('Atiende leads 24/7.');

        return $product;
    }

    private function createEntryPoint(Tenant $tenant, Product $product): EntryPoint
    {
        $playbook = new Playbook($tenant, 'Guia comercial', $product);
        $playbook->setConfig([
            'objective' => 'Calificar leads.',
            'qualificationQuestions' => ['Que negocio tienes?'],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
                'positiveSignals' => [],
                'negativeSignals' => [],
            ],
            'handoffRules' => ['Derivar a humano.'],
            'allowedActions' => ['askQuestion'],
        ]);

        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setPlaybook($playbook);
        $entryPoint->setDefaultMessage('Hola, quiero informacion.');
        $entryPoint->setCrmBranchRef('branch-42');

        return $entryPoint;
    }

    private function createEntryPointUtm(EntryPoint $entryPoint): EntryPointUtm
    {
        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $entryPointUtm->setUtmSource('google');
        $entryPointUtm->setUtmMedium('cpc');
        $entryPointUtm->setUtmCampaign('demo');

        return $entryPointUtm;
    }
}
