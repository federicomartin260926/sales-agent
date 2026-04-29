<?php

namespace App\Tests\Unit;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use App\Service\ProductCatalogImportService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ProductCatalogImportServiceTest extends TestCase
{
    private function createProductRepositoryFake(?Product $externalMatch = null, ?Product $slugMatch = null): ProductRepository
    {
        return new class($externalMatch, $slugMatch) extends ProductRepository {
            public function __construct(
                private ?Product $externalMatch,
                private ?Product $slugMatch,
            ) {
            }

            public function findOneByExternalIdentity(Tenant $tenant, string $source, string $reference): ?Product
            {
                if ($this->externalMatch instanceof Product
                    && $this->externalMatch->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                    && $source === 'crm'
                    && $this->externalMatch->getExternalReference() === $reference
                ) {
                    return $this->externalMatch;
                }

                return null;
            }

            public function findOneByTenantAndSlug(Tenant $tenant, string $slug): ?Product
            {
                if ($this->slugMatch instanceof Product
                    && $this->slugMatch->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                    && $this->slugMatch->getSlug() === $slug
                ) {
                    return $this->slugMatch;
                }

                return null;
            }
        };
    }

    public function testItImportsJsonAndUpsertsByExternalReferenceThenSlugFallback(): void
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');

        $existingByExternal = new Product($tenant, 'Starter Old', 'pack-starter');
        $existingByExternal->setExternalSource('crm');
        $existingByExternal->setExternalReference('pack-starter');
        $existingByExternal->setDescription('Old description');
        $existingByExternal->setValueProposition('Old value');
        $existingByExternal->setBasePriceCents(100000);
        $existingByExternal->setCurrency('EUR');
        $existingByExternal->setActive(false);

        $existingBySlug = new Product($tenant, 'Plus Old', 'pack-plus');
        $existingBySlug->setDescription('Old plus description');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (Product $product) use (&$persisted): bool {
            $persisted = $product;

            return $product->getSlug() === 'pack-enterprise'
                && $product->getExternalSource() === 'crm'
                && $product->getExternalReference() === 'pack-enterprise'
                && $product->getBasePriceCents() === 250000
                && $product->getCurrency() === 'EUR';
        }));
        $entityManager->expects(self::once())->method('flush');

        $service = new ProductCatalogImportService(
            $this->createProductRepositoryFake($existingByExternal, $existingBySlug),
            $entityManager
        );

        $result = $service->import($tenant, json_encode([
            'source' => 'crm',
            'resource' => 'services',
            'items' => [
                [
                    'type' => 'service',
                    'integration_key' => 'pack-starter',
                    'name' => 'Pack Starter Pro',
                    'slug' => 'pack-starter',
                    'description' => 'Starter actualizado',
                    'base_price_cents' => 150000,
                    'currency' => 'EUR',
                    'active' => true,
                ],
                [
                    'type' => 'service',
                    'name' => 'Pack Plus Pro',
                    'slug' => 'pack-plus',
                    'description' => 'Plus actualizado por slug',
                    'base_price_cents' => 200000,
                    'currency' => 'EUR',
                    'active' => 'Activo',
                ],
                [
                    'type' => 'service',
                    'integration_key' => 'pack-enterprise',
                    'name' => 'Pack Enterprise',
                    'slug' => 'pack-enterprise',
                    'description' => 'Enterprise',
                    'base_price_cents' => 250000,
                    'currency' => 'EUR',
                    'active' => 1,
                ],
            ],
        ], JSON_THROW_ON_ERROR), 'json');

        self::assertSame(1, $result->created);
        self::assertSame(2, $result->updated);
        self::assertSame(0, $result->omitted);
        self::assertCount(0, $result->errors);

        self::assertSame('Pack Starter Pro', $existingByExternal->getName());
        self::assertSame('Starter actualizado', $existingByExternal->getDescription());
        self::assertSame(150000, $existingByExternal->getBasePriceCents());
        self::assertTrue($existingByExternal->isActive());

        self::assertSame('Pack Plus Pro', $existingBySlug->getName());
        self::assertSame('pack-plus', $existingBySlug->getSlug());
        self::assertSame(200000, $existingBySlug->getBasePriceCents());
        self::assertTrue($existingBySlug->isActive());
        self::assertSame('Pack Enterprise', $persisted?->getName());
    }

    public function testItImportsCsvWithSemicolonAndActiveValues(): void
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (Product $product): bool {
            return $product->getSlug() === 'pack-starter'
                && $product->getExternalSource() === 'crm'
                && $product->getExternalReference() === 'pack-starter'
                && $product->getBasePriceCents() === 150000
                && $product->getCurrency() === 'EUR'
                && $product->isActive();
        }));
        $entityManager->expects(self::once())->method('flush');

        $service = new ProductCatalogImportService(
            $this->createProductRepositoryFake(),
            $entityManager
        );

        $result = $service->import($tenant, <<<CSV
type;integration_key;name;slug;description;base_price_cents;currency;active
service;pack-starter;Pack Starter;pack-starter;"Solución inicial para pymes...";150000;EUR;Activo
CSV, 'auto');

        self::assertSame(1, $result->created);
        self::assertSame(0, $result->updated);
        self::assertSame(0, $result->omitted);
        self::assertCount(0, $result->errors);
    }
}
