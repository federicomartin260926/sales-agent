<?php

namespace App\Service;

use App\Entity\Tenant;

final class PlanLimitResolver
{
    public function __construct(
        private readonly PlanEntitlementResolver $resolver,
    ) {
    }

    public function getLimit(Tenant $tenant, string $limitKey): int|float|string|null
    {
        $limits = $this->resolver->resolve($tenant)['limits'] ?? [];
        if (!is_array($limits)) {
            return null;
        }

        return $limits[$limitKey] ?? null;
    }
}
