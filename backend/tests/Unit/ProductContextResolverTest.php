<?php

namespace App\Tests\Unit;

use App\Entity\EntryPoint;
use App\Entity\ExternalTool;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\ProductRepository;
use App\Service\ProductContextResolver;
use PHPUnit\Framework\TestCase;

final class ProductContextResolverTest extends TestCase
{
    public function testEntryPointProductWinsOverExplicitProductAndSearch(): void
    {
        $tenant = $this->tenant();
        $entryPointProduct = $this->product($tenant, 'Depilación láser', 'depilacion-laser');
        $explicitProduct = $this->product($tenant, 'Masajes', 'masajes');
        $entryPoint = $this->entryPoint($entryPointProduct);

        $resolver = $this->resolver([$entryPointProduct, $explicitProduct], false);

        $result = $resolver->resolve($tenant, $entryPoint, $explicitProduct, 'Busco depilación láser');

        self::assertSame($entryPointProduct, $result['selected_product']);
        self::assertSame('entry_point', $result['selection_source']);
        self::assertSame([], $result['product_candidates']);
        self::assertFalse($result['needs_service_clarification']);
    }

    public function testExplicitProductIsReturnedWhenNoEntryPointProduct(): void
    {
        $tenant = $this->tenant();
        $explicitProduct = $this->product($tenant, 'Masajes', 'masajes');

        $resolver = $this->resolver([$explicitProduct], false);

        $result = $resolver->resolve($tenant, null, $explicitProduct, 'Hola');

        self::assertSame($explicitProduct, $result['selected_product']);
        self::assertSame('explicit_product_id', $result['selection_source']);
        self::assertSame([], $result['product_candidates']);
    }

    public function testAmbiguousSearchReturnsCandidatesAndClarification(): void
    {
        $tenant = $this->tenant();
        $first = $this->product($tenant, 'Depilación láser', 'depilacion-laser');
        $second = $this->product($tenant, 'Depilación con cera', 'depilacion-cera');

        $resolver = $this->resolver([$first, $second], false);

        $result = $resolver->resolve($tenant, null, null, 'Busco depilación');

        self::assertNull($result['selected_product']);
        self::assertSame('sa_search', $result['selection_source']);
        self::assertSame(2, $result['candidate_count']);
        self::assertCount(2, $result['product_candidates']);
        self::assertTrue($result['needs_service_clarification']);
    }

    public function testNoMatchAllowsMcpFallbackWhenRuntimeDefaultExists(): void
    {
        $tenant = $this->tenant();
        $resolver = $this->resolver([], true);

        $result = $resolver->resolve($tenant, null, null, 'Busco un servicio que no está en el catálogo');

        self::assertNull($result['selected_product']);
        self::assertTrue($result['fallback_to_mcp_allowed']);
        self::assertFalse($result['needs_service_clarification']);
        self::assertSame('no local product match; MCP fallback available', $result['reason']);
    }

    public function testWeakSingleMatchAllowsMcpFallbackWhenRuntimeDefaultExists(): void
    {
        $tenant = $this->tenant();
        $product = $this->product($tenant, 'Integración de APIs y sistemas', 'integracion-api-sistemas');
        $resolver = $this->resolver([$product], true);

        $result = $resolver->resolve($tenant, null, null, 'Busco integración con Holded o FacturaScripts');

        self::assertNull($result['selected_product']);
        self::assertCount(1, $result['product_candidates']);
        self::assertTrue($result['fallback_to_mcp_allowed']);
        self::assertFalse($result['needs_service_clarification']);
        self::assertSame('single weak local product candidate; MCP fallback available', $result['reason']);
    }

    public function testNoQueryAllowsMcpFallbackWhenTenantHasNoLocalCatalog(): void
    {
        $tenant = $this->tenant();
        $resolver = $this->resolver([], true);

        $result = $resolver->resolve($tenant, null, null, 'Hola');

        self::assertNull($result['selected_product']);
        self::assertSame('none', $result['selection_source']);
        self::assertSame(0, $result['candidate_count']);
        self::assertFalse($result['needs_service_clarification']);
        self::assertTrue($result['fallback_to_mcp_allowed']);
        self::assertSame('no local catalog; MCP fallback available', $result['reason']);
    }

    public function testNoQueryRequiresClarification(): void
    {
        $tenant = $this->tenant();
        $resolver = $this->resolver([], false);

        $result = $resolver->resolve($tenant, null, null, 'Hola');

        self::assertNull($result['selected_product']);
        self::assertTrue($result['needs_service_clarification']);
        self::assertFalse($result['fallback_to_mcp_allowed']);
        self::assertSame('missing service query', $result['reason']);
    }

    /**
     * @param list<Product> $products
     */
    private function resolver(array $products, bool $hasDefaultMcp): ProductContextResolver
    {
        $productRepository = new class($products) extends ProductRepository {
            public function __construct(private readonly array $products)
            {
            }

            public function searchActiveByTenantAndText(Tenant $tenant, string $query, int $limit = 20): array
            {
                return $this->products;
            }

            public function findActiveByTenantOrdered(Tenant $tenant): array
            {
                return $this->products;
            }
        };

        $externalToolRepository = new class($hasDefaultMcp) extends ExternalToolRepository {
            public function __construct(private readonly bool $hasDefaultMcp)
            {
            }

            public function findRuntimeDefaultMcpByTenant(Tenant $tenant): ?ExternalTool
            {
                if (!$this->hasDefaultMcp) {
                    return null;
                }

                $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
                $tool->setConfig([
                    'enabled_for_llm' => true,
                    'allowed_tools' => ['services_search', 'appointment_events'],
                ]);

                return $tool;
            }
        };

        return new ProductContextResolver($productRepository, $externalToolRepository);
    }

    private function tenant(string $id = 'tenant-1', string $name = 'Negocio Demo'): Tenant
    {
        $tenant = new Tenant($name, $id);
        $tenant->setBusinessContext('Contexto comercial');
        $tenant->setTone('consultivo');
        return $tenant;
    }

    private function product(Tenant $tenant, string $name, string $slug): Product
    {
        $product = new Product($tenant, $name, $slug);
        $product->setDescription($name.' description');
        $product->setValueProposition($name.' value proposition');

        return $product;
    }

    private function entryPoint(Product $product): EntryPoint
    {
        return new EntryPoint($product, 'crm-demo', 'WhatsApp inbound');
    }
}
