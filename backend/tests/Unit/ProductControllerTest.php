<?php

namespace App\Tests\Unit;

use App\Controller\Api\ProductController;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Container;

final class ProductControllerTest extends TestCase
{
    public function testIndexRequiresTenantId(): void
    {
        $controller = $this->createController([], []);

        $response = $controller->index(Request::create('/api/products', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('tenantId is required', json_decode((string) $response->getContent(), true)['message']);
    }

    public function testIndexFiltersByTenantId(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $productA = $this->product($tenantA, 'Product A');
        $productB = $this->product($tenantB, 'Product B');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA, $productB],
        );

        $response = $controller->index(Request::create('/api/products?tenant_id=tenant-a', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertCount(1, $payload);
        self::assertSame($productA->getId()->toRfc4122(), $payload[0]['id']);
    }

    public function testShowRejectsCrossTenantAccess(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $productA = $this->product($tenantA, 'Product A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA],
        );

        $response = $controller->show($productA->getId()->toRfc4122(), Request::create('/api/products/'.$productA->getId()->toRfc4122().'?tenant_id=tenant-b', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateAssignsScopedTenantAndDoesNotMixTenants(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $repository = $this->productRepository([]);
        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [],
            $repository,
        );

        $response = $controller->create(Request::create(
            '/api/products?tenant_id=tenant-a',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'WhatsApp Automation',
                'slug' => 'whatsapp-automation',
                'description' => 'Automatización comercial',
                'valueProposition' => 'Atiende leads 24/7',
                'salesPolicy' => [
                    'positioning' => 'Automatización comercial para leads entrantes.',
                ],
                'isActive' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $repository->saved);
        self::assertSame($tenantA->getId()->toRfc4122(), $repository->saved[0]->getTenant()->getId()->toRfc4122());
    }

    public function testUpdateRejectsTenantChange(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $productA = $this->product($tenantA, 'Product A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA],
        );

        $response = $controller->update($productA->getId()->toRfc4122(), Request::create(
            '/api/products/'.$productA->getId()->toRfc4122().'?tenant_id=tenant-a',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'tenantId' => 'tenant-b',
                'name' => 'Product A Updated',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('tenantId cannot be changed', json_decode((string) $response->getContent(), true)['message']);
    }

    public function testDeleteRejectsCrossTenantAccess(): void
    {
        $tenantA = $this->tenant('tenant-a', 'Tenant A');
        $tenantB = $this->tenant('tenant-b', 'Tenant B');
        $productA = $this->product($tenantA, 'Product A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA],
        );

        $response = $controller->delete($productA->getId()->toRfc4122(), Request::create('/api/products/'.$productA->getId()->toRfc4122().'?tenant_id=tenant-b', 'DELETE'));

        self::assertSame(404, $response->getStatusCode());
    }

    private function createController(array $tenants, array $products, ?ProductRepository $productRepository = null): ProductController
    {
        $tenantRepository = $this->tenantRepository($tenants);
        $productRepository ??= $this->productRepository($products);

        $controller = new ProductController(
            $productRepository,
            $tenantRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->superAdminSecurity(),
        );
        $controller->setContainer(new Container());

        return $controller;
    }

    private function tenantRepository(array $tenants): TenantRepository
    {
        return new class($tenants) extends TenantRepository {
            /** @var array<string, Tenant> */
            private array $tenants;

            /**
             * @param array<string, Tenant> $tenants
             */
            public function __construct(array $tenants)
            {
                $this->tenants = $tenants;
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
            /** @var array<string, Product> */
            public array $saved = [];

            /** @var array<string, Product> */
            private array $products;

            /**
             * @param array<int, Product> $products
             */
            public function __construct(array $products)
            {
                $this->products = [];
                foreach ($products as $product) {
                    $this->products[$product->getId()->toRfc4122()] = $product;
                }
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->products[(string) $id] ?? null;
            }

            public function findByTenantOrdered(Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->products,
                    static fn (Product $product): bool => $product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function findOneByTenantAndSlug(Tenant $tenant, string $slug): ?Product
            {
                foreach ($this->products as $product) {
                    if ($product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $product->getSlug() === $slug) {
                        return $product;
                    }
                }

                return null;
            }

            public function findOneByExternalIdentity(Tenant $tenant, string $source, string $reference): ?Product
            {
                return null;
            }

            public function save(Product $product, bool $flush = true): void
            {
                $this->saved[] = $product;
            }

            public function remove(Product $product, bool $flush = true): void
            {
            }
        };
    }

    private function tenant(string $id, string $name): Tenant
    {
        $tenant = new Tenant($name, $id);
        $tenant->setActive(true);

        return $tenant;
    }

    private function product(Tenant $tenant, string $name): Product
    {
        return new Product($tenant, $name);
    }

    private function superAdminSecurity(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        return $security;
    }
}
