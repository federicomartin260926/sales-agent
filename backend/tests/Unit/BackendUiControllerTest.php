<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class BackendUiControllerTest extends TestCase
{
    private function createController(
        Security $security,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ): BackendUiController {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $passwordHasher ??= $this->createStub(UserPasswordHasherInterface::class);
        $csrfTokenManager ??= $this->createStub(CsrfTokenManagerInterface::class);

        return new BackendUiController($security, $entityManager, $passwordHasher, $csrfTokenManager);
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
                return null;
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundTenant;
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

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundProduct;
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

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundPlaybook;
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
        self::assertStringContainsString('federicomartin2609@gmail.com', $response->getContent());
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

    public function testDashboardRendersAdminLandingForAuthenticatedUser(): void
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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_AGENT', 'ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Dashboard comercial de negocios', $response->getContent());
        self::assertStringContainsString('/logout', $response->getContent());
        self::assertStringContainsString('/backend/profile', $response->getContent());
        self::assertStringContainsString('/backend/playbooks', $response->getContent());
        self::assertStringContainsString('Admin', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('Salir', $response->getContent());
        self::assertStringContainsString('Negocios', $response->getContent());
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
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $controller = $this->createController($security);
        $response = $controller->tenants($tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('aria-label="Editar negocio"', $response->getContent());
        self::assertStringContainsString('/backend/tenants/new', $response->getContent());
        self::assertStringContainsString('/backend/tenants/', $response->getContent());
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
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

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
        self::assertStringContainsString('name="businessContext"', $response->getContent());
        self::assertStringContainsString('name="positioning"', $response->getContent());
        self::assertStringContainsString('name="qualificationFocus"', $response->getContent());
        self::assertStringContainsString('name="handoffRules"', $response->getContent());
        self::assertStringContainsString('name="salesBoundaries"', $response->getContent());
        self::assertStringContainsString('name="notes"', $response->getContent());
        self::assertStringContainsString('name="isActive"', $response->getContent());
        self::assertStringContainsString('Crear negocio', $response->getContent());
    }

    public function testTenantCreateSubmissionPersistsNewTenant(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (Tenant $tenant): bool {
                return $tenant->getName() === 'Academia Nova'
                    && $tenant->getSlug() === 'academia-nova'
                    && $tenant->getBusinessContext() === 'Negocio demo'
                    && $tenant->getTone() === 'Cercano'
                    && $tenant->getSalesPolicy() === [
                        'positioning' => 'Demo comercial',
                        'qualificationFocus' => 'Identificar tipo de negocio',
                        'handoffRules' => 'Derivar cuando el lead pida demo',
                        'salesBoundaries' => ['No prometer cierres automáticos', 'No inventar precios'],
                        'notes' => 'Plantilla de pruebas',
                    ]
                    && $tenant->isActive();
            }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'POST', [
            '_csrf_token' => 'token',
            'name' => 'Academia Nova',
            'slug' => 'academia-nova',
            'businessContext' => 'Negocio demo',
            'tone' => 'Cercano',
            'positioning' => 'Demo comercial',
            'qualificationFocus' => 'Identificar tipo de negocio',
            'handoffRules' => 'Derivar cuando el lead pida demo',
            'salesBoundaries' => "No prometer cierres automáticos\nNo inventar precios",
            'notes' => 'Plantilla de pruebas',
            'isActive' => '1',
        ]), $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants', $response->headers->get('Location'));
    }

    public function testTenantEditFormRendersCurrentValues(): void
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

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createController($security, null, null, $csrfTokenManager);
        $response = $controller->tenantEdit($tenant->getId()->toRfc4122(), Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Editar negocio', $response->getContent());
        self::assertStringContainsString('Federico Martin Demo', $response->getContent());
        self::assertStringContainsString('federico-martin-demo', $response->getContent());
        self::assertStringContainsString('Profesional', $response->getContent());
        self::assertStringContainsString('action="/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit"', $response->getContent());
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

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($tenant);
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->tenantEdit($tenant->getId()->toRfc4122(), Request::create('/backend/tenants/'.$tenant->getId()->toRfc4122().'/edit', 'POST', [
            '_csrf_token' => 'token',
            'name' => 'Federico Martin Demo 2',
            'slug' => 'federico-martin-demo-2',
            'businessContext' => 'Contexto actualizado',
            'tone' => 'Cercano',
            'positioning' => 'Nueva propuesta',
            'qualificationFocus' => 'Recoger necesidad',
            'handoffRules' => 'Handoff ante oportunidad',
            'salesBoundaries' => "Sin garantías\nSin promesas",
            'notes' => 'Actualización',
        ]), $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants', $response->headers->get('Location'));
        self::assertSame('Federico Martin Demo 2', $tenant->getName());
        self::assertSame('federico-martin-demo-2', $tenant->getSlug());
        self::assertSame('Contexto actualizado', $tenant->getBusinessContext());
        self::assertSame('Cercano', $tenant->getTone());
        self::assertSame([
            'positioning' => 'Nueva propuesta',
            'qualificationFocus' => 'Recoger necesidad',
            'handoffRules' => 'Handoff ante oportunidad',
            'salesBoundaries' => ['Sin garantías', 'Sin promesas'],
            'notes' => 'Actualización',
        ], $tenant->getSalesPolicy());
        self::assertFalse($tenant->isActive());
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

        $controller = $this->createController($security);
        $response = $controller->playbooks($playbooks);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear guía comercial', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar guía comercial', $response->getContent());
        self::assertStringContainsString('Guía comercial demo', $response->getContent());
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

        $controller = $this->createController($security, null, null, $csrfTokenManager);
        $response = $controller->playbookCreate(Request::create('/backend/playbooks/new', 'GET'), $tenants, $products);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear guía comercial', $response->getContent());
        self::assertStringContainsString('name="tenantId"', $response->getContent());
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

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->playbookCreate(Request::create('/backend/playbooks/new', 'POST', [
            '_csrf_token' => 'token',
            'tenantId' => $tenant->getId()->toRfc4122(),
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

    public function testProductsPageRendersCreateAndEditActions(): void
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

        $controller = $this->createController($security);
        $response = $controller->products($products);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear producto / servicio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar producto / servicio', $response->getContent());
        self::assertStringContainsString('WhatsApp Automation', $response->getContent());
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

        $controller = $this->createController($security, null, null, $csrfTokenManager);
        $response = $controller->productCreate(Request::create('/backend/products/new', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear producto / servicio', $response->getContent());
        self::assertStringContainsString('name="tenantId"', $response->getContent());
        self::assertStringContainsString('name="name"', $response->getContent());
        self::assertStringContainsString('name="description"', $response->getContent());
        self::assertStringContainsString('name="valueProposition"', $response->getContent());
        self::assertStringContainsString('name="positioning"', $response->getContent());
        self::assertStringContainsString('name="pricingNotes"', $response->getContent());
        self::assertStringContainsString('name="objections"', $response->getContent());
        self::assertStringContainsString('name="handoffRules"', $response->getContent());
        self::assertStringContainsString('name="notes"', $response->getContent());
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
                && $product->getDescription() === 'Automatización'
                && $product->getValueProposition() === 'Reduce tiempo'
                && $product->getSalesPolicy()['positioning'] === 'Demo comercial'
                && $product->isActive();
        }));
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);
        $products = $this->createProductRepositoryFake();

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->productCreate(Request::create('/backend/products/new', 'POST', [
            '_csrf_token' => 'token',
            'tenantId' => $tenant->getId()->toRfc4122(),
            'name' => 'WhatsApp Automation',
            'description' => 'Automatización',
            'valueProposition' => 'Reduce tiempo',
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
        self::assertStringContainsString('Dashboard comercial de negocios', $response->getContent());
        self::assertStringContainsString('Mi perfil', $response->getContent());
        self::assertStringNotContainsString('/backend/users', $response->getContent());
        self::assertStringNotContainsString('Revisar usuarios', $response->getContent());
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
}
