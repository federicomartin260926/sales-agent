<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\EntryPoint;
use App\Entity\AiUsageEvent;
use App\Entity\ExternalTool;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\TenantAiUsagePolicy;
use App\Entity\User;
use App\Repository\AiUsageEventRepository;
use App\Repository\EntryPointRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantAiUsagePolicyRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeConfigurationService;
use App\Service\ProductCatalogImportService;
use App\Service\ActiveTenantContext;
use App\Service\TenantAccessResolver;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BackendUiControllerTest extends TestCase
{
    private function createController(
        Security $security,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?ProductCatalogImportService $productCatalogImportService = null,
        ?RuntimeConfigurationService $runtimeConfigurationService = null,
        ?Environment $twig = null,
        ?ActiveTenantContext $activeTenantContext = null,
        ?TenantAccessResolver $tenantAccessResolver = null,
    ): BackendUiController {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $passwordHasher ??= $this->createStub(UserPasswordHasherInterface::class);
        $csrfTokenManager ??= $this->createStub(CsrfTokenManagerInterface::class);
        $runtimeConfigurationService ??= $this->createStub(RuntimeConfigurationService::class);
        $twig ??= $this->createTwigEnvironment();
        $activeTenantContext ??= new ActiveTenantContext(new RequestStack(), $this->createTenantRepositoryFake());

        return new BackendUiController($security, $entityManager, $passwordHasher, $runtimeConfigurationService, $activeTenantContext, $twig, null, $productCatalogImportService, $csrfTokenManager, $tenantAccessResolver);
    }

    private function createActiveTenantContext(?Tenant $tenant = null): ActiveTenantContext
    {
        $requestStack = new RequestStack();
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $requestStack->push($request);

        $repository = $this->createTenantRepositoryFake($tenant instanceof Tenant ? [$tenant] : [], $tenant);
        $context = new ActiveTenantContext($requestStack, $repository);

        if ($tenant instanceof Tenant) {
            $context->setActiveTenant($tenant);
        }

        return $context;
    }

    private function createActiveTenantContextForRequest(Tenant $tenant, Request $request): ActiveTenantContext
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $repository = $this->createTenantRepositoryFake([$tenant], $tenant);
        return new ActiveTenantContext($requestStack, $repository);
    }

    private function createControllerForActiveTenant(
        Security $security,
        Tenant $tenant,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?ProductCatalogImportService $productCatalogImportService = null,
        ?RuntimeConfigurationService $runtimeConfigurationService = null,
        ?Environment $twig = null,
        ?TenantAccessResolver $tenantAccessResolver = null,
    ): BackendUiController {
        return $this->createController(
            $security,
            $entityManager,
            $passwordHasher,
            $csrfTokenManager,
            $productCatalogImportService,
            $runtimeConfigurationService,
            $twig,
            $this->createActiveTenantContext($tenant),
            $tenantAccessResolver,
        );
    }

    private function createTwigEnvironment(): Environment
    {
        $loader = new FilesystemLoader(__DIR__.'/../../templates');

        return new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    private function createAuthenticatedUser(string $email = 'admin@example.com', array $roles = ['admin'], ?string $name = null): User
    {
        return new User($email, $roles, $name);
    }

    /**
     * @param Tenant[] $orderedTenants
     */
    private function createTenantRepositoryFake(array $orderedTenants = [], ?Tenant $foundTenant = null): TenantRepository
    {
        return new class($orderedTenants, $foundTenant) extends TenantRepository {
            /**
             * @param Tenant[] $orderedTenants
             */
            public function __construct(
                private array $orderedTenants,
                private ?Tenant $foundTenant,
            ) {
            }

            /**
             * @return Tenant[]
             */
            public function findAllOrdered(): array
            {
                return $this->orderedTenants;
            }

            public function findOneBy(array $criteria, ?array $orderBy = null): ?object
            {
                foreach ($this->orderedTenants as $tenant) {
                    if ($this->matchesCriteria($tenant, $criteria)) {
                        return $tenant;
                    }
                }

                return null;
            }

            /**
             * @return Tenant[]
             */
            public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?int $offset = null): array
            {
                $matches = array_values(array_filter(
                    $this->orderedTenants,
                    fn (Tenant $tenant): bool => $this->matchesCriteria($tenant, $criteria)
                ));

                if ($offset !== null || $limit !== null) {
                    $matches = array_slice(
                        $matches,
                        $offset ?? 0,
                        $limit ?? null
                    );
                }

                return $matches;
            }

            /**
             * @return Tenant[]
             */
            public function findByWhatsappPhoneNumberId(string $whatsappPhoneNumberId): array
            {
                return array_values(array_filter(
                    $this->orderedTenants,
                    static fn (Tenant $tenant): bool => $tenant->getWhatsappPhoneNumberId() === $whatsappPhoneNumberId
                ));
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundTenant;
            }

            /**
             * @param array<string, mixed> $criteria
             */
            private function matchesCriteria(Tenant $tenant, array $criteria): bool
            {
                foreach ($criteria as $field => $expected) {
                    if ($field === 'slug' && $tenant->getSlug() !== $expected) {
                        return false;
                    }

                    if ($field === 'whatsappPhoneNumberId' && $tenant->getWhatsappPhoneNumberId() !== $expected) {
                        return false;
                    }

                    if ($field === 'id' && $tenant->getId()->toRfc4122() !== (string) $expected) {
                        return false;
                    }
                }

                return true;
            }
        };
    }

    /**
     * @param Product[] $orderedProducts
     */
    private function createProductRepositoryFake(array $orderedProducts = [], ?Product $foundProduct = null): ProductRepository
    {
        return new class($orderedProducts, $foundProduct) extends ProductRepository {
            /**
             * @param Product[] $orderedProducts
             */
            public function __construct(
                private array $orderedProducts,
                private ?Product $foundProduct,
            ) {
            }

            /**
             * @return Product[]
             */
            public function findAllOrdered(): array
            {
                return $this->orderedProducts;
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->orderedProducts,
                    static fn (Product $product): bool => $product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundProduct;
            }

            public function findOneByTenantAndSlug(Tenant $tenant, string $slug): ?Product
            {
                foreach ($this->orderedProducts as $product) {
                    if ($product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $product->getSlug() === $slug) {
                        return $product;
                    }
                }

                return null;
            }

            public function findOneByExternalIdentity(Tenant $tenant, string $source, string $reference): ?Product
            {
                foreach ($this->orderedProducts as $product) {
                    if ($product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                        && $product->getExternalSource() === $source
                        && $product->getExternalReference() === $reference
                    ) {
                        return $product;
                    }
                }

                return null;
            }
        };
    }

    /**
     * @param ExternalTool[] $orderedTools
     */
    private function createExternalToolRepositoryFake(array $orderedTools = []): ExternalToolRepository
    {
        return new class($orderedTools) extends ExternalToolRepository {
            /**
             * @param ExternalTool[] $orderedTools
             */
            public function __construct(private array $orderedTools)
            {
            }

            /**
             * @return ExternalTool[]
             */
            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->orderedTools,
                    static fn (ExternalTool $tool) => $tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function findAllOrdered(): array
            {
                return $this->orderedTools;
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                foreach ($this->orderedTools as $tool) {
                    if ($tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $tool->isRuntimeDefault()) {
                        return $tool;
                    }
                }

                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->orderedTools,
                    static fn (ExternalTool $tool) => $tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $tool->isActive() && $tool->getType() === 'mcp_remote'
                ));
            }
        };
    }

    private function createTenantAiUsagePolicyRepositoryFake(?TenantAiUsagePolicy $foundPolicy = null): TenantAiUsagePolicyRepository
    {
        return new class($foundPolicy) extends TenantAiUsagePolicyRepository {
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
                $this->foundPolicy = $policy;
            }
        };
    }

    /**
     * @param AiUsageEvent[] $recentEvents
     */
    private function createAiUsageEventRepositoryFake(array $recentEvents = [], array $todaySummary = [], array $monthSummary = []): AiUsageEventRepository
    {
        return new class($recentEvents, $todaySummary, $monthSummary) extends AiUsageEventRepository {
            /**
             * @param AiUsageEvent[] $recentEvents
             * @param array{estimated_cost_eur?: float, input_tokens?: int, output_tokens?: int, cached_tokens?: int, total_tokens?: int} $todaySummary
             * @param array{estimated_cost_eur?: float, input_tokens?: int, output_tokens?: int, cached_tokens?: int, total_tokens?: int} $monthSummary
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
                    return array_merge([
                        'estimated_cost_eur' => 0.0,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'cached_tokens' => 0,
                        'total_tokens' => 0,
                    ], $this->todaySummary);
                }

                if ($since->format('Y-m-d') === $month->format('Y-m-d')) {
                    return array_merge([
                        'estimated_cost_eur' => 0.0,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'cached_tokens' => 0,
                        'total_tokens' => 0,
                    ], $this->monthSummary);
                }

                return [
                    'estimated_cost_eur' => 0.0,
                    'input_tokens' => 0,
                    'output_tokens' => 0,
                    'cached_tokens' => 0,
                    'total_tokens' => 0,
                ];
            }

            public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
            {
                return array_slice($this->recentEvents, 0, max(1, $limit));
            }
        };
    }

    /**
     * @param Playbook[] $orderedPlaybooks
     */
    private function createPlaybookRepositoryFake(array $orderedPlaybooks = [], ?Playbook $foundPlaybook = null): PlaybookRepository
    {
        return new class($orderedPlaybooks, $foundPlaybook) extends PlaybookRepository {
            /**
             * @param Playbook[] $orderedPlaybooks
             */
            public function __construct(
                private array $orderedPlaybooks,
                private ?Playbook $foundPlaybook,
            ) {
            }

            /**
             * @return Playbook[]
             */
            public function findAllOrdered(): array
            {
                return $this->orderedPlaybooks;
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->orderedPlaybooks,
                    static fn (Playbook $playbook): bool => $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundPlaybook;
            }
        };
    }

    /**
     * @param EntryPoint[] $orderedEntryPoints
     */
    private function createEntryPointRepositoryFake(array $orderedEntryPoints = [], ?EntryPoint $foundEntryPoint = null, ?EntryPoint $existingEntryPoint = null): EntryPointRepository
    {
        return new class($orderedEntryPoints, $foundEntryPoint, $existingEntryPoint) extends EntryPointRepository {
            /**
             * @param EntryPoint[] $orderedEntryPoints
             */
            public function __construct(
                private array $orderedEntryPoints,
                private ?EntryPoint $foundEntryPoint,
                private ?EntryPoint $existingEntryPoint,
            ) {
            }

            /**
             * @return EntryPoint[]
             */
            public function findAllOrdered(): array
            {
                return $this->orderedEntryPoints;
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->orderedEntryPoints,
                    static fn (EntryPoint $entryPoint): bool => $entryPoint->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                ));
            }

            public function findOneBy(array $criteria, ?array $orderBy = null): ?object
            {
                return $this->existingEntryPoint;
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundEntryPoint;
            }
        };
    }

    public function testLoginPageRendersBrowserForm(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $authenticationUtils = $this->createStub(AuthenticationUtils::class);
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);
        $authenticationUtils->method('getLastUsername')->willReturn('federicomartin2609@gmail.com');

        $controller = $this->createController($security);
        $response = $controller->login($authenticationUtils);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Iniciar sesión', $response->getContent());
        self::assertStringContainsString('name="email"', $response->getContent());
        self::assertStringContainsString('name="password"', $response->getContent());
        self::assertStringContainsString('/backend/login-check', $response->getContent());
        self::assertStringContainsString('federicomartin2609&#x40;gmail.com', $response->getContent());
        self::assertStringNotContainsString('Credenciales iniciales', $response->getContent());
    }

    public function testLoginCheckRouteExistsForPostSubmission(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'loginCheck');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/login-check', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testLogoutRouteExistsForSessionExit(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'logout');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/logout', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
    }

    public function testConfigurationRouteExistsForAdminSettings(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'configuration');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/configuration', $route->getPath());
        self::assertSame(['GET', 'POST'], $route->getMethods());
    }

    public function testProfileNameRouteExistsForPostSubmission(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'profileName');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/profile/name', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testProfilePasswordRouteExistsForPostSubmission(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'profilePassword');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/profile/password', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testLoginPageRedirectsWhenUserIsAlreadyAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'admin@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }
        });

        $authenticationUtils = $this->createStub(AuthenticationUtils::class);

        $controller = $this->createController($security);
        $response = $controller->login($authenticationUtils);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/dashboard', $response->headers->get('Location'));
    }

    public function testDashboardRendersSelectorWhenNoTenantIsActive(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'admin@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_SUPER_ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $controller = $this->createController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio para empezar', $response->getContent());
        self::assertStringContainsString('Ir al selector', $response->getContent());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('Sin negocio seleccionado', $response->getContent());
        self::assertStringContainsString('Seleccionar negocio', $response->getContent());
        self::assertStringContainsString('Plataforma', $response->getContent());
        self::assertStringContainsString('Administración técnica', $response->getContent());
        self::assertStringNotContainsString('Negocio activo', $response->getContent());
        self::assertStringNotContainsString('Uso IA', $response->getContent());
        self::assertStringNotContainsString('metric-value', $response->getContent());
    }

    public function testDashboardRendersTenantSpecificSummaryWhenTenantIsActive(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tenant->setBusinessContext('Contexto operativo del negocio activo.');
        $tenant->setTone('Profesional');

        $otherTenant = new Tenant('Another Brand', 'another-brand');
        $product = new Product($tenant, 'WhatsApp Automation');
        $productTwo = new Product($tenant, 'CRM Assistant');
        $otherProduct = new Product($otherTenant, 'RAG Knowledge System');
        $playbook = new Playbook($tenant, 'Guía comercial demo', $product);
        $playbookTwo = new Playbook($tenant, 'Guía comercial upsell', $productTwo);
        $otherPlaybook = new Playbook($otherTenant, 'Guía comercial otra', $otherProduct);
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setPlaybook($playbook);
        $entryPointTwo = new EntryPoint($productTwo, 'upsell-demo', 'Upsell Demo');
        $entryPointTwo->setPlaybook($playbookTwo);
        $entryPointThree = new EntryPoint($product, 'support-demo', 'Support Demo');
        $entryPointThree->setPlaybook($playbook);
        $otherEntryPoint = new EntryPoint($otherProduct, 'other-demo', 'Other Demo');
        $otherEntryPoint->setPlaybook($otherPlaybook);
        $tool = new \App\Entity\ExternalTool($tenant, 'MCP del negocio', 'mcp_remote', 'openai_remote_mcp');
        $toolTwo = new \App\Entity\ExternalTool($tenant, 'MCP secundario', 'mcp_remote', 'openai_remote_mcp');
        $toolThree = new \App\Entity\ExternalTool($tenant, 'MCP analytics', 'mcp_remote', 'openai_remote_mcp');
        $toolFour = new \App\Entity\ExternalTool($tenant, 'MCP booking', 'mcp_remote', 'openai_remote_mcp');
        $otherTool = new \App\Entity\ExternalTool($otherTenant, 'MCP ajeno', 'mcp_remote', 'openai_remote_mcp');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'manager@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security, null, null, null, null, null, null, $this->createActiveTenantContext($tenant));
        $response = $controller->dashboard(
            null,
            null,
            $this->createPlaybookRepositoryFake([$playbook, $playbookTwo, $otherPlaybook]),
            $this->createProductRepositoryFake([$product, $productTwo, $otherProduct]),
            $this->createEntryPointRepositoryFake([$entryPoint, $entryPointTwo, $entryPointThree, $otherEntryPoint]),
            $this->createExternalToolRepositoryFake([$tool, $toolTwo, $toolThree, $toolFour, $otherTool])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Dashboard comercial — Tech Investments', $response->getContent());
        self::assertStringContainsString('Aquí configuras el contexto, productos, guías y herramientas del agente para este negocio.', $response->getContent());
        self::assertStringContainsString('Negocio activo', $response->getContent());
        self::assertStringContainsString('nav-tenant-link', $response->getContent());
        self::assertStringContainsString('href="/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit">Negocio</a>', $response->getContent());
        self::assertStringContainsString('tenant-chip-name">Tech Investments', $response->getContent());
        self::assertStringNotContainsString('Seleccionar negocio', $response->getContent());
        self::assertStringNotContainsString('Cambiar</a>', $response->getContent());
        self::assertStringContainsString('Productos / servicios</div><div class="metric-value">2</div>', $response->getContent());
        self::assertStringContainsString('Guías comerciales</div><div class="metric-value">2</div>', $response->getContent());
        self::assertStringContainsString('Puntos de entrada</div><div class="metric-value">3</div>', $response->getContent());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', $response->getContent());
        self::assertStringContainsString('/backend/products', $response->getContent());
        self::assertStringContainsString('/backend/playbooks', $response->getContent());
        self::assertStringContainsString('/backend/entry-points', $response->getContent());
        self::assertStringContainsString('Editar negocio', $response->getContent());
        self::assertStringContainsString('Ver productos / servicios', $response->getContent());
        self::assertStringContainsString('Ver guías comerciales', $response->getContent());
        self::assertStringContainsString('Ver puntos de entrada', $response->getContent());
        self::assertStringContainsString('/backend/ai-usage', $response->getContent());
        self::assertStringNotContainsString('/backend/external-tools', $response->getContent());
        self::assertStringNotContainsString('Servidores MCP', $response->getContent());
        self::assertStringNotContainsString('Plataforma', $response->getContent());
        self::assertStringNotContainsString('Administración técnica', $response->getContent());
        self::assertStringNotContainsString('API Health', $response->getContent());
        self::assertStringNotContainsString('Selecciona un negocio para empezar', $response->getContent());
        self::assertStringNotContainsString('Usuarios registrados', $response->getContent());
    }

    public function testDashboardShowsPlatformAndTechnicalBlocksForSuperAdmin(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new \App\Entity\ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setRuntimeDefault(true);
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'owner@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_SUPER_ADMIN'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $controller = $this->createController($security, null, null, null, null, null, null, $this->createActiveTenantContext($tenant));
        $response = $controller->dashboard(
            null,
            null,
            null,
            null,
            null,
            $this->createExternalToolRepositoryFake([$tool])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Negocio activo', $response->getContent());
        self::assertStringContainsString('Administración técnica', $response->getContent());
        self::assertStringContainsString('Plataforma', $response->getContent());
        self::assertStringContainsString('Servidores MCP', $response->getContent());
        self::assertStringContainsString('↺', $response->getContent());
        self::assertStringContainsString('Cambiar negocio', $response->getContent());
        self::assertStringContainsString('/backend/external-tools', $response->getContent());
        self::assertStringContainsString('/backend/users', $response->getContent());
        self::assertStringContainsString('/backend/configuration', $response->getContent());
        self::assertStringContainsString('/backend/api-health', $response->getContent());
        self::assertStringContainsString('API Health', $response->getContent());
        self::assertStringContainsString('/backend/tenants', $response->getContent());
    }

    public function testDashboardShowsRuntimeDefaultMcpWhenPresent(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new \App\Entity\ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setRuntimeDefault(true);
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'manager@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security, null, null, null, null, null, null, $this->createActiveTenantContext($tenant));
        $response = $controller->dashboard(
            null,
            null,
            null,
            null,
            null,
            $this->createExternalToolRepositoryFake([$tool])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('MCP runtime', $response->getContent());
        self::assertStringNotContainsString('/backend/external-tools', $response->getContent());
    }

    public function testDashboardShowsWarningWhenMultipleActiveMcpsExistWithoutPrincipal(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $toolA = new \App\Entity\ExternalTool($tenant, 'MCP A', 'mcp_remote', 'openai_remote_mcp');
        $toolA->setWebhookUrl('https://mcp-a.example.test');
        $toolA->setConfig(['enabled_for_llm' => true, 'server_label' => 'mcp_a']);
        $toolB = new \App\Entity\ExternalTool($tenant, 'MCP B', 'mcp_remote', 'openai_remote_mcp');
        $toolB->setWebhookUrl('https://mcp-b.example.test');
        $toolB->setConfig(['enabled_for_llm' => true, 'server_label' => 'mcp_b']);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'manager@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security, null, null, null, null, null, null, $this->createActiveTenantContext($tenant));
        $response = $controller->dashboard(
            null,
            null,
            null,
            null,
            null,
            $this->createExternalToolRepositoryFake([$toolA, $toolB])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('MCP runtime', $response->getContent());
        self::assertStringNotContainsString('/backend/external-tools', $response->getContent());
    }

    public function testDashboardShowsPendingMcpWhenNoneExists(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'manager@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security, null, null, null, null, null, null, $this->createActiveTenantContext($tenant));
        $response = $controller->dashboard(
            null,
            null,
            null,
            null,
            null,
            $this->createExternalToolRepositoryFake([])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('MCP runtime', $response->getContent());
        self::assertStringNotContainsString('/backend/external-tools', $response->getContent());
    }

    public function testUsersRendersTwigListForAdmins(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $userOne = $this->createAuthenticatedUser('ana@example.com', ['admin'], 'Ana');
        $userTwo = $this->createAuthenticatedUser('pablo@example.com', ['manager'], 'Pablo');
        $userTwo->setActive(false);

        $usersRepository = $this->createMock(EntityRepository::class);
        $usersRepository->expects(self::once())
            ->method('findBy')
            ->with([], ['createdAt' => 'DESC'])
            ->willReturn([$userOne, $userTwo]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($usersRepository);

        $controller = $this->createController($security, $entityManager);
        $response = $controller->users();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Usuarios registrados', $response->getContent());
        self::assertStringContainsString('ana@example.com', $response->getContent());
        self::assertStringContainsString('pablo@example.com', $response->getContent());
        self::assertStringContainsString('Activo', $response->getContent());
        self::assertStringContainsString('Inactivo', $response->getContent());
        self::assertStringContainsString('Login ok', $response->getContent());
        self::assertStringContainsString('Sin acceso', $response->getContent());
        self::assertStringNotContainsString('No hay usuarios todavía.', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('/backend/users', $response->getContent());
    }

    public function testEntryPointRoutesExistForListingDetailAndEditing(): void
    {
        foreach ([
            ['entryPoints', '/entry-points', ['GET']],
            ['entryPointCreate', '/entry-points/new', ['GET', 'POST']],
            ['entryPointEdit', '/entry-points/{id}/edit', ['GET', 'POST']],
            ['entryPointDetail', '/entry-points/{id}', ['GET']],
        ] as [$method, $path, $methods]) {
            $reflection = new \ReflectionMethod(BackendUiController::class, $method);
            $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

            self::assertCount(1, $attributes);

            /** @var \Symfony\Component\Routing\Attribute\Route $route */
            $route = $attributes[0]->newInstance();

            self::assertSame($path, $route->getPath());
            self::assertSame($methods, $route->getMethods());
        }
    }

    public function testTenantsPageRendersCreateAndEditActions(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $tenant->setBusinessContext('Tenant de arranque para pruebas del backend administrativo.');
        $tenant->setSalesPolicy([
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Detectar tipo de negocio',
            'handoffRules' => 'Derivar si pide demo',
            'salesBoundaries' => ['No prometer cierres'],
            'notes' => 'Demo',
        ]);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->tenants(Request::create('/backend/tenants', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selector de negocios', $response->getContent());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('aria-label="Editar negocio"', $response->getContent());
        self::assertStringContainsString('aria-label="Eliminar negocio"', $response->getContent());
        self::assertStringContainsString('/backend/super-admin/tenants/'.$tenant->getId()->toRfc4122().'/ai', $response->getContent());
        self::assertStringContainsString('/backend/tenants/new', $response->getContent());
        self::assertStringContainsString('/backend/tenants/', $response->getContent());
        self::assertStringContainsString('<a class="active" href="/backend/tenants">Negocios</a>', $response->getContent());
        self::assertStringNotContainsString('nav-tenant-link">Negocio</a>', $response->getContent());
        self::assertStringContainsString('Contexto:', $response->getContent());
        self::assertStringContainsString('Tono:', $response->getContent());
    }

    public function testTenantDeleteRemovesTenantAndShowsFlashOnListing(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($tenant);
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $request = Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/delete', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->tenantDelete($tenant->getId()->toRfc4122(), $request, $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants', $response->headers->get('Location'));

        $listRequest = Request::create('/backend/tenants', 'GET');
        $listRequest->setSession($request->getSession());
        $listResponse = $controller->tenants($listRequest, $tenants);

        self::assertStringContainsString('Negocio eliminado.', $listResponse->getContent());
    }

    public function testTenantEnterStoresActiveTenantAndRedirectsToTenantEdit(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);
        $request = Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/enter', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $activeTenantContext = $this->createActiveTenantContextForRequest($tenant, $request);
        $controller = $this->createController($security, null, null, $csrfTokenManager, null, null, null, $activeTenantContext);
        $response = $controller->tenantEnter($tenant->getId()->toRfc4122(), $request, $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', $response->headers->get('Location'));
        self::assertTrue($activeTenantContext->hasActiveTenant());
        self::assertSame($tenant->getId()->toRfc4122(), $activeTenantContext->getActiveTenantId());
    }

    public function testProductsPageRequiresActiveTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], true));

        $controller = $this->createController($security);
        $response = $controller->products(Request::create('/backend/products', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio para continuar', $response->getContent());
        self::assertStringContainsString('/backend/tenants', $response->getContent());
        self::assertStringNotContainsString('Crear producto / servicio', $response->getContent());
    }

    public function testTenantCreateRouteExistsForGetAndPost(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'tenantCreate');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/tenants/new', $route->getPath());
        self::assertSame(['GET', 'POST'], $route->getMethods());
    }

    public function testTenantEditRouteExistsForGetAndPost(): void
    {
        $reflection = new \ReflectionMethod(BackendUiController::class, 'tenantEdit');
        $attributes = $reflection->getAttributes(\Symfony\Component\Routing\Attribute\Route::class);

        self::assertCount(1, $attributes);

        /** @var \Symfony\Component\Routing\Attribute\Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/tenants/{id}/edit', $route->getPath());
        self::assertSame(['GET', 'POST'], $route->getMethods());
    }

    public function testTenantCreateFormRendersTheExpectedFields(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createController($security, null, null, $csrfTokenManager);
        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('name="name"', $response->getContent());
        self::assertStringContainsString('name="slug"', $response->getContent());
        self::assertStringContainsString('name="tone"', $response->getContent());
        self::assertStringContainsString('name="whatsappPhoneNumberId"', $response->getContent());
        self::assertStringContainsString('name="whatsappPublicPhone"', $response->getContent());
        self::assertStringContainsString('Identidad del negocio', $response->getContent());
        self::assertStringContainsString('Contexto comercial', $response->getContent());
        self::assertStringContainsString('Canal WhatsApp del agente IA', $response->getContent());
        self::assertStringContainsString('Handoff humano', $response->getContent());
        self::assertStringContainsString('WhatsApp público del agente IA', $response->getContent());
        self::assertStringContainsString('WhatsApp humano para derivaciones', $response->getContent());
        self::assertStringContainsString('Estrategia de handoff', $response->getContent());
        self::assertStringContainsString('Criterios de derivación', $response->getContent());
        self::assertStringContainsString('name="businessContext"', $response->getContent());
        self::assertStringContainsString('name="positioning"', $response->getContent());
        self::assertStringContainsString('name="qualificationFocus"', $response->getContent());
        self::assertStringContainsString('name="handoffRules"', $response->getContent());
        self::assertStringContainsString('name="salesBoundaries"', $response->getContent());
        self::assertStringContainsString('name="notes"', $response->getContent());
        self::assertStringContainsString('name="isActive"', $response->getContent());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('data-tenant-draft-assistant', $response->getContent());
        self::assertStringContainsString('Ficha negocio, Canales, Handoff y Uso IA', $response->getContent());
        self::assertStringContainsString('No se guardará hasta que pulses Crear negocio.', $response->getContent());
        self::assertStringNotContainsString('Modo borrador', $response->getContent());
    }

    public function testTenantCreateSubmissionPersistsNewTenant(): void
    {
        $tenant = new Tenant('Academia Nova', 'academia-nova');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $createdTenant = null;
        $entityManager->expects(self::exactly(2))->method('persist')->with(self::callback(static function (object $entity) use (&$createdTenant): bool {
            if ($entity instanceof Tenant) {
                $createdTenant = $entity;
            }

            return true;
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'POST', [
            '_csrf_token' => 'token',
            'name' => 'Academia Nova',
            'slug' => 'academia-nova',
            'businessContext' => 'Negocio demo',
            'tone' => 'Cercano',
            'whatsappPhoneNumberId' => '123456789012345',
            'whatsappPublicPhone' => '34612345678',
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Identificar tipo de negocio',
            'handoffRules' => 'Derivar cuando el lead pida demo',
            'salesBoundaries' => "No prometer cierres automáticos\nNo inventar precios",
            'notes' => 'Plantilla de pruebas',
            'isActive' => '1',
        ]), $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(Tenant::class, $createdTenant);
        self::assertSame('/backend/tenants/'.$createdTenant->getId()->toRfc4122().'/edit', $response->headers->get('Location'));
    }

    public function testTenantCreateSubmissionAllowsEmptyWhatsappPhoneNumberId(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $createdTenant = null;
        $entityManager->expects(self::exactly(2))->method('persist')->with(self::callback(static function (object $entity) use (&$createdTenant): bool {
            if ($entity instanceof Tenant) {
                $createdTenant = $entity;
            }

            return true;
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, new Tenant('Tenant base', 'tenant-base'), $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'POST', [
            '_csrf_token' => 'token',
            'name' => 'Academia Nova',
            'slug' => 'academia-nova',
            'businessContext' => 'Negocio demo',
            'tone' => 'Cercano',
            'whatsappPhoneNumberId' => '',
            'whatsappPublicPhone' => '34612345678',
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Identificar tipo de negocio',
            'handoffRules' => 'Derivar cuando el lead pida demo',
            'salesBoundaries' => "No prometer cierres automáticos\nNo inventar precios",
            'notes' => 'Plantilla de pruebas',
            'isActive' => '1',
        ]), $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(Tenant::class, $createdTenant);
        self::assertNull($createdTenant->getWhatsappPhoneNumberId());
        self::assertSame('/backend/tenants/'.$createdTenant->getId()->toRfc4122().'/edit', $response->headers->get('Location'));
    }

    public function testTenantCreateSubmissionRejectsDuplicateWhatsappPhoneNumberId(): void
    {
        $existingTenant = new Tenant('Mary', 'mary');
        $existingTenant->setWhatsappPhoneNumberId('123456789012345');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('admin@example.com', ['super_admin'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$existingTenant]);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, new Tenant('Tenant base', 'tenant-base'), $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'POST', [
            '_csrf_token' => 'token',
            'name' => 'Academia Nova',
            'slug' => 'academia-nova',
            'businessContext' => 'Negocio demo',
            'tone' => 'Cercano',
            'whatsappPhoneNumberId' => '123456789012345',
            'whatsappPublicPhone' => '34612345678',
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Identificar tipo de negocio',
            'handoffRules' => 'Derivar cuando el lead pida demo',
            'salesBoundaries' => "No prometer cierres automáticos\nNo inventar precios",
            'notes' => 'Plantilla de pruebas',
            'isActive' => '1',
        ]), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Este WhatsApp Phone Number ID ya está en uso por otro negocio.', $response->getContent());
        self::assertStringContainsString('name="whatsappPhoneNumberId"', $response->getContent());
    }

    public function testTenantEditFormRendersCurrentValues(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $tenant->setBusinessContext('Tenant de arranque para pruebas del backend administrativo.');
        $tenant->setTone('Profesional');
        $tenant->setWhatsappPhoneNumberId('123456789012345');
        $tenant->setWhatsappPublicPhone('34612345678');
        $tenant->setSalesPolicy([
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Detectar tipo de negocio',
            'handoffRules' => 'Derivar si pide demo',
            'salesBoundaries' => ['No prometer cierres'],
            'notes' => 'Demo',
        ]);
        $tenant->setActive(true);
        $aiUsagePolicyRepository = $this->createTenantAiUsagePolicyRepositoryFake(null);
        $recentEvent = new AiUsageEvent($tenant);
        $recentEvent->setProvider('openai');
        $recentEvent->setModel('gpt-4.1-mini');
        $recentEvent->setInputTokens(120);
        $recentEvent->setOutputTokens(30);
        $recentEvent->setCachedTokens(20);
        $recentEvent->setTotalTokens(150);
        $recentEvent->setEstimatedCost(0.0005);
        $recentEvent->setLatencyMs(123);
        $aiUsageEventsRepository = $this->createAiUsageEventRepositoryFake(
            [$recentEvent],
            ['estimated_cost_eur' => 0.004321, 'total_tokens' => 100],
            ['estimated_cost_eur' => 0.012345, 'total_tokens' => 500]
        );

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, null, null, $csrfTokenManager);
        $response = $controller->tenantEdit(
            $tenant->getId()->toRfc4122(),
            Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', 'GET'),
            $tenants,
            $aiUsagePolicyRepository,
            $aiUsageEventsRepository
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Ficha del negocio', $response->getContent());
        self::assertStringContainsString('Federico Martin Demo', $response->getContent());
        self::assertStringContainsString('federico-martin-demo', $response->getContent());
        self::assertStringContainsString('Profesional', $response->getContent());
        self::assertStringContainsString('Ficha negocio', $response->getContent());
        self::assertStringContainsString('Canales', $response->getContent());
        self::assertStringContainsString('Handoff', $response->getContent());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('Identidad del negocio', $response->getContent());
        self::assertStringContainsString('Contexto comercial', $response->getContent());
        self::assertStringContainsString('Canal WhatsApp del agente IA', $response->getContent());
        self::assertStringContainsString('Handoff humano', $response->getContent());
        self::assertStringContainsString('data-bs-target="#tenant-business-panel"', $response->getContent());
        self::assertStringContainsString('data-bs-target="#tenant-channels-panel"', $response->getContent());
        self::assertStringContainsString('data-bs-target="#tenant-handoff-panel"', $response->getContent());
        self::assertStringContainsString('data-bs-target="#tenant-ai-panel"', $response->getContent());
        self::assertStringContainsString('Ficha negocio, Canales, Handoff y Uso IA', $response->getContent());
        self::assertStringContainsString('No se guardará hasta que pulses Guardar cambios.', $response->getContent());
        self::assertStringContainsString('name="aiEnabled"', $response->getContent());
        self::assertStringContainsString('name="dailyCostLimitEur"', $response->getContent());
        self::assertStringContainsString('name="monthlyCostLimitEur"', $response->getContent());
        self::assertStringContainsString('step="1"', $response->getContent());
        self::assertStringContainsString('Tokens procesados hoy', $response->getContent());
        self::assertStringContainsString('Tokens procesados este mes', $response->getContent());
        self::assertStringContainsString('0,004321 €', $response->getContent());
        self::assertStringContainsString('0,012345 €', $response->getContent());
        self::assertStringContainsString('100', $response->getContent());
        self::assertStringContainsString('500', $response->getContent());
        self::assertStringContainsString('Límite diario de tokens', $response->getContent());
        self::assertStringContainsString('Límite mensual de tokens', $response->getContent());
        self::assertStringContainsString('Últimos 5 eventos IA', $response->getContent());
        self::assertStringContainsString('openai', $response->getContent());
        self::assertStringContainsString('gpt-4.1-mini', $response->getContent());
        self::assertStringContainsString('Input 120 | Output 30 | Cached 20', $response->getContent());
        self::assertStringContainsString('0,0005 €', $response->getContent());
        self::assertStringContainsString('123 ms', $response->getContent());
        self::assertStringContainsString('name="whatsappPhoneNumberId"', $response->getContent());
        self::assertStringContainsString('name="whatsappPublicPhone"', $response->getContent());
        self::assertStringContainsString('value="123456789012345"', $response->getContent());
        self::assertStringContainsString('value="34612345678"', $response->getContent());
        self::assertStringContainsString('Para WhatsApp real, usa un Phone Number ID único por tenant.', $response->getContent());
        self::assertStringContainsString('action="/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit"', $response->getContent());
        self::assertStringContainsString('nav-tenant-link', $response->getContent());
        self::assertStringContainsString('href="/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit"', $response->getContent());
        self::assertStringContainsString('>Negocio</a>', $response->getContent());
        self::assertStringNotContainsString('<a class="active" href="/backend/tenants">Negocios</a>', $response->getContent());
        self::assertCount(1, $aiUsagePolicyRepository->savedPolicies);
    }

    public function testTenantEditFormShowsEmptyAiUsageStateWhenNoEvents(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $tenant->setActive(true);
        $aiUsagePolicyRepository = $this->createTenantAiUsagePolicyRepositoryFake(null);
        $aiUsageEventsRepository = $this->createAiUsageEventRepositoryFake();

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createController($security, null, null, $csrfTokenManager);
        $response = $controller->tenantEdit(
            $tenant->getId()->toRfc4122(),
            Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', 'GET'),
            $tenants,
            $aiUsagePolicyRepository,
            $aiUsageEventsRepository
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('No hay eventos IA todavía para este negocio.', $response->getContent());
    }

    public function testTenantEditSubmissionUpdatesExistingTenant(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $tenant->setBusinessContext('Tenant de arranque para pruebas del backend administrativo.');
        $tenant->setTone('Profesional');
        $tenant->setSalesPolicy([
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Detectar tipo de negocio',
            'handoffRules' => 'Derivar si pide demo',
            'salesBoundaries' => ['No prometer cierres'],
            'notes' => 'Demo',
        ]);
        $tenant->setActive(true);
        $aiUsagePolicyRepository = $this->createTenantAiUsagePolicyRepositoryFake(null);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($tenant);
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $aiUsageEventsRepository = $this->createAiUsageEventRepositoryFake(
            [
                (static function (Tenant $tenant): AiUsageEvent {
                    $event = new AiUsageEvent($tenant);
                    $event->setProvider('openai');
                    $event->setModel('gpt-4.1-mini');
                    $event->setInputTokens(120);
                    $event->setOutputTokens(30);
                    $event->setCachedTokens(20);
                    $event->setTotalTokens(150);
                    $event->setEstimatedCost(0.0005);
                    $event->setLatencyMs(123);

                    return $event;
                })($tenant),
            ],
            ['estimated_cost_eur' => 0.004321, 'total_tokens' => 100],
            ['estimated_cost_eur' => 0.012345, 'total_tokens' => 500]
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantEdit(
            $tenant->getId()->toRfc4122(),
            Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', 'POST', [
                '_csrf_token' => 'token',
                'name' => 'Federico Martin Demo 2',
                'slug' => 'federico-martin-demo-2',
                'businessContext' => 'Contexto actualizado',
                'tone' => 'Cercano',
                'whatsappPhoneNumberId' => '123456789012345',
                'whatsappPublicPhone' => '34612345678',
                'positioning' => 'Nueva propuesta',
                'qualificationFocus' => 'Recoger necesidad',
                'handoffRules' => 'Handoff ante oportunidad',
                'salesBoundaries' => "Sin garantías\nSin promesas",
                'notes' => 'Actualización',
                'aiEnabled' => '1',
                'dailyCostLimitEur' => '60750',
                'monthlyCostLimitEur' => '617658',
                'defaultModel' => 'gpt-4.1-mini',
                'fallbackModel' => 'gpt-4.1-nano',
                'limitAction' => 'block',
            ]),
            $tenants,
            $aiUsagePolicyRepository,
            $aiUsageEventsRepository
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', $response->headers->get('Location'));
        self::assertSame('Federico Martin Demo 2', $tenant->getName());
        self::assertSame('federico-martin-demo-2', $tenant->getSlug());
        self::assertSame('Contexto actualizado', $tenant->getBusinessContext());
        self::assertSame('Cercano', $tenant->getTone());
        self::assertSame('123456789012345', $tenant->getWhatsappPhoneNumberId());
        self::assertSame('34612345678', $tenant->getWhatsappPublicPhone());
        self::assertSame([
            'positioning' => 'Nueva propuesta',
            'qualificationFocus' => 'Recoger necesidad',
            'handoffRules' => 'Handoff ante oportunidad',
            'salesBoundaries' => ['Sin garantías', 'Sin promesas'],
            'notes' => 'Actualización',
        ], $tenant->getSalesPolicy());
        self::assertFalse($tenant->isActive());
        self::assertCount(2, $aiUsagePolicyRepository->savedPolicies);
        $policy = $aiUsagePolicyRepository->savedPolicies[1];
        self::assertGreaterThan(0.0, $policy->getDailyCostLimitEur());
        self::assertGreaterThan(0.0, $policy->getMonthlyCostLimitEur());
        self::assertSame('gpt-4.1-mini', $policy->getDefaultModel());
        self::assertSame('gpt-4.1-nano', $policy->getFallbackModel());
        self::assertSame('block', $policy->getLimitAction());
    }

    public function testPlaybooksPageRendersCreateAndEditActions(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');
        $playbook = new Playbook($tenant, 'Guía comercial demo', $product);
        $playbook->setConfig([
            'objective' => 'Calificar el lead',
            'qualificationQuestions' => ['¿Qué buscas?'],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
                'positiveSignals' => ['Quiere demo'],
                'negativeSignals' => ['No tiene presupuesto'],
            ],
            'agendaRules' => ['Ofrecer reunión'],
            'handoffRules' => ['Derivar si hay interés alto'],
            'allowedActions' => ['ask_question'],
            'notes' => 'Demo',
        ]);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);
        $products = $this->createProductRepositoryFake([$product]);
        $playbooks = $this->createPlaybookRepositoryFake([$playbook]);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->playbooks(Request::create('/backend/playbooks', 'GET'), $playbooks);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Guías comerciales de Federico Martin Demo', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar guía comercial', $response->getContent());
        self::assertStringContainsString('Eliminar guía comercial', $response->getContent());
        self::assertStringContainsString('Guía comercial demo', $response->getContent());
        self::assertStringContainsString('Resumen:', $response->getContent());
        self::assertStringNotContainsString('Todos los tenants', $response->getContent());
    }

    public function testPlaybookDeleteRemovesPlaybookAndShowsFlashOnListing(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $playbook = new Playbook($tenant, 'Guía comercial demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($playbook);
        $entityManager->expects(self::once())->method('flush');

        $playbooks = $this->createPlaybookRepositoryFake([$playbook], $playbook);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $request = Request::create('/backend/playbooks/'.$playbook->getId()->toRfc4122().'/delete', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->playbookDelete($playbook->getId()->toRfc4122(), $request, $playbooks);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/playbooks', $response->headers->get('Location'));

        $listRequest = Request::create('/backend/playbooks', 'GET');
        $listRequest->setSession($request->getSession());
        $listResponse = $controller->playbooks($listRequest, $playbooks);

        self::assertStringContainsString('Guía comercial eliminada.', $listResponse->getContent());
    }

    public function testPlaybookCreateFormRendersTheExpectedFields(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);
        $products = $this->createProductRepositoryFake([$product]);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, null, null, $csrfTokenManager);
        $response = $controller->playbookCreate(Request::create('/backend/playbooks/new', 'GET'), $tenants, $products);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear guía comercial', $response->getContent());
        self::assertStringContainsString('value="Federico Martin Demo"', $response->getContent());
        self::assertStringContainsString('name="productId"', $response->getContent());
        self::assertStringContainsString('name="name"', $response->getContent());
        self::assertStringContainsString('name="objective"', $response->getContent());
        self::assertStringContainsString('name="qualificationQuestions"', $response->getContent());
        self::assertStringContainsString('name="maxScore"', $response->getContent());
        self::assertStringContainsString('name="handoffThreshold"', $response->getContent());
        self::assertStringContainsString('name="positiveSignals"', $response->getContent());
        self::assertStringContainsString('name="negativeSignals"', $response->getContent());
        self::assertStringContainsString('name="agendaRules"', $response->getContent());
        self::assertStringContainsString('name="handoffRules"', $response->getContent());
        self::assertStringContainsString('name="allowedActions"', $response->getContent());
        self::assertStringContainsString('name="notes"', $response->getContent());
        self::assertStringContainsString('Guía IA', $response->getContent());
        self::assertStringContainsString('data-playbook-draft-assistant', $response->getContent());
        self::assertStringContainsString('playbook-draft-assistant-modal', $response->getContent());
        self::assertStringNotContainsString('Modo borrador', $response->getContent());
    }

    public function testPlaybookCreateSubmissionPersistsNewPlaybook(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (Playbook $playbook) use ($tenant, $product): bool {
            return $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                && $playbook->getProduct()?->getId()->toRfc4122() === $product->getId()->toRfc4122()
                && $playbook->getName() === 'Guía comercial demo'
                && $playbook->getConfig()['objective'] === 'Calificar el lead'
                && $playbook->isActive();
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);
        $products = $this->createProductRepositoryFake([$product], $product);
        $playbooks = $this->createPlaybookRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->playbookCreate(Request::create('/backend/playbooks/new', 'POST', [
            '_csrf_token' => 'token',
            'productId' => $product->getId()->toRfc4122(),
            'name' => 'Guía comercial demo',
            'objective' => 'Calificar el lead',
            'qualificationQuestions' => "¿Qué buscas?\n¿Para cuándo?",
            'maxScore' => '10',
            'handoffThreshold' => '7',
            'positiveSignals' => "Quiere demo\nTiene presupuesto",
            'negativeSignals' => "No tiene urgencia",
            'agendaRules' => "Ofrecer agenda",
            'handoffRules' => "Derivar si hay interés alto",
            'allowedActions' => "ask_question\noffer_demo",
            'notes' => 'Demo',
            'isActive' => '1',
        ]), $tenants, $products, $playbooks);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/playbooks', $response->headers->get('Location'));
    }

    public function testPlaybookCreateSubmissionAllowsMinimalOptionalConfig(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (Playbook $playbook) use ($tenant): bool {
            return $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                && $playbook->getProduct() === null
                && $playbook->getName() === 'Guía comercial campaña'
                && $playbook->getConfig() === [];
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);
        $playbooks = $this->createPlaybookRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->playbookCreate(Request::create('/backend/playbooks/new', 'POST', [
            '_csrf_token' => 'token',
            'productId' => '',
            'name' => 'Guía comercial campaña',
            'objective' => '',
            'qualificationQuestions' => '',
            'maxScore' => '',
            'handoffThreshold' => '',
            'positiveSignals' => '',
            'negativeSignals' => '',
            'agendaRules' => '',
            'handoffRules' => '',
            'allowedActions' => '',
            'notes' => '',
            'isActive' => '1',
        ]), $tenants, null, $playbooks);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/playbooks', $response->headers->get('Location'));
    }

    public function testProductsPageRendersCreateEditAndDeleteActions(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');
        $product->setDescription('Automatización de WhatsApp');
        $product->setValueProposition('Reduce tiempo operativo');
        $product->setSalesPolicy([
            'positioning' => 'Demo comercial',
            'pricingNotes' => 'Desde 99€',
            'objections' => ['Es caro'],
            'handoffRules' => 'Derivar si pide demo',
            'notes' => 'Demo',
        ]);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $products = $this->createProductRepositoryFake([$product]);
        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->products(Request::create('/backend/products', 'GET'), $products, $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Productos / servicios de Federico Martin Demo', $response->getContent());
        self::assertStringContainsString('Crear producto / servicio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar producto / servicio', $response->getContent());
        self::assertStringContainsString('Eliminar producto / servicio', $response->getContent());
        self::assertStringContainsString('/backend/products/'.$product->getId()->toRfc4122().'/delete', $response->getContent());
        self::assertStringContainsString('name="product"', $response->getContent());
        self::assertStringContainsString('WhatsApp Automation', $response->getContent());
        self::assertStringContainsString('Slug:', $response->getContent());
        self::assertStringNotContainsString('Todos los tenants', $response->getContent());
    }

    public function testProductsPageFiltersByTenantAndProductQuery(): void
    {
        $tenantA = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $tenantB = new Tenant('Tech Investments', 'tech-investments');
        $productA = new Product($tenantA, 'WhatsApp Automation');
        $productB = new Product($tenantB, 'RAG Knowledge System');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $products = $this->createProductRepositoryFake([$productA, $productB]);
        $tenants = $this->createTenantRepositoryFake([$tenantA, $tenantB]);

        $controller = $this->createControllerForActiveTenant($security, $tenantA);
        $request = Request::create('/backend/products', 'GET', [
            'product' => 'whatsapp',
        ]);

        $response = $controller->products($request, $products, $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('WhatsApp Automation', $response->getContent());
        self::assertStringNotContainsString('RAG Knowledge System', $response->getContent());
        self::assertStringContainsString('value="whatsapp"', $response->getContent());
        self::assertStringNotContainsString('name="tenantId"', $response->getContent());
    }

    public function testProductCreateFormRendersTheExpectedFields(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, null, null, $csrfTokenManager);
        $response = $controller->productCreate(Request::create('/backend/products/new', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear producto / servicio', $response->getContent());
        self::assertStringContainsString('value="Federico Martin Demo"', $response->getContent());
        self::assertStringContainsString('name="slug"', $response->getContent());
        self::assertStringContainsString('name="externalSource"', $response->getContent());
        self::assertStringContainsString('name="externalReference"', $response->getContent());
        self::assertStringContainsString('name="basePriceCents"', $response->getContent());
        self::assertStringContainsString('name="currency"', $response->getContent());
        self::assertStringContainsString('name="name"', $response->getContent());
        self::assertStringContainsString('name="description"', $response->getContent());
        self::assertStringContainsString('name="valueProposition"', $response->getContent());
        self::assertStringContainsString('name="positioning"', $response->getContent());
        self::assertStringContainsString('name="pricingNotes"', $response->getContent());
        self::assertStringContainsString('name="objections"', $response->getContent());
        self::assertStringContainsString('name="handoffRules"', $response->getContent());
        self::assertStringContainsString('name="notes"', $response->getContent());
    }

    public function testProductImportPageRendersHelpAndPayloadFields(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->productImport(Request::create('/backend/products/import', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Importar catálogo de productos / servicios', $response->getContent());
        self::assertStringContainsString('Catálogo independiente', $response->getContent());
        self::assertStringContainsString('value="Federico Martin Demo"', $response->getContent());
        self::assertStringContainsString('name="format"', $response->getContent());
        self::assertStringContainsString('name="file"', $response->getContent());
        self::assertStringContainsString('name="payload"', $response->getContent());
        self::assertStringContainsString('external_reference', $response->getContent());
    }

    public function testProductEditDoesNotRenderDeleteAction(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $products = $this->createProductRepositoryFake([$product], $product);
        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createControllerForActiveTenant($security, $tenant, null, null, $csrfTokenManager);
        $response = $controller->productEdit($product->getId()->toRfc4122(), Request::create('/backend/products/'.$product->getId()->toRfc4122().'/edit', 'GET'), $tenants, $products);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringNotContainsString('Eliminar producto / servicio', $response->getContent());
        self::assertStringNotContainsString('/backend/products/'.$product->getId()->toRfc4122().'/delete', $response->getContent());
    }

    public function testProductDeleteRemovesProductWhenItHasNoUsage(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($product);
        $entityManager->expects(self::once())->method('flush');

        $products = $this->createProductRepositoryFake([$product], $product);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $request = Request::create('/backend/products/'.$product->getId()->toRfc4122().'/delete', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());

        $response = $controller->productDelete($product->getId()->toRfc4122(), $request, $products);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/products', $response->headers->get('Location'));

        $listRequest = Request::create('/backend/products', 'GET');
        $listRequest->setSession($request->getSession());

        $listResponse = $controller->products($listRequest, $products);
        self::assertStringContainsString('Producto / servicio eliminado.', $listResponse->getContent());
    }

    public function testProductCreateSubmissionPersistsNewProduct(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (Product $product) use ($tenant): bool {
            return $product->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()
                && $product->getName() === 'WhatsApp Automation'
                && $product->getSlug() === 'whatsapp-automation'
                && $product->getDescription() === 'Automatización'
                && $product->getValueProposition() === 'Reduce tiempo'
                && $product->getExternalSource() === 'crm'
                && $product->getExternalReference() === 'pack-starter'
                && $product->getBasePriceCents() === 150000
                && $product->getCurrency() === 'EUR'
                && $product->getSalesPolicy()['positioning'] === 'Demo comercial'
                && $product->isActive();
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);
        $products = $this->createProductRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->productCreate(Request::create('/backend/products/new', 'POST', [
            '_csrf_token' => 'token',
            'slug' => 'whatsapp-automation',
            'externalSource' => 'crm',
            'externalReference' => 'pack-starter',
            'name' => 'WhatsApp Automation',
            'description' => 'Automatización',
            'valueProposition' => 'Reduce tiempo',
            'basePriceCents' => '150000',
            'currency' => 'EUR',
            'positioning' => 'Demo comercial',
            'pricingNotes' => 'Desde 99€',
            'objections' => "Es caro\nNo lo necesito",
            'handoffRules' => 'Derivar si pide demo',
            'notes' => 'Demo',
            'isActive' => '1',
        ]), $tenants, $products);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/products', $response->headers->get('Location'));
    }

    public function testEntryPointsPageRendersCreateEditAndDetailActions(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');
        $playbook = new Playbook($tenant, 'Guía comercial demo', $product);
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setPlaybook($playbook);
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entryPoints = $this->createEntryPointRepositoryFake([$entryPoint]);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->entryPoints($entryPoints);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear punto de entrada', $response->getContent());
        self::assertStringContainsString('Ver punto de entrada', $response->getContent());
        self::assertStringContainsString('Editar punto de entrada', $response->getContent());
        self::assertStringContainsString('crm-demo', $response->getContent());
        self::assertStringNotContainsString('Canal', $response->getContent());
    }

    public function testEntryPointDetailRendersPublicRedirectUrls(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');
        $playbook = new Playbook($tenant, 'Guía comercial demo', $product);
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setPlaybook($playbook);
        $entryPoint->setCrmBranchRef('branch-123');
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entryPoints = $this->createEntryPointRepositoryFake([], $entryPoint);

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->entryPointDetail($entryPoint->getId()->toRfc4122(), $entryPoints);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('/api/r/wa/crm-demo', $response->getContent());
        self::assertStringContainsString('utm_source=google&amp;utm_medium=cpc&amp;utm_campaign=example', $response->getContent());
        self::assertStringContainsString('branch-123', $response->getContent());
        self::assertStringNotContainsString('Canal', $response->getContent());
    }

    public function testEntryPointCreateSubmissionPersistsNewEntryPoint(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');
        $product = new Product($tenant, 'WhatsApp Automation');
        $playbook = new Playbook($tenant, 'Guía comercial demo', $product);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::callback(static function (EntryPoint $entryPoint) use ($product, $playbook): bool {
            return $entryPoint->getProduct()->getId()->toRfc4122() === $product->getId()->toRfc4122()
                && $entryPoint->getPlaybook()?->getId()->toRfc4122() === $playbook->getId()->toRfc4122()
                && $entryPoint->getCode() === 'crm-demo'
                && $entryPoint->getName() === 'CRM Demo'
                && $entryPoint->getSource() === 'google'
                && $entryPoint->getMedium() === 'cpc'
                && $entryPoint->getCampaign() === 'crm_pymes'
                && $entryPoint->getCrmBranchRef() === 'branch-123'
                && $entryPoint->getDefaultMessage() === 'Hola, quiero información.'
                && $entryPoint->isActive();
        }));
        $entityManager->expects(self::once())->method('flush');

        $products = $this->createProductRepositoryFake([$product], $product);
        $playbooks = $this->createPlaybookRepositoryFake([$playbook], $playbook);
        $entryPoints = $this->createEntryPointRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createControllerForActiveTenant($security, $tenant, $entityManager, null, $csrfTokenManager);
        $response = $controller->entryPointCreate(Request::create('/backend/entry-points/new', 'POST', [
            '_csrf_token' => 'token',
            'productId' => $product->getId()->toRfc4122(),
            'playbookId' => $playbook->getId()->toRfc4122(),
            'code' => 'crm-demo',
            'name' => 'CRM Demo',
            'source' => 'google',
            'medium' => 'cpc',
            'campaign' => 'crm_pymes',
            'content' => 'ad_01',
            'term' => 'crm',
            'crmBranchRef' => 'branch-123',
            'defaultMessage' => 'Hola, quiero información.',
            'isActive' => '1',
        ]), $products, $playbooks, $entryPoints);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/entry-points', $response->headers->get('Location'));
    }

    public function testProfileRendersCurrentSessionSummary(): void
    {
        $user = $this->createAuthenticatedUser('admin@example.com', ['admin'], 'Federico Martín');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_ADMIN', 'ROLE_MANAGER'], true));

        $entityManager = $this->createStub(EntityManagerInterface::class);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->profile(Request::create('/backend/profile', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Mi perfil', $response->getContent());
        self::assertStringContainsString('admin@example.com', $response->getContent());
        self::assertStringContainsString('Federico Martín', $response->getContent());
        self::assertStringContainsString('action="/backend/profile/name"', $response->getContent());
        self::assertStringContainsString('action="/backend/profile/password"', $response->getContent());
        self::assertStringContainsString('name="currentPassword"', $response->getContent());
        self::assertStringContainsString('name="newPassword"', $response->getContent());
        self::assertStringContainsString('Guardar nombre', $response->getContent());
        self::assertStringContainsString('Actualizar clave', $response->getContent());
    }

    public function testProfileNameUpdatePersistsTheDisplayName(): void
    {
        $user = $this->createAuthenticatedUser('admin@example.com', ['admin'], 'Federico Martín');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_ADMIN', 'ROLE_MANAGER'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($user);
        $entityManager->expects(self::once())->method('flush');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $request = Request::create('/backend/profile/name', 'POST', [
            '_csrf_token' => 'token-profile_name',
            'name' => 'Federico Martín Ortega',
        ]);

        $response = $controller->profileName($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/profile', $response->headers->get('Location'));
        self::assertSame('Federico Martín Ortega', $user->getName());
    }

    public function testProfilePasswordUpdatePersistsTheNewPassword(): void
    {
        $user = $this->createAuthenticatedUser('admin@example.com', ['admin'], 'Federico Martín');
        $user->setPassword('old-hash');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_ADMIN', 'ROLE_MANAGER'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($user);
        $entityManager->expects(self::once())->method('flush');

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())
            ->method('isPasswordValid')
            ->with($user, 'old-password')
            ->willReturn(true);
        $passwordHasher->expects(self::once())
            ->method('hashPassword')
            ->with($user, 'new-password')
            ->willReturn('new-hash');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, $entityManager, $passwordHasher, $csrfTokenManager);
        $request = Request::create('/backend/profile/password', 'POST', [
            '_csrf_token' => 'token-profile_password',
            'currentPassword' => 'old-password',
            'newPassword' => 'new-password',
        ]);

        $response = $controller->profilePassword($request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/profile', $response->headers->get('Location'));
        self::assertSame('new-hash', $user->getPassword());
    }

    public function testDashboardRedirectsWhenNoUserIsAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $controller = $this->createController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }

    public function testDashboardHidesUserAdminActionForAgentRole(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new class implements UserInterface {
            public function getUserIdentifier(): string
            {
                return 'agent@example.com';
            }

            public function getRoles(): array
            {
                return ['ROLE_AGENT'];
            }

            public function eraseCredentials(): void
            {
            }
        });
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_AGENT');

        $controller = $this->createController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio para empezar', $response->getContent());
        self::assertStringContainsString('Mi perfil', $response->getContent());
        self::assertStringNotContainsString('Revisar usuarios', $response->getContent());
    }

    public function testDashboardHidesMcpForNonSuperAdminWithActiveTenant(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createControllerForActiveTenant($security, $tenant);
        $response = $controller->dashboard(
            null,
            null,
            null,
            null,
            null,
            $this->createExternalToolRepositoryFake([])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Dashboard comercial — Tech Investments', $response->getContent());
        self::assertStringNotContainsString('/backend/external-tools', $response->getContent());
        self::assertStringNotContainsString('Servidores MCP', $response->getContent());
        self::assertStringNotContainsString('MCP runtime', $response->getContent());
    }

    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $controller = $this->createController($security);
        $response = $controller->index();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }

    public function testConfigurationPageRedirectsWhenUserIsNotAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(false);

        $controller = $this->createController($security);
        $response = $controller->configuration(Request::create('/backend/configuration', 'GET'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }

    public function testConfigurationPageDisablesAutofillAndKeepsSaveActionAtTheBottom(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $runtimeConfigurationService = $this->createMock(RuntimeConfigurationService::class);
        $runtimeConfigurationService->expects(self::once())
            ->method('pageData')
            ->with([], [])
            ->willReturn([
                'formState' => [
                    'llm_default_profile' => [
                        'key' => 'llm_default_profile',
                        'label' => 'Perfil LLM por defecto',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'auto',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'auto',
                    ],
                    'openai_base_url' => [
                        'key' => 'openai_base_url',
                        'label' => 'Base URL de OpenAI',
                        'description' => '',
                        'inputType' => 'text',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'https://api.openai.com/v1',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'https://api.openai.com/v1',
                    ],
                    'openai_model' => [
                        'key' => 'openai_model',
                        'label' => 'Modelo de OpenAI',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'gpt-4o-mini',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'gpt-4o-mini',
                    ],
                    'openai_api_key' => [
                        'key' => 'openai_api_key',
                        'label' => 'API key de OpenAI',
                        'description' => '',
                        'inputType' => 'password',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => '',
                        'secret' => true,
                        'configured' => false,
                        'value' => '',
                        'fullWidth' => true,
                    ],
                    'ollama_base_url' => [
                        'key' => 'ollama_base_url',
                        'label' => 'Base URL de Ollama',
                        'description' => '',
                        'inputType' => 'text',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'http://ollama-vpn-bridge:11434',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'http://ollama-vpn-bridge:11434',
                    ],
                    'ollama_model' => [
                        'key' => 'ollama_model',
                        'label' => 'Modelo de Ollama',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'llama3.1',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'llama3.1',
                    ],
                    'audio_gateway_base_url' => [
                        'key' => 'audio_gateway_base_url',
                        'label' => 'Base URL del audio-gateway',
                        'description' => '',
                        'inputType' => 'text',
                        'group' => 'audio',
                        'options' => [],
                        'defaultValue' => 'http://audio-gateway',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'http://audio-gateway',
                    ],
                ],
                'values' => [
                    'llm_default_profile' => 'auto',
                    'openai_base_url' => 'https://api.openai.com/v1',
                    'openai_model' => 'gpt-4o-mini',
                    'openai_api_key' => '',
                    'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
                    'ollama_model' => 'llama3.1',
                    'audio_gateway_base_url' => 'http://audio-gateway',
                ],
                'status' => [
                    'overall' => ['status' => 'ready', 'message' => 'Listo'],
                    'llm' => ['status' => 'ready'],
                    'openai' => ['status' => 'ready'],
                    'ollama' => ['status' => 'ready'],
                    'audio' => ['status' => 'ready'],
                ],
            ]);

        $controller = $this->createController($security, null, null, null, null, $runtimeConfigurationService);
        $request = Request::create('/backend/configuration', 'GET');
        $request->setSession(new Session());
        $request->getSession()->getFlashBag()->add('success', 'Configuración guardada.');
        $response = $controller->configuration($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('<form method="post" action="/backend/configuration" class="tenant-form runtime-settings-form">', $response->getContent());
        self::assertStringNotContainsString('autocomplete="off"', $response->getContent());
        self::assertGreaterThan(
            strpos($response->getContent(), 'name="action" value="test_ollama"'),
            strrpos($response->getContent(), 'name="action" value="save"')
        );
        self::assertStringContainsString('type="url"', $response->getContent());
        self::assertStringContainsString('autocomplete="new-password"', $response->getContent());
        self::assertStringContainsString('class="btn-close"', $response->getContent());
        self::assertStringContainsString('aria-label="Cerrar"', $response->getContent());
    }

    public function testConfigurationSaveRejectsInvalidRuntimeEndpoints(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');

        $runtimeConfigurationService = $this->createMock(RuntimeConfigurationService::class);
        $submitted = [
            'llm_default_profile' => 'auto',
            'openai_base_url' => 'federico.martin2609@gmail.com',
            'openai_model' => 'gpt-4o-mini',
            'openai_api_key' => 'wrong-secret',
            'openai_timeout_seconds' => '0',
            'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
            'ollama_model' => 'llama3.1',
            'ollama_timeout_seconds' => 'abc',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_timeout_seconds' => '0',
        ];

        $runtimeConfigurationService->expects(self::once())
            ->method('validate')
            ->with($submitted)
            ->willReturn([
                'El endpoint "Base URL de OpenAI" debe ser una URL válida con http o https.',
                'La clave API de OpenAI no parece válida.',
            ]);
        $runtimeConfigurationService->expects(self::once())
            ->method('pageData')
            ->with($submitted, [])
            ->willReturn([
                'formState' => [
                    'llm_default_profile' => [
                        'key' => 'llm_default_profile',
                        'label' => 'Perfil LLM por defecto',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'auto',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'auto',
                    ],
                    'openai_base_url' => [
                        'key' => 'openai_base_url',
                        'label' => 'Base URL de OpenAI',
                        'description' => '',
                        'inputType' => 'url',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'https://api.openai.com/v1',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'federico.martin2609@gmail.com',
                    ],
                    'openai_model' => [
                        'key' => 'openai_model',
                        'label' => 'Modelo de OpenAI',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'gpt-4o-mini',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'gpt-4o-mini',
                    ],
                    'openai_api_key' => [
                        'key' => 'openai_api_key',
                        'label' => 'API key de OpenAI',
                        'description' => '',
                        'inputType' => 'password',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => '',
                        'secret' => true,
                        'configured' => false,
                        'value' => '',
                        'fullWidth' => true,
                    ],
                    'ollama_base_url' => [
                        'key' => 'ollama_base_url',
                        'label' => 'Base URL de Ollama',
                        'description' => '',
                        'inputType' => 'url',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'http://ollama-vpn-bridge:11434',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'http://ollama-vpn-bridge:11434',
                    ],
                    'ollama_model' => [
                        'key' => 'ollama_model',
                        'label' => 'Modelo de Ollama',
                        'description' => '',
                        'inputType' => 'select',
                        'group' => 'llm',
                        'options' => [],
                        'defaultValue' => 'llama3.1',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'llama3.1',
                    ],
                    'audio_gateway_base_url' => [
                        'key' => 'audio_gateway_base_url',
                        'label' => 'Base URL del audio-gateway',
                        'description' => '',
                        'inputType' => 'url',
                        'group' => 'audio',
                        'options' => [],
                        'defaultValue' => 'http://audio-gateway',
                        'secret' => false,
                        'configured' => false,
                        'value' => 'http://audio-gateway',
                    ],
                ],
                'values' => [
                    'llm_default_profile' => 'auto',
                    'openai_base_url' => 'federico.martin2609@gmail.com',
                    'openai_model' => 'gpt-4o-mini',
                    'openai_api_key' => 'wrong-secret',
                    'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
                    'ollama_model' => 'llama3.1',
                    'audio_gateway_base_url' => 'http://audio-gateway',
                ],
                'status' => [
                    'overall' => ['status' => 'blocked', 'message' => 'Bloqueado'],
                    'llm' => ['status' => 'blocked'],
                    'openai' => ['status' => 'blocked'],
                    'ollama' => ['status' => 'ready'],
                    'audio' => ['status' => 'ready'],
                ],
            ]);
        $runtimeConfigurationService->expects(self::never())->method('save');

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, null, null, $csrfTokenManager, null, $runtimeConfigurationService);
        $request = Request::create('/backend/configuration', 'POST', [
            '_csrf_token' => 'token-runtime_configuration',
            'action' => 'save',
            'llm_default_profile' => 'auto',
            'openai_base_url' => 'federico.martin2609@gmail.com',
            'openai_model' => 'gpt-4o-mini',
            'openai_api_key' => 'wrong-secret',
            'openai_timeout_seconds' => '0',
            'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
            'ollama_model' => 'llama3.1',
            'ollama_timeout_seconds' => 'abc',
            'audio_gateway_base_url' => 'http://audio-gateway',
            'audio_timeout_seconds' => '0',
        ]);

        $response = $controller->configuration($request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('La clave API de OpenAI no parece válida.', $response->getContent());
        self::assertStringContainsString('El endpoint &quot;Base URL de OpenAI&quot; debe ser una URL válida con http o https.', $response->getContent());
        self::assertStringNotContainsString('Configuración guardada', $response->getContent());
    }
}
