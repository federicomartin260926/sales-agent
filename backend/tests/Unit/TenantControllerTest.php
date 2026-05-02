<?php

namespace App\Tests\Unit;

use App\Controller\Api\TenantController;
use App\Domain\CommercialDomainSchema;
use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class TenantControllerTest extends TestCase
{
    public function testCreatePersistsWhatsAppChannelFields(): void
    {
        $state = (object) ['tenant' => null];

        $tenants = new class($state) extends TenantRepository {
            public function __construct(private object $state)
            {
            }

            public function findOneBy(array $criteria, array|null $orderBy = null): ?object
            {
                return null;
            }

            public function save(Tenant $tenant, bool $flush = true): void
            {
                $this->state->tenant = $tenant;
            }
        };

        $controller = new TenantController(
            $tenants,
            $this->createStub(EntityManagerInterface::class),
        );
        $controller->setContainer(new Container());

        $response = $controller->create(Request::create('/api/tenants', 'POST', [], [], [], [], json_encode([
            'name' => 'Academia Nova',
            'slug' => 'academia-nova',
            'businessContext' => 'Negocio demo',
            'tone' => 'Cercano',
            'whatsappPhoneNumberId' => '123456789012345',
            'whatsappPublicPhone' => '34612345678',
            'salesPolicy' => CommercialDomainSchema::normalizeTenantSalesPolicy([
                'positioning' => 'Demo comercial',
                'qualificationFocus' => 'Identificar tipo de negocio',
                'handoffRules' => 'Derivar cuando el lead pida demo',
                'salesBoundaries' => ['No prometer cierres automáticos'],
                'notes' => 'Plantilla de pruebas',
            ]),
            'isActive' => true,
        ])));

        self::assertSame(201, $response->getStatusCode());
        self::assertInstanceOf(Tenant::class, $state->tenant);
        self::assertSame('123456789012345', $state->tenant->getWhatsappPhoneNumberId());
        self::assertSame('34612345678', $state->tenant->getWhatsappPublicPhone());
    }

    public function testUpdatePersistsWhatsAppChannelFields(): void
    {
        $tenant = new Tenant('Negocio demo', 'negocio-demo');

        $tenants = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }

            public function findOneBy(array $criteria, array|null $orderBy = null): ?object
            {
                return null;
            }

            public function save(Tenant $tenant, bool $flush = true): void
            {
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $controller = new TenantController($tenants, $em);
        $controller->setContainer(new Container());

        $response = $controller->update($tenant->getId()->toRfc4122(), Request::create('/api/tenants/'.$tenant->getId()->toRfc4122(), 'PATCH', [], [], [], [], json_encode([
            'whatsappPhoneNumberId' => '123456789012345',
            'whatsappPublicPhone' => '34612345678',
        ])));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('123456789012345', $tenant->getWhatsappPhoneNumberId());
        self::assertSame('34612345678', $tenant->getWhatsappPublicPhone());
    }
}
