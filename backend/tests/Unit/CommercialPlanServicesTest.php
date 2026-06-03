<?php

namespace App\Tests\Unit;

use App\Entity\CommercialPlan;
use App\Entity\Tenant;
use App\Service\FeatureAccessChecker;
use App\Service\PlanEntitlementResolver;
use App\Service\PlanLimitResolver;
use PHPUnit\Framework\TestCase;

final class CommercialPlanServicesTest extends TestCase
{
    public function testResolverReturnsPlanEntitlements(): void
    {
        $tenant = $this->tenant();
        $plan = $this->plan();
        $tenant->setCommercialPlan($plan);
        $tenant->setSubscriptionStatus('active');

        $resolver = new PlanEntitlementResolver();
        $result = $resolver->resolve($tenant);

        self::assertSame($plan, $result['plan']);
        self::assertSame(['ai_agent' => true, 'priority_support' => 'yes'], $result['features']);
        self::assertSame(['monthly_conversations' => 3000], $result['limits']);
        self::assertSame('active', $result['subscriptionStatus']);
        self::assertNull($result['currentPeriodStart']);
        self::assertNull($result['currentPeriodEnd']);
    }

    public function testFeatureAccessCheckerUsesTruthyFeatureFlags(): void
    {
        $tenant = $this->tenant();
        $tenant->setCommercialPlan($this->plan([
            'ai_agent' => 'true',
            'human_handoff' => 'disabled',
            'priority_support' => 1,
        ]));

        $checker = new FeatureAccessChecker(new PlanEntitlementResolver());

        self::assertTrue($checker->isFeatureEnabled($tenant, 'ai_agent'));
        self::assertFalse($checker->isFeatureEnabled($tenant, 'human_handoff'));
        self::assertTrue($checker->isFeatureEnabled($tenant, 'priority_support'));
        self::assertFalse($checker->isFeatureEnabled($tenant, 'missing_feature'));
    }

    public function testPlanLimitResolverReturnsConfiguredLimit(): void
    {
        $tenant = $this->tenant();
        $tenant->setCommercialPlan($this->plan(null, [
            'monthly_conversations' => 3000,
            'whatsapp_numbers' => 2,
        ]));

        $resolver = new PlanLimitResolver(new PlanEntitlementResolver());

        self::assertSame(3000, $resolver->getLimit($tenant, 'monthly_conversations'));
        self::assertSame(2, $resolver->getLimit($tenant, 'whatsapp_numbers'));
        self::assertNull($resolver->getLimit($tenant, 'missing_limit'));
    }

    /**
     * @param array<string, mixed>|null $features
     * @param array<string, mixed>|null $limits
     */
    private function plan(?array $features = null, ?array $limits = null): CommercialPlan
    {
        $plan = new CommercialPlan('starter', 'Starter');
        $plan->setFeatures($features ?? [
            'ai_agent' => true,
            'priority_support' => 'yes',
        ]);
        $plan->setLimits($limits ?? [
            'monthly_conversations' => 3000,
        ]);

        return $plan;
    }

    private function tenant(): Tenant
    {
        $tenant = new Tenant('Negocio demo', 'negocio-demo');
        $tenant->setActive(true);

        return $tenant;
    }
}
