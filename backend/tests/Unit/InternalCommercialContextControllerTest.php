<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalCommercialContextController;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\ExternalTool;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\ExternalToolRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use App\Service\ProductContextResolver;
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
        self::assertSame([], $payload['products']);
        self::assertSame('none', $payload['product_selection']['selection_source']);
        self::assertTrue($payload['product_selection']['needs_service_clarification']);
        self::assertNull($payload['playbook']);
        self::assertNull($payload['entry_point']);
        self::assertSame([
            'enabled' => false,
            'strategy' => 'disabled',
            'whatsapp_public' => null,
            'message' => null,
        ], $payload['tenant']['handoff']);
    }

    public function testTenantContextIncludesHandoffBlock(): void
    {
        $tenant = $this->tenant();
        $tenant->setHumanHandoffEnabled(true);
        $tenant->setHumanHandoffWhatsappPublic('+34 612 345 678');
        $tenant->setHumanHandoffMessage('Prefiero que esto lo revise una persona del equipo.');
        $tenant->setHumanHandoffStrategy('manual_wa_link_and_n8n');

        $controller = $this->createController([$tenant->getId()->toRfc4122() => $tenant], [], [], [], []);

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122(), 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame([
            'enabled' => true,
            'strategy' => 'manual_wa_link_and_n8n',
            'whatsapp_public' => '+34 612 345 678',
            'message' => 'Prefiero que esto lo revise una persona del equipo.',
        ], $payload['tenant']['handoff']);
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
        self::assertSame('entry_point', $payload['product_selection']['selection_source']);
        self::assertSame([], $payload['products']);
        self::assertSame('Cerrar cita', $payload['playbook']['config']['objective']);
        self::assertSame(['¿Qué horario le encaja?'], $payload['playbook']['config']['qualificationQuestions']);
    }

    public function testProductSearchReturnsCandidatesAndClarification(): void
    {
        $tenant = $this->tenant();
        $productA = $this->product($tenant, 'Depilación láser', 'depilacion-laser');
        $productB = $this->product($tenant, 'Depilación con cera', 'depilacion-cera');
        $controller = $this->createController(
            [$tenant->getId()->toRfc4122() => $tenant],
            [
                $productA->getId()->toRfc4122() => $productA,
                $productB->getId()->toRfc4122() => $productB,
            ],
            [],
            [],
            [],
        );

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122().'&current_message=Busco depilación', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertNull($payload['product']);
        self::assertCount(2, $payload['products']);
        self::assertSame('sa_search', $payload['product_selection']['selection_source']);
        self::assertTrue($payload['product_selection']['needs_service_clarification']);
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

    public function testProductSearchAllowsMcpFallbackWhenNoLocalMatchExists(): void
    {
        $tenant = $this->tenant();
        $externalTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $externalTool->setRuntimeDefault(true);
        $externalTool->setConfig([
            'enabled_for_llm' => true,
            'allowed_tools' => ['services_search'],
        ]);

        $controller = $this->createController(
            [$tenant->getId()->toRfc4122() => $tenant],
            [],
            [],
            [],
            [],
            $this->externalToolRepository([$externalTool]),
        );

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122().'&current_message=Busco servicio que no existe', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertNull($payload['product']);
        self::assertSame([], $payload['products']);
        self::assertTrue($payload['product_selection']['fallback_to_mcp_allowed']);
        self::assertFalse($payload['product_selection']['needs_service_clarification']);
    }

    public function testWeakSingleSearchAllowsMcpFallbackWhenRuntimeDefaultExists(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant, 'Integración de APIs y sistemas', 'integracion-api-sistemas');
        $externalTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $externalTool->setRuntimeDefault(true);
        $externalTool->setConfig([
            'enabled_for_llm' => true,
            'allowed_tools' => ['services_search'],
        ]);

        $controller = $this->createController(
            [$tenant->getId()->toRfc4122() => $tenant],
            [$product->getId()->toRfc4122() => $product],
            [],
            [],
            [],
            $this->externalToolRepository([$externalTool]),
        );

        $response = $controller(Request::create('/api/internal/commercial-context?tenant_id='.$tenant->getId()->toRfc4122().'&current_message=Busco integración con Holded o FacturaScripts', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertNull($payload['product']);
        self::assertCount(1, $payload['products']);
        self::assertTrue($payload['product_selection']['fallback_to_mcp_allowed']);
        self::assertFalse($payload['product_selection']['needs_service_clarification']);
        self::assertSame('single weak local product candidate; MCP fallback available', $payload['product_selection']['reason']);
    }

    private function createController(array $tenants, array $products, array $playbooks, array $entryPoints, array $entryPointUtms, ?ExternalToolRepository $externalTools = null): InternalCommercialContextController
    {
        $tenantRepository = $this->tenantRepository($tenants);
        $productRepository = $this->productRepository($products);
        $playbookRepository = $this->playbookRepository($playbooks);
        $entryPointRepository = $this->entryPointRepository($entryPoints);
        $entryPointUtmRepository = $this->entryPointUtmRepository($entryPointUtms);
        $externalTools ??= $this->externalToolRepository([]);

        $controller = new InternalCommercialContextController(
            $tenantRepository,
            $productRepository,
            $playbookRepository,
            $entryPointRepository,
            $entryPointUtmRepository,
            new ProductContextResolver($productRepository, $externalTools),
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

            public function searchActiveByTenantAndText(Tenant $tenant, string $query, int $limit = 20): array
            {
                return array_values(array_filter(
                    $this->products,
                    static fn ($product): bool => $product instanceof Product && $product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $product->isActive()
                ));
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

    private function externalToolRepository(array $tools): ExternalToolRepository
    {
        return new class($tools) extends ExternalToolRepository {
            public function __construct(private array $tools)
            {
            }

            public function findRuntimeDefaultMcpByTenant(Tenant $tenant): ?ExternalTool
            {
                foreach ($this->tools as $tool) {
                    if ($tool instanceof ExternalTool && $tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $tool->isActive() && $tool->isRuntimeDefault()) {
                        return $tool;
                    }
                }

                return null;
            }
        };
    }
}
