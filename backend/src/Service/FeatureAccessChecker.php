<?php

namespace App\Service;

use App\Entity\Tenant;

final class FeatureAccessChecker
{
    public function __construct(
        private readonly PlanEntitlementResolver $resolver,
    ) {
    }

    public function isFeatureEnabled(Tenant $tenant, string $feature): bool
    {
        $features = $this->resolver->resolve($tenant)['features'] ?? [];
        $value = is_array($features) ? ($features[$feature] ?? null) : null;

        return $this->truthy($value);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return false;
        }

        return !in_array($normalized, ['0', 'false', 'no', 'off', 'disabled', 'none', 'null'], true);
    }
}
