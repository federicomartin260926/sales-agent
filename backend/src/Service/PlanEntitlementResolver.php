<?php

namespace App\Service;

use App\Entity\CommercialPlan;
use App\Entity\Tenant;

final class PlanEntitlementResolver
{
    /**
     * @return array{
     *     plan: CommercialPlan|null,
     *     features: array<string, mixed>,
     *     limits: array<string, mixed>,
     *     subscriptionStatus: string|null,
     *     currentPeriodStart: \DateTimeImmutable|null,
     *     currentPeriodEnd: \DateTimeImmutable|null
     * }
     */
    public function resolve(Tenant $tenant): array
    {
        $plan = $tenant->getCommercialPlan();

        if (!$plan instanceof CommercialPlan) {
            return [
                'plan' => null,
                'features' => [],
                'limits' => [],
                'subscriptionStatus' => $tenant->getSubscriptionStatus(),
                'currentPeriodStart' => $tenant->getCurrentPeriodStart(),
                'currentPeriodEnd' => $tenant->getCurrentPeriodEnd(),
            ];
        }

        return [
            'plan' => $plan,
            'features' => $plan->getFeatures(),
            'limits' => $plan->getLimits(),
            'subscriptionStatus' => $tenant->getSubscriptionStatus(),
            'currentPeriodStart' => $tenant->getCurrentPeriodStart(),
            'currentPeriodEnd' => $tenant->getCurrentPeriodEnd(),
        ];
    }
}
