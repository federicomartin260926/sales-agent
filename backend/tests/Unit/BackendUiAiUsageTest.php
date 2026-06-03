<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\AiUsageEvent;
use App\Entity\CommercialPlan;
use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use App\Entity\TenantAiUsagePolicy;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\AiUsageEventRepository;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeConfigurationService;
use App\Service\TenantAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BackendUiAiUsageTest extends TestCase
{
    public function testManagerCanViewUsageDashboardAndRequestForm(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);
        $policy = $this->policy($tenant, true, 1.0, 10.0);
        $event = new AiUsageEvent($tenant);
        $event->setProvider('openai');
        $event->setModel('gpt-4.1-mini');
        $event->setInputTokens(100);
        $event->setOutputTokens(20);
        $event->setCachedTokens(10);
        $event->setTotalTokens(130);
        $event->setEstimatedCost(0.25);
        $event->setLatencyMs(123);
        $requestEntity = $this->topUpRequest($tenant, 25.0, 'Necesitamos más cuota');
        $requestEntity->setRequestedBy($user);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage(
            $request,
            $this->policyRepository($policy),
            $this->eventsRepository([$event], ['estimated_cost_eur' => 0.25, 'total_tokens' => 130], ['estimated_cost_eur' => 0.25, 'total_tokens' => 130]),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('Tokens procesados hoy', $response->getContent());
        self::assertStringContainsString('Plan mensual base', $response->getContent());
        self::assertStringContainsString('Recargas aprobadas este mes', $response->getContent());
        self::assertStringContainsString('Cupo efectivo este mes', $response->getContent());
        self::assertStringContainsString('Límite diario base', $response->getContent());
        self::assertStringContainsString('Periodo actual', $response->getContent());
        self::assertStringContainsString('Ampliación solicitada', $response->getContent());
        self::assertStringContainsString('Tokens solicitados', $response->getContent());
        self::assertStringContainsString('/backend/ai-usage/top-up-requests', $response->getContent());
        self::assertStringContainsString('Pendiente', $response->getContent());
        self::assertStringContainsString('Audio entrante', $response->getContent());
        self::assertStringContainsString('Límite máximo de audio', $response->getContent());
        self::assertStringNotContainsString('default_model', $response->getContent());
        self::assertStringNotContainsString('fallback_model', $response->getContent());
    }

    public function testManagerUsageDashboardShowsCommercialPlanBaseAndEffectiveLimit(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $plan = new CommercialPlan('growth', 'Growth');
        $plan->setLimits(['included_monthly_ai_tokens' => 10000000]);
        $tenant->setCommercialPlan($plan);

        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);
        $policy = $this->policy($tenant, true, 1.0, 10.0);
        $currentPeriodKey = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m');
        $requestEntity = $this->topUpRequest($tenant, 25.0, 'Ampliación puntual');
        $requestEntity->approve($user, 2000000, $currentPeriodKey);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage(
            $request,
            $this->policyRepository($policy),
            $this->eventsRepository([], ['estimated_cost_eur' => 0.0, 'total_tokens' => 0], ['estimated_cost_eur' => 0.0, 'total_tokens' => 0]),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Plan comercial', $response->getContent());
        self::assertStringContainsString('Growth', $response->getContent());
        self::assertStringContainsString('Tokens incluidos por plan', $response->getContent());
        self::assertMatchesRegularExpression('/Tokens incluidos por plan<\/div>\s*<div class="metric-value">10M<\/div>/', $response->getContent());
        self::assertStringContainsString('Tokens extra aprobados este mes', $response->getContent());
        self::assertStringContainsString('2M', $response->getContent());
        self::assertMatchesRegularExpression('/Cupo efectivo este mes<\/div>\s*<div class="metric-value">12M<\/div>/', $response->getContent());
        self::assertStringContainsString('Plan comercial asignado: Growth (growth).', $response->getContent());
    }

    public function testUsageDashboardKeepsBaseLimitsStableWhenConsumptionArrives(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);
        $policy = $this->policy($tenant, true, 0.07, 0.35);
        $policy->setDefaultModel('gpt-4.1-mini');
        $policy->setFallbackModel('gpt-4.1-mini');

        $event = new AiUsageEvent($tenant);
        $event->setProvider('openai');
        $event->setModel('gpt-4.1-mini');
        $event->setInputTokens(4200);
        $event->setOutputTokens(664);
        $event->setCachedTokens(0);
        $event->setTotalTokens(4864);
        $event->setEstimatedCost(0.003405);
        $event->setLatencyMs(111);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage(
            $request,
            $this->policyRepository($policy),
            $this->eventsRepository([$event], ['estimated_cost_eur' => 0.003405, 'total_tokens' => 4864], ['estimated_cost_eur' => 0.003405, 'total_tokens' => 4864]),
            $this->topUpRequestRepository([])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertMatchesRegularExpression('/Límite diario base<\/div>\s*<div class="metric-value">0,1M<\/div>/', $response->getContent());
        self::assertMatchesRegularExpression('/Plan mensual base<\/div>\s*<div class="metric-value">0,5M<\/div>/', $response->getContent());
        self::assertMatchesRegularExpression('/Cupo efectivo este mes<\/div>\s*<div class="metric-value">0,5M<\/div>/', $response->getContent());
        self::assertStringContainsString('0,004864M', $response->getContent());
        self::assertStringContainsString('0,095136M', $response->getContent());
        self::assertStringContainsString('0,495136M', $response->getContent());
        self::assertStringContainsString('5%', $response->getContent());
        self::assertStringContainsString('1%', $response->getContent());
    }

    public function testNoAccessibleTenantShowsSelectionRequired(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [], []);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant]);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage($request, $this->policyRepository(), $this->eventsRepository(), $this->topUpRequestRepository());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio antes de consultar consumo, límites o solicitudes de ampliación.', $response->getContent());
        self::assertStringNotContainsString('Importe solicitado (€)', $response->getContent());
        self::assertStringNotContainsString('Tokens solicitados', $response->getContent());
    }

    public function testManagerSeesApprovedTopUpRequestInUsageDashboard(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);
        $requestEntity = $this->topUpRequest($tenant, 25.0, 'Necesitamos más cuota');
        $requestEntity->setRequestedBy($user);
        $requestEntity->approve($user, 30, (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m'));

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage($request, $this->policyRepository($this->policy($tenant)), $this->eventsRepository(), $this->topUpRequestRepository([$requestEntity]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Aprobada', $response->getContent());
        self::assertStringContainsString('Aprobados: 0,00003M', $response->getContent());
        self::assertStringContainsString('Recargas aprobadas este mes', $response->getContent());
    }

    public function testManagerDashboardCountsOnlyCurrentPeriodTopUps(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);
        $currentPeriodKey = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Madrid')))->format('Y-m');
        $previousPeriodKey = (new \DateTimeImmutable('first day of last month', new \DateTimeZone('Europe/Madrid')))->format('Y-m');
        $currentTopUp = $this->topUpRequest($tenant, 31.0, 'Recarga del periodo actual');
        $currentTopUp->approve($user, 31, $currentPeriodKey);
        $previousTopUp = $this->topUpRequest($tenant, 17.0, 'Recarga antigua');
        $previousTopUp->approve($user, 17, $previousPeriodKey);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage(
            $request,
            $this->policyRepository($this->policy($tenant)),
            $this->eventsRepository([], ['estimated_cost_eur' => 0.25, 'total_tokens' => 130], ['estimated_cost_eur' => 1.25, 'total_tokens' => 530]),
            $this->topUpRequestRepository([$currentTopUp, $previousTopUp])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertMatchesRegularExpression('/Recargas aprobadas este mes<\\/div>\\s*<div class="metric-value">0,000031M<\\/div>/', $response->getContent());
        self::assertStringContainsString('Cupo efectivo este mes', $response->getContent());
    }

    public function testTamperedActiveTenantResolvesToAccessibleTenant(): void
    {
        $tenantA = $this->tenant('Tech Investments', 'tech-investments');
        $tenantB = $this->tenant('Northwind', 'northwind');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenantA], [$this->membership($user, $tenantA, 'manager')]);

        $request = Request::create('/backend/ai-usage', 'GET');
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenantA, $tenantB], $tenantB);

        $controller = $this->controller($user, $context, $resolver);
        $response = $controller->aiUsage($request, $this->policyRepository($this->policy($tenantA)), $this->eventsRepository(), $this->topUpRequestRepository());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Tech Investments', $response->getContent());
        self::assertStringNotContainsString('Northwind', $response->getContent());
    }

    public function testTopUpRequestCreatesPendingRequestAndRedirects(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenant], [$this->membership($user, $tenant, 'manager')]);

        $request = Request::create('/backend/ai-usage/top-up-requests', 'POST', [
            '_csrf_token' => 'token',
            'requestedTokens' => '1000000',
            'message' => 'Necesitamos más cuota para el mes',
        ]);
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant], $tenant);
        $topUpRepository = $this->topUpRequestRepository([]);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $csrfTokenManager->method('getToken')->willReturnCallback(static fn (string $id): \Symfony\Component\Security\Csrf\CsrfToken => new \Symfony\Component\Security\Csrf\CsrfToken($id, 'token'));

        $controller = $this->controller($user, $context, $resolver, null, null, $csrfTokenManager);
        $response = $controller->aiUsageTopUpRequestCreate(
            $request,
            $this->policyRepository($this->policy($tenant)),
            $this->eventsRepository(),
            $topUpRepository
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/ai-usage', $response->headers->get('Location'));
        self::assertCount(1, $topUpRepository->saved);
        self::assertInstanceOf(TenantAiTopUpRequest::class, $topUpRepository->saved[0]);
        self::assertSame(TenantAiTopUpRequest::STATUS_PENDING, $topUpRepository->saved[0]->getStatus());
        self::assertSame(1000000.0, $topUpRepository->saved[0]->getRequestedAmountEur());
        self::assertSame('Necesitamos más cuota para el mes', $topUpRepository->saved[0]->getMessage());
        self::assertSame($user->getEmail(), $topUpRepository->saved[0]->getRequestedBy()?->getEmail());
    }

    public function testTopUpRequestWithoutAccessibleTenantIsBlocked(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [], []);

        $request = Request::create('/backend/ai-usage/top-up-requests', 'POST', [
            '_csrf_token' => 'token',
            'requestedTokens' => '1000000',
            'message' => 'Necesitamos más cuota para el mes',
        ]);
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenant]);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->controller($user, $context, $resolver, null, null, $csrfTokenManager);
        $response = $controller->aiUsageTopUpRequestCreate($request, $this->policyRepository(), $this->eventsRepository(), $this->topUpRequestRepository());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio antes de enviar una solicitud de ampliación.', $response->getContent());
    }

    private function controller(
        User $user,
        ActiveTenantContext $context,
        ?TenantAccessResolver $resolver = null,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ): BackendUiController {
        $security = $this->createStub(Security::class);
        $roles = $user->getRoles();
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static function (string $role) use ($roles): bool {
            return match ($role) {
                'ROLE_SUPER_ADMIN' => in_array('ROLE_SUPER_ADMIN', $roles, true),
                'ROLE_ADMIN' => in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true),
                'ROLE_MANAGER' => in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true),
                'ROLE_AGENT' => in_array('ROLE_AGENT', $roles, true) || in_array('ROLE_MANAGER', $roles, true) || in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_SUPER_ADMIN', $roles, true),
                default => in_array($role, $roles, true),
            };
        });

        return new BackendUiController(
            $security,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $passwordHasher ?? $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(RuntimeConfigurationService::class),
            $context,
            new Environment(new FilesystemLoader(__DIR__.'/../../templates'), ['cache' => false]),
            null,
            null,
            null,
            $csrfTokenManager,
            $resolver,
            null,
        );
    }

    /**
     * @param Tenant[] $tenants
     */
    private function activeTenantContext(Request $request, array $tenants, ?Tenant $activeTenant = null): ActiveTenantContext
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $repository = $this->tenantRepository($tenants);
        $context = new ActiveTenantContext($requestStack, $repository);
        if ($activeTenant instanceof Tenant) {
            $context->setActiveTenant($activeTenant);
        }

        return $context;
    }

    /**
     * @param Tenant[] $tenants
     */
    private function tenantRepository(array $tenants): TenantRepository
    {
        return new class($tenants) extends TenantRepository {
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
    }

    private function resolver(User $user, array $accessibleTenants, array $memberships): TenantAccessResolver
    {
        return new TenantAccessResolver(
            $this->tenantRepository($accessibleTenants),
            new class($user, $memberships) extends \App\Repository\TenantMembershipRepository {
                /**
                 * @param TenantMembership[] $memberships
                 */
                public function __construct(private User $user, private array $memberships)
                {
                }

                public function findActiveByUser(User $user): array
                {
                    if ($user->getId()->toRfc4122() !== $this->user->getId()->toRfc4122()) {
                        return [];
                    }

                    return $this->memberships;
                }

                public function findAccessibleTenantsByUser(User $user): array
                {
                    return array_map(static fn (TenantMembership $membership): Tenant => $membership->getTenant(), $this->findActiveByUser($user));
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
            }
        );
    }

    private function membership(User $user, Tenant $tenant, string $role): TenantMembership
    {
        return new TenantMembership($user, $tenant, $role);
    }

    private function policy(Tenant $tenant, bool $enabled = true, ?float $daily = null, ?float $monthly = null): TenantAiUsagePolicy
    {
        $policy = new TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled($enabled);
        $policy->setDailyCostLimitEur($daily);
        $policy->setMonthlyCostLimitEur($monthly);

        return $policy;
    }

    /**
     * @param TenantAiUsagePolicy|null $foundPolicy
     */
    private function policyRepository(?TenantAiUsagePolicy $foundPolicy = null): TenantAiUsagePolicyRepository
    {
        return new class($foundPolicy) extends TenantAiUsagePolicyRepository {
            public function __construct(private ?TenantAiUsagePolicy $foundPolicy)
            {
            }

            public function findOneByTenant(Tenant $tenant): ?TenantAiUsagePolicy
            {
                return $this->foundPolicy;
            }
        };
    }

    /**
     * @param AiUsageEvent[] $recentEvents
     */
    private function eventsRepository(array $recentEvents = [], array $todaySummary = [], array $monthSummary = []): AiUsageEventRepository
    {
        return new class($recentEvents, $todaySummary, $monthSummary) extends AiUsageEventRepository {
            /**
             * @param AiUsageEvent[] $recentEvents
             */
            public function __construct(
                private array $recentEvents,
                private array $todaySummary,
                private array $monthSummary,
            ) {
            }

            public function summarizeSince(Tenant $tenant, \DateTimeImmutable $since): array
            {
                if ($since->format('d') === '01') {
                    return $this->monthSummary ?: ['estimated_cost_eur' => 0.0, 'total_tokens' => 0];
                }

                return $this->todaySummary ?: ['estimated_cost_eur' => 0.0, 'total_tokens' => 0];
            }

            public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
            {
                return array_slice($this->recentEvents, 0, max(1, $limit));
            }
        };
    }

    /**
     * @param TenantAiTopUpRequest[] $requests
     */
    private function topUpRequestRepository(array $requests = []): TenantAiTopUpRequestRepository
    {
        return new class($requests) extends TenantAiTopUpRequestRepository {
            /**
             * @param TenantAiTopUpRequest[] $requests
             */
            public array $saved = [];

            public function __construct(private array $requests)
            {
            }

            public function save(TenantAiTopUpRequest $request, bool $flush = true): void
            {
                $this->saved[] = $request;
            }

            public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
            {
                return array_slice($this->requests, 0, max(1, $limit));
            }

            public function findApprovedByTenantAndPeriod(Tenant $tenant, string $periodKey): array
            {
                return array_values(array_filter(
                    $this->requests,
                    static function (TenantAiTopUpRequest $request) use ($tenant, $periodKey): bool {
                        if ($request->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                            return false;
                        }

                        if ($request->getStatus() !== TenantAiTopUpRequest::STATUS_APPROVED) {
                            return false;
                        }

                        $approvedPeriodKey = $request->getApprovedPeriodKey();
                        if ($approvedPeriodKey !== null && $approvedPeriodKey !== '') {
                            return $approvedPeriodKey === $periodKey;
                        }

                        return $request->getResolvedAt()?->format('Y-m') === $periodKey;
                    }
                ));
            }

            public function sumApprovedTokensByTenantAndPeriod(Tenant $tenant, string $periodKey): int
            {
                $total = 0;
                foreach ($this->findApprovedByTenantAndPeriod($tenant, $periodKey) as $request) {
                    $approvedTokens = $request->getApprovedTokens();
                    if ($approvedTokens === null) {
                        $approvedTokens = max(0, (int) round($request->getRequestedAmountEur()));
                    }

                    $total += max(0, $approvedTokens);
                }

                return $total;
            }

            public function findLegacyApprovedWithoutPeriodByTenant(Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->requests,
                    static function (TenantAiTopUpRequest $request) use ($tenant): bool {
                        if ($request->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                            return false;
                        }

                        return $request->getStatus() === TenantAiTopUpRequest::STATUS_APPROVED && $request->getApprovedPeriodKey() === null;
                    }
                ));
            }
        };
    }

    private function topUpRequest(Tenant $tenant, float $amount, string $message): TenantAiTopUpRequest
    {
        return new TenantAiTopUpRequest($tenant, $amount, $message);
    }

    private function tenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setActive(true);

        return $tenant;
    }
}
