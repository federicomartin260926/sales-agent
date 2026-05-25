<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\AiUsageEvent;
use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use App\Entity\TenantAiUsagePolicy;
use App\Entity\User;
use App\Repository\AiUsageEventRepository;
use App\Repository\TenantAiTopUpRequestRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BackendUiSuperAdminTenantAiTest extends TestCase
{
    public function testSuperAdminCanViewTenantAiPage(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $policy = $this->policy($tenant, true, 1.0, 10.0, 'gpt-4.1-mini', 'gpt-4.1-nano', 'handoff_human');
        $event = $this->event($tenant);
        $requestEntity = $this->topUpRequest($tenant, 40.0, 'Solicitamos ampliación para el trimestre');
        $requestEntity->setRequestedBy($this->user('manager@example.com', ['manager'], 'Manager'));

        $controller = $this->controller($this->user('owner@example.com', ['super_admin'], 'Owner'));
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', 'GET');
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAi(
            $tenant->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant], $tenant),
            $this->policyRepository($policy),
            $this->eventsRepository([$event], ['estimated_cost_eur' => 0.25, 'total_tokens' => 130], ['estimated_cost_eur' => 1.25, 'total_tokens' => 530]),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('IA del tenant', $response->getContent());
        self::assertStringContainsString('Configuración técnica', $response->getContent());
        self::assertStringContainsString('Guardar configuración', $response->getContent());
        self::assertStringContainsString('Aprobar', $response->getContent());
        self::assertStringContainsString('Rechazar', $response->getContent());
        self::assertStringContainsString('gpt-4.1-mini', $response->getContent());
        self::assertStringContainsString('gpt-4.1-nano', $response->getContent());
        self::assertStringContainsString('0,25 €', $response->getContent());
        self::assertStringNotContainsString('openai_api_key', $response->getContent());
        self::assertStringNotContainsString('bearer', $response->getContent());
    }

    public function testNonSuperAdminCannotAccessTenantAiPage(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $controller = $this->controller($this->user('manager@example.com', ['manager'], 'Manager'));
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', 'GET');
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAi(
            $tenant->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant], $tenant),
            $this->policyRepository(),
            $this->eventsRepository(),
            $this->topUpRequestRepository()
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/dashboard', $response->headers->get('Location'));
    }

    public function testSuperAdminCanUpdateTenantAiPolicy(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $policyRepository = $this->policyRepository();
        $csrfTokenManager = $this->csrfTokenManager(true);
        $controller = $this->controller($this->user('owner@example.com', ['super_admin'], 'Owner'), null, null, $csrfTokenManager);
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', 'POST', [
            '_csrf_token' => 'token',
            'aiEnabled' => '1',
            'dailyCostLimitEur' => '2.50',
            'monthlyCostLimitEur' => '30.00',
            'defaultModel' => 'gpt-4.1-mini',
            'fallbackModel' => 'gpt-4.1-nano',
            'limitAction' => 'block',
        ]);
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAi(
            $tenant->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant], $tenant),
            $policyRepository,
            $this->eventsRepository(),
            $this->topUpRequestRepository()
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', $response->headers->get('Location'));
        self::assertNotEmpty($policyRepository->savedPolicies);
        $savedPolicy = $policyRepository->savedPolicies[array_key_last($policyRepository->savedPolicies)];
        self::assertTrue($savedPolicy->isAiEnabled());
        self::assertSame(2.5, $savedPolicy->getDailyCostLimitEur());
        self::assertSame(30.0, $savedPolicy->getMonthlyCostLimitEur());
        self::assertSame('gpt-4.1-mini', $savedPolicy->getDefaultModel());
        self::assertSame('gpt-4.1-nano', $savedPolicy->getFallbackModel());
        self::assertSame('block', $savedPolicy->getLimitAction());
    }

    public function testSuperAdminCanApproveTopUpRequest(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $requestEntity = $this->topUpRequest($tenant, 45.0, 'Ampliación para cerrar leads');
        $requestEntity->setRequestedBy($this->user('manager@example.com', ['manager'], 'Manager'));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::identicalTo($requestEntity));
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->controller($this->user('owner@example.com', ['super_admin'], 'Owner'), $entityManager, null, $this->csrfTokenManager(true));
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai/top-up-requests/'.$requestEntity->getId()->toRfc4122().'/approve', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAiTopUpRequestApprove(
            $tenant->getId()->toRfc4122(),
            $requestEntity->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant], $tenant),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', $response->headers->get('Location'));
        self::assertSame(TenantAiTopUpRequest::STATUS_APPROVED, $requestEntity->getStatus());
        self::assertNotNull($requestEntity->getResolvedAt());
        self::assertSame('owner@example.com', $requestEntity->getResolvedBy()?->getEmail());
        self::assertNotEmpty($requestEntity->getAdminNotes());
    }

    public function testSuperAdminCanRejectTopUpRequest(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $requestEntity = $this->topUpRequest($tenant, 45.0, 'Ampliación para cerrar leads');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::identicalTo($requestEntity));
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->controller($this->user('owner@example.com', ['super_admin'], 'Owner'), $entityManager, null, $this->csrfTokenManager(true));
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai/top-up-requests/'.$requestEntity->getId()->toRfc4122().'/reject', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAiTopUpRequestReject(
            $tenant->getId()->toRfc4122(),
            $requestEntity->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant], $tenant),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', $response->headers->get('Location'));
        self::assertSame(TenantAiTopUpRequest::STATUS_REJECTED, $requestEntity->getStatus());
        self::assertNotNull($requestEntity->getResolvedAt());
        self::assertSame('owner@example.com', $requestEntity->getResolvedBy()?->getEmail());
    }

    public function testCannotApproveTopUpRequestFromAnotherTenant(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $otherTenant = $this->tenant('Northwind', 'northwind');
        $requestEntity = $this->topUpRequest($otherTenant, 45.0, 'Ampliación para otro tenant');
        $controller = $this->controller($this->user('owner@example.com', ['super_admin'], 'Owner'));
        $request = Request::create('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai/top-up-requests/'.$requestEntity->getId()->toRfc4122().'/approve', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->superAdminTenantAiTopUpRequestApprove(
            $tenant->getId()->toRfc4122(),
            $requestEntity->getId()->toRfc4122(),
            $request,
            $this->tenantRepository([$tenant, $otherTenant], $tenant),
            $this->topUpRequestRepository([$requestEntity])
        );

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertSame(TenantAiTopUpRequest::STATUS_PENDING, $requestEntity->getStatus());
    }

    private function controller(
        User $user,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Environment $twig = null,
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

        $requestStack = new RequestStack();
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $requestStack->push($request);

        $activeTenantContext = new ActiveTenantContext($requestStack, $this->tenantRepository());

        return new BackendUiController(
            $security,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $passwordHasher ?? $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(RuntimeConfigurationService::class),
            $activeTenantContext,
            $twig ?? new Environment(new FilesystemLoader(__DIR__.'/../../templates'), ['cache' => false]),
            null,
            null,
            $csrfTokenManager,
            null,
        );
    }

    private function csrfTokenManager(bool $valid = true): CsrfTokenManagerInterface
    {
        $manager = $this->createStub(CsrfTokenManagerInterface::class);
        $manager->method('isTokenValid')->willReturn($valid);
        $manager->method('getToken')->willReturnCallback(static fn (string $id): CsrfToken => new CsrfToken($id, 'token'));

        return $manager;
    }

    /**
     * @param Tenant[] $orderedTenants
     */
    private function tenantRepository(array $orderedTenants = [], ?Tenant $foundTenant = null): TenantRepository
    {
        return new class($orderedTenants, $foundTenant) extends TenantRepository {
            public function __construct(private array $orderedTenants, private ?Tenant $foundTenant)
            {
            }

            public function findAllOrdered(): array
            {
                return $this->orderedTenants;
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                if ($this->foundTenant instanceof Tenant) {
                    return $this->foundTenant;
                }

                foreach ($this->orderedTenants as $tenant) {
                    if ($tenant->getId()->toRfc4122() === (string) $id) {
                        return $tenant;
                    }
                }

                return null;
            }
        };
    }

    private function policyRepository(?TenantAiUsagePolicy $foundPolicy = null): TenantAiUsagePolicyRepository
    {
        return new class($foundPolicy) extends TenantAiUsagePolicyRepository {
            /** @var TenantAiUsagePolicy[] */
            public array $savedPolicies = [];

            public function __construct(private ?TenantAiUsagePolicy $foundPolicy)
            {
            }

            public function findOneByTenant(Tenant $tenant): ?TenantAiUsagePolicy
            {
                return $this->foundPolicy;
            }

            public function save(TenantAiUsagePolicy $policy, bool $flush = true): void
            {
                $this->savedPolicies[] = $policy;
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
                $today = new \DateTimeImmutable('today');
                $month = new \DateTimeImmutable('first day of this month');
                if ($since->format('Y-m-d') === $today->format('Y-m-d')) {
                    return $this->todaySummary;
                }

                if ($since->format('Y-m-d') === $month->format('Y-m-d')) {
                    return $this->monthSummary;
                }

                return ['estimated_cost_eur' => 0.0, 'total_tokens' => 0];
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
            public function __construct(private array $requests)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                foreach ($this->requests as $request) {
                    if ($request->getId()->toRfc4122() === (string) $id) {
                        return $request;
                    }
                }

                return null;
            }

            public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
            {
                return array_values(array_filter(
                    $this->requests,
                    static fn (TenantAiTopUpRequest $request): bool => $request->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }
        };
    }

    private function tenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setActive(true);

        return $tenant;
    }

    private function user(string $email, array $roles, ?string $name = null): User
    {
        return new User($email, $roles, $name);
    }

    private function policy(
        Tenant $tenant,
        bool $enabled,
        ?float $dailyLimit,
        ?float $monthlyLimit,
        string $defaultModel,
        string $fallbackModel,
        string $limitAction,
    ): TenantAiUsagePolicy {
        $policy = new TenantAiUsagePolicy($tenant);
        $policy->setAiEnabled($enabled);
        $policy->setDailyCostLimitEur($dailyLimit);
        $policy->setMonthlyCostLimitEur($monthlyLimit);
        $policy->setDefaultModel($defaultModel);
        $policy->setFallbackModel($fallbackModel);
        $policy->setLimitAction($limitAction);

        return $policy;
    }

    private function event(Tenant $tenant): AiUsageEvent
    {
        $event = new AiUsageEvent($tenant);
        $event->setProvider('openai');
        $event->setModel('gpt-4.1-mini');
        $event->setInputTokens(100);
        $event->setOutputTokens(20);
        $event->setCachedTokens(10);
        $event->setTotalTokens(130);
        $event->setEstimatedCost(0.25);
        $event->setLatencyMs(123);

        return $event;
    }

    private function topUpRequest(Tenant $tenant, float $amount, string $message): TenantAiTopUpRequest
    {
        return new TenantAiTopUpRequest($tenant, $amount, $message);
    }
}
