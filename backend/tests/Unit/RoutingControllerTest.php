<?php

namespace App\Tests\Unit;

use App\Controller\Api\RoutingController;
use App\Entity\Conversation;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ConversationRepository;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\ConversationService;
use App\Service\EntryPointUtmFactory;
use App\Service\RoutingResolver;
use App\Service\WhatsAppRedirectUrlBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class RoutingControllerTest extends TestCase
{
    private function createTenant(string $name = 'Negocio Demo', string $slug = 'negocio-demo'): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setWhatsappPublicPhone('+34600000000');

        return $tenant;
    }

    private function createProduct(Tenant $tenant, string $name = 'CRM Automation'): Product
    {
        $product = new Product($tenant, $name);
        $product->setDescription('Automatización de CRM.');
        $product->setValueProposition('Atiende leads 24/7.');

        return $product;
    }

    private function createPlaybook(Tenant $tenant, ?Product $product = null): Playbook
    {
        $playbook = new Playbook($tenant, 'Guía comercial', $product);
        $playbook->setConfig([
            'objective' => 'Calificar leads.',
            'qualificationQuestions' => ['¿Qué negocio tienes?'],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
                'positiveSignals' => [],
                'negativeSignals' => [],
            ],
            'handoffRules' => ['Derivar a humano.'],
            'allowedActions' => ['askQuestion'],
        ]);

        return $playbook;
    }

    private function createEntryPoint(Product $product, ?Playbook $playbook = null): EntryPoint
    {
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setPlaybook($playbook);
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        return $entryPoint;
    }

    private function createController(
        RoutingResolver $routingResolver,
        ?EntryPointUtmFactory $entryPointUtmFactory = null,
        ?WhatsAppRedirectUrlBuilder $builder = null,
        ?TenantRepository $tenants = null,
        ?EntryPointRepository $entryPoints = null,
        ?EntryPointUtmRepository $entryPointUtms = null,
        ?ProductRepository $products = null,
        ?ConversationService $conversationService = null,
        ?ConversationRepository $conversations = null,
    ): RoutingController {
        $entryPointUtms ??= $this->createStub(EntryPointUtmRepository::class);
        $controller = new RoutingController(
            $routingResolver,
            $entryPointUtmFactory ?? new EntryPointUtmFactory($entryPointUtms),
            $builder ?? new WhatsAppRedirectUrlBuilder(),
            $entryPoints ?? $this->createStub(EntryPointRepository::class),
            $entryPointUtms,
            $tenants ?? $this->createStub(TenantRepository::class),
            $products ?? $this->createStub(ProductRepository::class),
            $conversationService ?? new ConversationService($this->createStub(ConversationRepository::class)),
            $conversations ?? $this->createStub(ConversationRepository::class),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    public function testRedirectToWhatsappCreatesEntryPointUtmAndAddsRefToMessage(): void
    {
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $playbook = $this->createPlaybook($tenant, $product);
        $entryPoint = $this->createEntryPoint($product, $playbook);

        $savedUtms = [];
        $entryPointUtmRepository = new class($savedUtms) extends EntryPointUtmRepository {
            public array $savedUtms;

            public function __construct()
            {
                $this->savedUtms = [];
            }

            public function findByRef(string $ref): ?EntryPointUtm
            {
                return null;
            }

            public function save(EntryPointUtm $entryPointUtm, bool $flush = true): void
            {
                $this->savedUtms[] = $entryPointUtm;
            }
        };

        $entryPointRepository = new class($entryPoint) extends EntryPointRepository {
            public function __construct(private readonly EntryPoint $entryPoint)
            {
            }

            public function findActiveByCode(string $code): ?EntryPoint
            {
                return $this->entryPoint;
            }
        };

        $resolver = new RoutingResolver($entryPointRepository, $entryPointUtmRepository, $this->createStub(TenantRepository::class));
        $controller = $this->createController($resolver, null, new WhatsAppRedirectUrlBuilder(), null, $entryPointRepository, $entryPointUtmRepository);

        $response = $controller->redirectToWhatsApp('crm-demo', Request::create('/api/r/wa/crm-demo', 'GET', [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'crm_pymes',
            'gclid' => 'abc',
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertStringStartsWith('https://wa.me/34600000000?text=', $response->getTargetUrl());
        self::assertStringContainsString('Ref%3A%20', $response->getTargetUrl());
        self::assertCount(1, $entryPointUtmRepository->savedUtms);
        self::assertSame('google', $entryPointUtmRepository->savedUtms[0]->getUtmSource());
        self::assertSame('cpc', $entryPointUtmRepository->savedUtms[0]->getUtmMedium());
        self::assertSame('crm_pymes', $entryPointUtmRepository->savedUtms[0]->getUtmCampaign());
    }

    public function testResolveEntryPointRefReturnsAttribution(): void
    {
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $playbook = $this->createPlaybook($tenant, $product);
        $entryPoint = $this->createEntryPoint($product, $playbook);
        $entryPoint->setCrmBranchRef('branch-42');
        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $entryPointUtm->setUtmSource('google');
        $entryPointUtm->setUtmMedium('cpc');
        $entryPointUtm->setUtmCampaign('crm_pymes');
        $entryPointUtm->markMatched();

        $entryPointUtmRepository = new class($entryPointUtm) extends EntryPointUtmRepository {
            public function __construct(private readonly EntryPointUtm $entryPointUtm)
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
        };

        $entryPointRepository = new class($entryPoint) extends EntryPointRepository {
            public function __construct(private readonly EntryPoint $entryPoint)
            {
            }

            public function findActiveByCode(string $code): ?EntryPoint
            {
                return $this->entryPoint;
            }
        };

        $resolver = new RoutingResolver($entryPointRepository, $entryPointUtmRepository, $this->createStub(TenantRepository::class));
        $controller = $this->createController($resolver);

        $response = $controller->resolveEntryPointRef('abc123');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame($entryPointUtm->getId()->toRfc4122(), $data['entry_point_utm_id']);
        self::assertSame($entryPoint->getId()->toRfc4122(), $data['entry_point_id']);
        self::assertSame($tenant->getId()->toRfc4122(), $data['tenant_id']);
        self::assertSame('branch-42', $data['crm_branch_ref']);
        self::assertSame('google', $data['utm_source']);
    }

    public function testResolveWhatsappPhoneReturnsTenant(): void
    {
        $tenant = $this->createTenant();
        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findOneBy(array $criteria, array|null $orderBy = null): ?object
            {
                return $this->tenant;
            }
        };

        $resolver = new RoutingResolver(
            $this->createStub(EntryPointRepository::class),
            $this->createStub(EntryPointUtmRepository::class),
            $tenantRepository,
        );

        $controller = $this->createController($resolver, null, null, $tenantRepository);
        $response = $controller->resolveWhatsappPhone('phone-number-id-1');

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertSame($tenant->getId()->toRfc4122(), $data['tenant_id']);
        self::assertSame($tenant->getSlug(), $data['tenant_slug']);
    }

    public function testUpsertConversationReusesActiveConversationAndMarksEntryPointUtmMatched(): void
    {
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $entryPoint = $this->createEntryPoint($product);
        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $existingConversation = new Conversation($tenant, '+34999999999');

        $conversationRepository = new class($existingConversation) extends ConversationRepository {
            public function __construct(private readonly Conversation $conversation)
            {
            }

            public function findActiveByTenantPhone(Tenant $tenant, string $customerPhone): ?Conversation
            {
                return $this->conversation;
            }

            public function save(Conversation $conversation, bool $flush = true): void
            {
            }
        };

        $conversationService = new ConversationService($conversationRepository);
        $entryPointUtmRepository = new class($entryPointUtm) extends EntryPointUtmRepository {
            public function __construct(private readonly EntryPointUtm $entryPointUtm)
            {
            }

            public function findByRef(string $ref): ?EntryPointUtm
            {
                return $this->entryPointUtm;
            }
        };

        $entryPointRepository = new class($entryPoint) extends EntryPointRepository {
            public function __construct(private readonly EntryPoint $entryPoint)
            {
            }

            public function findActiveByCode(string $code): ?EntryPoint
            {
                return $this->entryPoint;
            }
        };

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): object|null
            {
                return $this->tenant;
            }
        };

        $resolver = new RoutingResolver($entryPointRepository, $entryPointUtmRepository, $this->createStub(TenantRepository::class));
        $controller = $this->createController($resolver, null, null, $tenantRepository, $entryPointRepository, $entryPointUtmRepository, null, $conversationService);

        $response = $controller->upsertConversation(Request::create('/api/internal/conversations/upsert', 'POST', [], [], [], [], json_encode([
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'customer_phone' => '+34999999999',
            'entrypoint_ref' => 'abc123',
            'customer_name' => 'Ana García',
            'first_message' => 'Hola',
        ])));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('matched', $entryPointUtm->getStatus());
        $data = json_decode((string) $response->getContent(), true);
        self::assertFalse($data['created']);
        self::assertSame($entryPointUtm->getId()->toRfc4122(), $data['conversation']['entryPointUtmId']);
        self::assertSame('Ana García', $data['conversation']['customerName']);
    }
}
