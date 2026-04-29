<?php

namespace App\Tests\Functional;

use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Tenant;
use App\Kernel;
use App\Repository\ConversationRepository;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\ConversationService;
use App\Service\EntryPointUtmFactory;
use App\Service\RoutingResolver;
use App\Service\WhatsAppRedirectUrlBuilder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class WhatsAppRedirectPublicTest extends WebTestCase
{
    protected static function createKernel(array $options = []): Kernel
    {
        return new Kernel('test', true);
    }

    public function testRedirectEndpointWorksWithoutAuthorizationHeader(): void
    {
        $client = static::createClient();

        $tenant = new Tenant('Negocio Demo', 'negocio-demo');
        $tenant->setWhatsappPublicPhone('+34600000000');

        $product = new \App\Entity\Product($tenant, 'CRM Automation');
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        $entryPointRepository = new class($entryPoint) extends EntryPointRepository {
            public function __construct(private readonly EntryPoint $entryPoint)
            {
            }

            public function findActiveByCode(string $code): ?EntryPoint
            {
                return $this->entryPoint;
            }
        };

        $entryPointUtmRepository = new class extends EntryPointUtmRepository {
            public array $saved = [];

            public function __construct()
            {
            }

            public function findByRef(string $ref): ?EntryPointUtm
            {
                return null;
            }

            public function save(EntryPointUtm $entryPointUtm, bool $flush = true): void
            {
                $this->saved[] = $entryPointUtm;
            }
        };

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findOneBy(array $criteria, array|null $orderBy = null): ?object
            {
                return $this->tenant;
            }
        };

        $routingResolver = new RoutingResolver($entryPointRepository, $entryPointUtmRepository, $tenantRepository);
        $conversationService = new ConversationService($this->createStub(ConversationRepository::class));

        $container = static::getContainer();
        $container->set(RoutingResolver::class, $routingResolver);
        $container->set(EntryPointUtmFactory::class, new EntryPointUtmFactory($entryPointUtmRepository));
        $container->set(WhatsAppRedirectUrlBuilder::class, new WhatsAppRedirectUrlBuilder());
        $container->set(TenantRepository::class, $tenantRepository);
        $container->set(EntryPointRepository::class, $entryPointRepository);
        $container->set(EntryPointUtmRepository::class, $entryPointUtmRepository);
        $container->set(ProductRepository::class, $this->createStub(ProductRepository::class));
        $container->set(ConversationService::class, $conversationService);

        $client->request('GET', '/api/r/wa/crm-demo?utm_source=google&utm_medium=cpc');

        self::assertResponseStatusCodeSame(302);
        self::assertCount(1, $entryPointUtmRepository->saved);
        $entryPointUtm = $entryPointUtmRepository->saved[0];
        $location = $client->getResponse()->headers->get('Location');

        self::assertIsString($location);
        self::assertStringStartsWith('https://wa.me/34600000000?text=', $location);
        self::assertStringContainsString(rawurlencode('Ref: '.$entryPointUtm->getRef()), $location);
        self::assertSame('google', $entryPointUtm->getUtmSource());
        self::assertSame('cpc', $entryPointUtm->getUtmMedium());
    }
}
