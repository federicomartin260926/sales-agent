<?php

namespace App\Tests\Unit;

use App\Entity\CommercialPlan;
use App\Entity\EntryPoint;
use App\Entity\ExternalTool;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Service\FeatureAccessChecker;
use App\Service\PlanEntitlementResolver;
use App\Service\PlanLimitResolver;
use App\Service\PlanUsageGuard;
use App\Exception\PlanLimitExceededException;
use App\Repository\EntryPointRepository;
use App\Repository\ExternalToolRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
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

    public function testPlanUsageGuardBlocksResourceCreationWhenLimitIsReached(): void
    {
        $tenant = $this->tenant();
        $tenant->setCommercialPlan($this->plan(null, [
            'products' => 1,
            'playbooks' => 1,
            'entry_points' => 1,
            'mcp_tools' => 1,
        ]));

        $product = new Product($tenant, 'Producto');
        $playbook = new Playbook($tenant, 'Guía');
        $entryPoint = new EntryPoint($product, 'code-1', 'Entrada');
        $tool = new ExternalTool($tenant, 'MCP', 'mcp_remote', 'openai_remote_mcp');

        $guard = $this->guard([$product], [$playbook], [$entryPoint], [$tool]);

        self::assertFalse($guard->canUseAudioTranscription($tenant));
        self::assertFalse($guard->canUseMcpTools($tenant));

        self::expectException(PlanLimitExceededException::class);
        self::expectExceptionMessage('Tu plan Starter ya alcanzó el límite de productos / servicios (1/1).');
        $guard->assertCanCreateProduct($tenant);
    }

    public function testPlanUsageGuardBlocksWhenTenantHasNoCommercialPlan(): void
    {
        $tenant = $this->tenant();

        $guard = $this->guard([], [], [], []);

        self::assertFalse($guard->canUseAudioTranscription($tenant));
        self::assertFalse($guard->canUseMcpTools($tenant));

        self::expectException(PlanLimitExceededException::class);
        self::expectExceptionMessage('Este negocio no tiene un plan comercial asignado.');
        $guard->assertCanCreatePlaybook($tenant);
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

    private function guard(array $products, array $playbooks, array $entryPoints, array $externalTools): PlanUsageGuard
    {
        return new PlanUsageGuard(
            new FeatureAccessChecker(new PlanEntitlementResolver()),
            new PlanLimitResolver(new PlanEntitlementResolver()),
            new class($products) extends ProductRepository {
                public function __construct(private array $products)
                {
                }

                public function findByTenantOrdered(Tenant $tenant): array
                {
                    return array_values(array_filter($this->products, static fn (Product $product): bool => $product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()));
                }
            },
            new class($playbooks) extends PlaybookRepository {
                public function __construct(private array $playbooks)
                {
                }

                public function findByTenantOrdered(Tenant $tenant): array
                {
                    return array_values(array_filter($this->playbooks, static fn (Playbook $playbook): bool => $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()));
                }
            },
            new class($entryPoints) extends EntryPointRepository {
                public function __construct(private array $entryPoints)
                {
                }

                public function findByTenantOrdered(Tenant $tenant): array
                {
                    return array_values(array_filter($this->entryPoints, static fn (EntryPoint $entryPoint): bool => $entryPoint->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()));
                }
            },
            new class($externalTools) extends ExternalToolRepository {
                public function __construct(private array $externalTools)
                {
                }

                public function findByTenantOrdered(Tenant $tenant): array
                {
                    return array_values(array_filter($this->externalTools, static fn (ExternalTool $tool): bool => $tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()));
                }
            }
        );
    }
}
