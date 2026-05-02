<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\EntryPoint;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\EntryPointRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeConfigurationService;
use App\Service\ProductCatalogImportService;
use PHPUnit\Framework\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
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
    ): BackendUiController {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $passwordHasher ??= $this->createStub(UserPasswordHasherInterface::class);
        $csrfTokenManager ??= $this->createStub(CsrfTokenManagerInterface::class);
        $runtimeConfigurationService ??= $this->createStub(RuntimeConfigurationService::class);
        $twig ??= $this->createTwigEnvironment();

        return new BackendUiController($security, $entityManager, $passwordHasher, $runtimeConfigurationService, $twig, $productCatalogImportService, $csrfTokenManager);
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
        self::assertStringContainsString('/backend/entry-points', $response->getContent());
        self::assertStringContainsString('Admin', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('Salir', $response->getContent());
        self::assertStringContainsString('Negocios', $response->getContent());
    }

    public function testUsersRendersTwigListForAdmins(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

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
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $tenants = $this->createTenantRepositoryFake([$tenant]);

        $controller = $this->createController($security);
        $response = $controller->tenants(Request::create('/backend/tenants', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear negocio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('aria-label="Editar negocio"', $response->getContent());
        self::assertStringContainsString('aria-label="Eliminar negocio"', $response->getContent());
        self::assertStringContainsString('/backend/tenants/new', $response->getContent());
        self::assertStringContainsString('/backend/tenants/', $response->getContent());
        self::assertStringContainsString('Contexto:', $response->getContent());
        self::assertStringContainsString('Tono:', $response->getContent());
    }

    public function testTenantDeleteRemovesTenantAndShowsFlashOnListing(): void
    {
        $tenant = new Tenant('Federico Martin Demo', 'federico-martin-demo');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($this->createAuthenticatedUser('manager@example.com', ['manager'], 'María Manager'));
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('remove')->with($tenant);
        $entityManager->expects(self::once())->method('flush');

        $tenants = $this->createTenantRepositoryFake([$tenant], $tenant);

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);
        $csrfTokenManager->method('getToken')->willReturnCallback(
            static fn (string $id): CsrfToken => new CsrfToken($id, 'token-'.$id)
        );

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
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
        self::assertStringContainsString('name="whatsappPhoneNumberId"', $response->getContent());
        self::assertStringContainsString('name="whatsappPublicPhone"', $response->getContent());
        self::assertStringContainsString('WhatsApp Business', $response->getContent());
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
                    && $tenant->getWhatsappPhoneNumberId() === '123456789012345'
                    && $tenant->getWhatsappPublicPhone() === '34612345678'
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
        self::assertSame('/backend/tenants', $response->headers->get('Location'));
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
        self::assertStringContainsString('name="whatsappPhoneNumberId"', $response->getContent());
        self::assertStringContainsString('name="whatsappPublicPhone"', $response->getContent());
        self::assertStringContainsString('value="123456789012345"', $response->getContent());
        self::assertStringContainsString('value="34612345678"', $response->getContent());
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
            'whatsappPhoneNumberId' => '123456789012345',
            'whatsappPublicPhone' => '34612345678',
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
        $response = $controller->playbooks(Request::create('/backend/playbooks', 'GET'), $playbooks);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Crear guía comercial', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar guía comercial', $response->getContent());
        self::assertStringContainsString('Eliminar guía comercial', $response->getContent());
        self::assertStringContainsString('Guía comercial demo', $response->getContent());
        self::assertStringContainsString('Resumen:', $response->getContent());
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

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
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

        $controller = $this->createController($security);
        $response = $controller->products(Request::create('/backend/products', 'GET'), $products, $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Importar catálogo', $response->getContent());
        self::assertStringContainsString('Crear producto / servicio', $response->getContent());
        self::assertStringContainsString('icon-action', $response->getContent());
        self::assertStringContainsString('Editar producto / servicio', $response->getContent());
        self::assertStringContainsString('Eliminar producto / servicio', $response->getContent());
        self::assertStringContainsString('/backend/products/'.$product->getId()->toRfc4122().'/delete', $response->getContent());
        self::assertStringContainsString('name="tenantId"', $response->getContent());
        self::assertStringContainsString('name="product"', $response->getContent());
        self::assertStringContainsString('WhatsApp Automation', $response->getContent());
        self::assertStringContainsString('Slug:', $response->getContent());
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

        $controller = $this->createController($security);
        $request = Request::create('/backend/products', 'GET', [
            'tenantId' => $tenantA->getId()->toRfc4122(),
            'product' => 'whatsapp',
        ]);

        $response = $controller->products($request, $products, $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('WhatsApp Automation', $response->getContent());
        self::assertStringNotContainsString('RAG Knowledge System', $response->getContent());
        self::assertStringContainsString('value="whatsapp"', $response->getContent());
        self::assertStringContainsString('selected', $response->getContent());
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

        $controller = $this->createController($security);
        $response = $controller->productImport(Request::create('/backend/products/import', 'GET'), $tenants);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Importar catálogo de productos / servicios', $response->getContent());
        self::assertStringContainsString('Catálogo independiente', $response->getContent());
        self::assertStringContainsString('name="tenantId"', $response->getContent());
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

        $controller = $this->createController($security, null, null, $csrfTokenManager);
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

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
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

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
        $response = $controller->productCreate(Request::create('/backend/products/new', 'POST', [
            '_csrf_token' => 'token',
            'tenantId' => $tenant->getId()->toRfc4122(),
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

        $controller = $this->createController($security);
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

        $controller = $this->createController($security);
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

        $controller = $this->createController($security, $entityManager, null, $csrfTokenManager);
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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

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
        self::assertStringContainsString('class="alert-dismiss"', $response->getContent());
        self::assertStringContainsString('aria-label="Cerrar mensaje"', $response->getContent());
    }

    public function testConfigurationSaveRejectsInvalidRuntimeEndpoints(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');

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
