<?php

namespace App\Tests\Unit;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;

final class ActiveTenantContextTest extends TestCase
{
    private function createTenantRepository(?Tenant $tenant = null): TenantRepository
    {
        return new class($tenant) extends TenantRepository {
            public function __construct(private readonly ?Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }

            public function findAllOrdered(): array
            {
                return $this->tenant instanceof Tenant ? [$this->tenant] : [];
            }
        };
    }

    private function createContext(TenantRepository $repository, Request $request): ActiveTenantContext
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new ActiveTenantContext($stack, $repository);
    }

    public function testSetAndResolveActiveTenantFromSession(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $repository = $this->createTenantRepository($tenant);
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $context = $this->createContext($repository, $request);

        $context->setActiveTenant($tenant);

        self::assertTrue($context->hasActiveTenant());
        self::assertSame($tenant->getId()->toRfc4122(), $context->getActiveTenantId());
        self::assertSame($tenant->getId()->toRfc4122(), $context->getActiveTenant()?->getId()->toRfc4122());
    }

    public function testClearsInactiveTenantFromSession(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tenant->setActive(false);
        $repository = $this->createTenantRepository($tenant);
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $context = $this->createContext($repository, $request);

        $context->setActiveTenant($tenant);
        $resolved = $context->getActiveTenant();

        self::assertNull($resolved);
        self::assertFalse($context->hasActiveTenant());
        self::assertNull($request->getSession()->get('backend.active_tenant_id'));
    }
}
