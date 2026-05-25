<?php

namespace App\Tests\Unit;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use App\Service\TenantAccessResolver;
use PHPUnit\Framework\TestCase;

final class TenantAccessResolverTest extends TestCase
{
    public function testSuperAdminBypassesMembershipChecks(): void
    {
        $tenantA = $this->tenant('Tech Investments');
        $tenantB = $this->tenant('Northwind');
        $user = new User('owner@example.com', ['super_admin'], 'Owner');
        $resolver = $this->resolver([$tenantA, $tenantB], []);

        self::assertTrue($resolver->isSuperAdmin($user));
        self::assertTrue($resolver->canAccessTenant($user, $tenantB));
        self::assertTrue($resolver->canManageTenant($user, $tenantB));
        self::assertSame([$tenantA, $tenantB], $resolver->accessibleTenants($user));
        self::assertSame($tenantB, $resolver->resolveActiveTenantForUser($user, $tenantB));
    }

    public function testMemberCanAccessAndManageAssignedTenant(): void
    {
        $tenant = $this->tenant('Tech Investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver([$tenant], [$this->membership($user, $tenant, 'manager')]);

        self::assertFalse($resolver->isSuperAdmin($user));
        self::assertTrue($resolver->canAccessTenant($user, $tenant));
        self::assertTrue($resolver->canManageTenant($user, $tenant));
        self::assertSame([$tenant], $resolver->accessibleTenants($user));
        self::assertSame($tenant, $resolver->resolveActiveTenantForUser($user, null));
    }

    public function testUserWithoutMembershipIsDeniedAccess(): void
    {
        $tenant = $this->tenant('Tech Investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver([$tenant], []);

        self::assertFalse($resolver->canAccessTenant($user, $tenant));
        self::assertFalse($resolver->canManageTenant($user, $tenant));
        self::assertSame([], $resolver->accessibleTenants($user));
        self::assertNull($resolver->resolveActiveTenantForUser($user, $tenant, false));
    }

    public function testResolveActiveTenantAutoSelectsSingleAccessibleTenant(): void
    {
        $tenant = $this->tenant('Tech Investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver([$tenant], [$this->membership($user, $tenant, 'viewer')]);

        self::assertSame($tenant, $resolver->resolveActiveTenantForUser($user, null));
    }

    /**
     * @param Tenant[] $tenants
     * @param TenantMembership[] $memberships
     */
    private function resolver(array $tenants, array $memberships): TenantAccessResolver
    {
        $tenantRepository = new class($tenants) extends TenantRepository {
            /**
             * @param Tenant[] $tenants
             */
            public function __construct(private array $tenants)
            {
            }

            public function findAllOrdered(): array
            {
                return $this->tenants;
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                foreach ($this->tenants as $tenant) {
                    if ($tenant->getId()->toRfc4122() === (string) $id) {
                        return $tenant;
                    }
                }

                return null;
            }
        };

        $membershipRepository = new class($memberships) extends TenantMembershipRepository {
            /**
             * @param TenantMembership[] $memberships
             */
            public function __construct(private array $memberships)
            {
            }

            public function findActiveByUser(User $user): array
            {
                return array_values(array_filter(
                    $this->memberships,
                    static fn (TenantMembership $membership) => $membership->getUser()->getId()->toRfc4122() === $user->getId()->toRfc4122()
                ));
            }

            public function findAccessibleTenantsByUser(User $user): array
            {
                return array_values(array_map(
                    static fn (TenantMembership $membership): Tenant => $membership->getTenant(),
                    $this->findActiveByUser($user)
                ));
            }

            public function findActiveByUserAndTenant(User $user, Tenant $tenant): ?TenantMembership
            {
                foreach ($this->findActiveByUser($user) as $membership) {
                    if ($membership->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()) {
                        return $membership;
                    }
                }

                return null;
            }

            public function hasActiveMembership(User $user, Tenant $tenant): bool
            {
                return $this->findActiveByUserAndTenant($user, $tenant) instanceof TenantMembership;
            }
        };

        return new TenantAccessResolver($tenantRepository, $membershipRepository);
    }

    private function tenant(string $name): Tenant
    {
        return new Tenant($name, strtolower(str_replace(' ', '-', $name)));
    }

    private function membership(User $user, Tenant $tenant, string $role): TenantMembership
    {
        return new TenantMembership($user, $tenant, $role);
    }
}
