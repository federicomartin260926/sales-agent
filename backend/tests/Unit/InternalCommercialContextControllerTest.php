<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalCommercialContextController;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalCommercialContextControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    public function testTenantOnlyContextReturnsLegacyBlocksOnly(): void
    {
        $tenant = $this->tenant();
        $controller = $this->createController([$tenant->getId()->toRfc4122() => $tenant], [], [], [], []);

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122(), 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayNotHasKey('effective_context', $payload);
        self::assertSame('consultivo', $payload['tenant']['tone']);
        self::assertSame('Responder con claridad y foco comercial.', $payload['tenant']['sales_policy']['positioning']);
        self::assertNull($payload['product']);
        self::assertNull($payload['playbook']);
        self::assertNull($payload['entry_point']);
    }

    public function testProductPlaybookAndEntryPointAreReturnedAsLegacyBlocks(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant);
        $playbook = $this->playbook($tenant, $product);
        $entryPoint = $this->entryPoint($product, $playbook);
        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $entryPointUtm->setUtmSource('google');
        $entryPointUtm->setUtmMedium('cpc');
        $entryPointUtm->setUtmCampaign('demo');

        $controller = $this->createController(
            [$tenant->getId()->toRfc4122() => $tenant],
            [$product->getId()->toRfc4122() => $product],
            [$playbook->getId()->toRfc4122() => $playbook],
            [$entryPoint->getId()->toRfc4122() => $entryPoint],
            ['abc123' => $entryPointUtm],
        );

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122().'&entrypoint_ref=abc123&product_id='.$product->getId()->toRfc4122().'&playbook_id='.$playbook->getId()->toRfc4122(), 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertArrayNotHasKey('effective_context', $payload);
        self::assertSame('crm-demo', $payload['entry_point']['code']);
        self::assertSame('Guía campañas láser', $payload['playbook']['name']);
        self::assertSame('Depilación láser', $payload['product']['name']);
        self::assertSame('Cerrar cita', $payload['playbook']['config']['objective']);
        self::assertSame(['¿Qué horario le encaja?'], $payload['playbook']['config']['qualificationQuestions']);
    }

    public function testCrossTenantProductIsRejected(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $productB = $this->product($tenantB, 'Product B');

        $controller = $this->createController(
            [
                $tenantA->getId()->toRfc4122() => $tenantA,
                $tenantB->getId()->toRfc4122() => $tenantB,
            ],
            [$productB->getId()->toRfc4122() => $productB],
            [],
            [],
            [],
        );

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenantA->getId()->toRfc4122().'&product_id='.$productB->getId()->toRfc4122(), 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Product not found', json_decode((string) $response->getContent(), true)['message']);
    }

    private function createController(array $tenants, array $products, array $playbooks, array $entryPoints, array $entryPointUtms): InternalCommercialContextController
    {
        $controller = new InternalCommercialContextController(
            $this->tenantRepository($tenants),
            $this->productRepository($products),
            $this->playbookRepository($playbooks),
            $this->entryPointRepository($entryPoints),
            $this->entryPointUtmRepository($entryPointUtms),
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    private function tenant(string $id = 'tenant-1', string $name = 'Negocio Demo'): Tenant
    {
        $tenant = new Tenant($name, $id);
        $tenant->setBusinessContext('Contexto comercial general del negocio.');
        $tenant->setTone('consultivo');
        $tenant->setSalesPolicy([
            'positioning' => 'Responder con claridad y foco comercial.',
            'qualificationFocus' => 'Identificar volumen, canal y urgencia.',
            'handoffRules' => 'Derivar a humano si piden seguimiento manual.',
            'salesBoundaries' => ['No prometer integraciones inexistentes.'],
            'notes' => 'Usar como base para todos los leads del negocio.',
        ]);

        return $tenant;
    }

    private function product(Tenant $tenant, string $name = 'Depilación láser'): Product
    {
        $product = new Product($tenant, $name, 'depilacion-laser');
        $product->setDescription('Tratamiento de depilación permanente.');
        $product->setValueProposition('Eliminar el vello de forma progresiva.');
        $product->setSalesPolicy([
            'positioning' => 'Oferta consultiva con valoración previa.',
            'pricingNotes' => 'Desde 59€ por sesión.',
            'objections' => ['¿Qué tratamiento le interesa?', 'Tiene experiencia previa con depilación láser'],
            'handoffRules' => 'Derivar a humano si pide valoración personalizada.',
            'notes' => 'No cerrar precio sin revisar la zona.',
        ]);

        return $product;
    }

    private function playbook(Tenant $tenant, Product $product, string $name = 'Guía campañas láser'): Playbook
    {
        $playbook = new Playbook($tenant, $name, $product);
        $playbook->setConfig([
            'objective' => 'Cerrar cita',
            'qualificationQuestions' => [
                '¿Qué horario le encaja?',
            ],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
            ],
            'agendaRules' => [
                'Ofrecer cita de valoración si encaja.',
            ],
            'handoffRules' => [
                'Derivar a humano cuando haya dudas médicas.',
            ],
            'allowedActions' => [
                'askQuestion',
                'offer_booking',
            ],
            'notes' => 'No prometer resultados garantizados.',
        ]);

        return $playbook;
    }

    private function entryPoint(Product $product, Playbook $playbook, string $code = 'crm-demo'): EntryPoint
    {
        $entryPoint = new EntryPoint($product, $code, 'WhatsApp inbound');
        $entryPoint->setPlaybook($playbook);
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        return $entryPoint;
    }

    private function tenantRepository(array $tenants): TenantRepository
    {
        return new class($tenants) extends TenantRepository {
            public function __construct(private array $tenants)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenants[(string) $id] ?? null;
            }
        };
    }

    private function productRepository(array $products): ProductRepository
    {
        return new class($products) extends ProductRepository {
            public function __construct(private array $products)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->products[(string) $id] ?? null;
            }
        };
    }

    private function playbookRepository(array $playbooks): PlaybookRepository
    {
        return new class($playbooks) extends PlaybookRepository {
            public function __construct(private array $playbooks)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->playbooks[(string) $id] ?? null;
            }

            public function findActiveGeneralByTenant(Tenant $tenant): ?Playbook
            {
                return null;
            }
        };
    }

    private function entryPointRepository(array $entryPoints): EntryPointRepository
    {
        return new class($entryPoints) extends EntryPointRepository {
            public function __construct(private array $entryPoints)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->entryPoints[(string) $id] ?? null;
            }
        };
    }

    private function entryPointUtmRepository(array $entryPointUtms): EntryPointUtmRepository
    {
        return new class($entryPointUtms) extends EntryPointUtmRepository {
            public function __construct(private array $entryPointUtms)
            {
            }

            public function findByRef(string $ref): ?EntryPointUtm
            {
                return $this->entryPointUtms[$ref] ?? null;
            }
        };
    }
}
