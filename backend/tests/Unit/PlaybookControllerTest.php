<?php

namespace App\Tests\Unit;

use App\Controller\Api\PlaybookController;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\Container;

final class PlaybookControllerTest extends TestCase
{
    public function testIndexRequiresTenantId(): void
    {
        $controller = $this->createController([], [], []);

        $response = $controller->index(Request::create('/api/playbooks', 'GET'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('tenantId is required', json_decode((string) $response->getContent(), true)['message']);
    }

    public function testIndexFiltersByTenantId(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $playbookA = $this->playbook($tenantA, 'Playbook A');
        $playbookB = $this->playbook($tenantB, 'Playbook B');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [],
            [$playbookA, $playbookB],
        );

        $response = $controller->index(Request::create('/api/playbooks?tenant_id=tenant-a', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertCount(1, $payload);
        self::assertSame($playbookA->getId()->toRfc4122(), $payload[0]['id']);
    }

    public function testShowRejectsCrossTenantAccess(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $playbookA = $this->playbook($tenantA, 'Playbook A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [],
            [$playbookA],
        );

        $response = $controller->show($playbookA->getId()->toRfc4122(), Request::create('/api/playbooks/'.$playbookA->getId()->toRfc4122().'?tenant_id=tenant-b', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testCreateRejectsProductFromOtherTenant(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $productA = $this->product($tenantA, 'Product A');
        $productB = $this->product($tenantB, 'Product B');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA, $productB],
            [],
        );

        $response = $controller->create(Request::create(
            '/api/playbooks?tenant_id=tenant-a',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Guía comercial',
                'productId' => $productB->getId()->toRfc4122(),
                'config' => [],
                'isActive' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('product must belong to the same tenant', json_decode((string) $response->getContent(), true)['message']);
    }

    public function testCreateAssignsScopedTenantAndProduct(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $productA = $this->product($tenantA, 'Product A');
        $productB = $this->product($tenantB, 'Product B');
        $repository = $this->playbookRepository([]);

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [$productA, $productB],
            [],
            $repository,
        );

        $response = $controller->create(Request::create(
            '/api/playbooks?tenant_id=tenant-a',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'name' => 'Guía comercial',
                'productId' => $productA->getId()->toRfc4122(),
                'config' => [],
                'isActive' => true,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        self::assertSame(201, $response->getStatusCode());
        self::assertCount(1, $repository->saved);
        self::assertSame($tenantA->getId()->toRfc4122(), $repository->saved[0]->getTenant()->getId()->toRfc4122());
        self::assertSame($productA->getId()->toRfc4122(), $repository->saved[0]->getProduct()?->getId()->toRfc4122());
    }

    public function testUpdateRejectsTenantChange(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $playbookA = $this->playbook($tenantA, 'Playbook A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [],
            [$playbookA],
        );

        $response = $controller->update($playbookA->getId()->toRfc4122(), Request::create(
            '/api/playbooks/'.$playbookA->getId()->toRfc4122().'?tenant_id=tenant-a',
            'PUT',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'tenantId' => $tenantB->getId()->toRfc4122(),
                'name' => 'Playbook A Updated',
                'config' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('tenantId cannot be changed', json_decode((string) $response->getContent(), true)['message']);
    }

    public function testDeleteRejectsCrossTenantAccess(): void
    {
        $tenantA = $this->tenant('Tenant A');
        $tenantB = $this->tenant('Tenant B');
        $playbookA = $this->playbook($tenantA, 'Playbook A');

        $controller = $this->createController(
            ['tenant-a' => $tenantA, 'tenant-b' => $tenantB],
            [],
            [$playbookA],
        );

        $response = $controller->delete($playbookA->getId()->toRfc4122(), Request::create('/api/playbooks/'.$playbookA->getId()->toRfc4122().'?tenant_id=tenant-b', 'DELETE'));

        self::assertSame(404, $response->getStatusCode());
    }

    private function createController(array $tenants, array $products, array $playbooks, ?PlaybookRepository $playbookRepository = null): PlaybookController
    {
        $tenantRepository = $this->tenantRepository($tenants);
        $productRepository = $this->productRepository($products);
        $playbookRepository ??= $this->playbookRepository($playbooks);

        $controller = new PlaybookController(
            $playbookRepository,
            $tenantRepository,
            $productRepository,
            $this->createStub(EntityManagerInterface::class),
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
        };
    }

    private function playbookRepository(array $playbooks): PlaybookRepository
    {
        return new class($playbooks) extends PlaybookRepository {
            /** @var array<string, Playbook> */
            public array $saved = [];

            /** @var array<string, Playbook> */
            private array $playbooks;

            /**
             * @param array<int, Playbook> $playbooks
             */
            public function __construct(array $playbooks)
            {
                $this->playbooks = [];
                foreach ($playbooks as $playbook) {
                    $this->playbooks[$playbook->getId()->toRfc4122()] = $playbook;
                }
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->playbooks[(string) $id] ?? null;
            }

            public function findByTenantOrdered(Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->playbooks,
                    static fn (Playbook $playbook): bool => $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function save(Playbook $playbook, bool $flush = true): void
            {
                $this->saved[] = $playbook;
            }

            public function remove(Playbook $playbook, bool $flush = true): void
            {
            }
        };
    }

    private function tenant(string $name): Tenant
    {
        $tenant = new Tenant($name, strtolower(str_replace(' ', '-', $name)));
        $tenant->setActive(true);

        return $tenant;
    }

    private function product(Tenant $tenant, string $name): Product
    {
        return new Product($tenant, $name);
    }

    private function playbook(Tenant $tenant, string $name, ?Product $product = null): Playbook
    {
        $playbook = new Playbook($tenant, $name, $product);
        $playbook->setConfig([]);

        return $playbook;
    }
}
