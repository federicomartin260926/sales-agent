<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
use App\Repository\TenantRepository;
use Symfony\Component\Security\Core\User\UserInterface;

final class TenantAccessResolver
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly TenantMembershipRepository $memberships,
    ) {
    }

    public function isSuperAdmin(?UserInterface $user): bool
    {
        return $user instanceof User && in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true);
    }

    public function canAccessTenant(?UserInterface $user, Tenant $tenant): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (!$user instanceof User) {
            return false;
        }

        return $this->memberships->hasActiveMembership($user, $tenant);
    }

    public function canManageTenant(?UserInterface $user, Tenant $tenant): bool
    {
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (!$user instanceof User) {
            return false;
        }

        $membership = $this->memberships->findActiveByUserAndTenant($user, $tenant);
        if ($membership === null) {
            return false;
        }

        return $membership->canManageTenant();
    }

    /**
     * @return Tenant[]
     */
    public function accessibleTenants(?UserInterface $user): array
    {
        if ($this->isSuperAdmin($user)) {
            return $this->tenants->findAllOrdered();
        }

        if (!$user instanceof User) {
            return [];
        }

        return $this->memberships->findAccessibleTenantsByUser($user);
    }

    public function hasAccessibleTenants(?UserInterface $user): bool
    {
        return $this->accessibleTenants($user) !== [];
    }

    public function hasSingleAccessibleTenant(?UserInterface $user): bool
    {
        return count($this->accessibleTenants($user)) === 1;
    }

    public function resolveSingleAccessibleTenant(?UserInterface $user): ?Tenant
    {
        $tenants = $this->accessibleTenants($user);

        return count($tenants) === 1 ? $tenants[0] : null;
    }

    public function resolveActiveTenantForUser(?UserInterface $user, ?Tenant $activeTenant, bool $autoSelectSingle = true): ?Tenant
    {
        if ($this->isSuperAdmin($user)) {
            return $activeTenant;
        }

        if ($activeTenant instanceof Tenant && $this->canAccessTenant($user, $activeTenant)) {
            return $activeTenant;
        }

        if (!$autoSelectSingle) {
            return null;
        }

        return $this->resolveSingleAccessibleTenant($user);
    }
}
