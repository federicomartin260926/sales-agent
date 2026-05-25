<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class BackendUiUserCreationTest extends TestCase
{
    public function testUsersPageShowsNewUserButtonForSuperAdmin(): void
    {
        $security = $this->security(['super_admin'], 'owner@example.com', 'Owner');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($this->userRepository([]));

        $controller = $this->controller($security, $entityManager);
        $response = $controller->users();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Nuevo usuario', $response->getContent());
        self::assertStringContainsString('/backend/users/new', $response->getContent());
    }

    public function testNonSuperAdminCannotAccessNewUserForm(): void
    {
        $security = $this->security(['manager'], 'manager@example.com', 'Manager');
        $controller = $this->controller($security);

        $response = $controller->userCreate(Request::create('/backend/users/new', 'GET'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/dashboard', $response->headers->get('Location'));
    }

    public function testCreatesManagerWithTenantMembershipAndHashedPassword(): void
    {
        $tenant = $this->tenant('Tech Investments', 'tech-investments');
        $security = $this->security(['super_admin'], 'owner@example.com', 'Owner');
        $userRepository = $this->userRepository([]);
        $tenantRepository = $this->tenantRepository([$tenant]);
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), 'TempPass123!')
            ->willReturn('hashed-password');

        $controller = $this->controller($security, $entityManager, $passwordHasher, $tenantRepository);
        $request = Request::create('/backend/users/new', 'POST', [
            '_csrf_token' => 'token',
            'email' => 'manager@example.com',
            'password' => 'TempPass123!',
            'passwordConfirmation' => 'TempPass123!',
            'role' => 'manager',
            'isActive' => '1',
            'tenantIds' => [$tenant->getId()->toRfc4122()],
            'membershipRole' => 'manager',
        ]);

        $response = $controller->userCreate($request, null, $tenantRepository);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/users', $response->headers->get('Location'));
        self::assertCount(2, $persisted);
        self::assertInstanceOf(User::class, $persisted[0]);
        self::assertInstanceOf(TenantMembership::class, $persisted[1]);
        self::assertSame('hashed-password', $persisted[0]->getPassword());
        self::assertContains('ROLE_MANAGER', $persisted[0]->getRoles());
        self::assertSame($tenant->getId()->toRfc4122(), $persisted[1]->getTenant()->getId()->toRfc4122());
        self::assertSame('manager', $persisted[1]->getRole());
    }

    public function testRejectsNonSuperAdminUserWithoutTenant(): void
    {
        $security = $this->security(['super_admin'], 'owner@example.com', 'Owner');
        $tenantRepository = $this->tenantRepository([ $this->tenant('Tech Investments', 'tech-investments') ]);
        $controller = $this->controller($security);

        $response = $controller->userCreate(Request::create('/backend/users/new', 'POST', [
            '_csrf_token' => 'token',
            'email' => 'manager@example.com',
            'password' => 'TempPass123!',
            'passwordConfirmation' => 'TempPass123!',
            'role' => 'manager',
            'isActive' => '1',
            'membershipRole' => 'manager',
        ]), null, $tenantRepository);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Debes asignar al menos un tenant a un usuario no-super-admin.', $response->getContent());
    }

    public function testCreatesSuperAdminWithoutTenantMembership(): void
    {
        $security = $this->security(['super_admin'], 'owner@example.com', 'Owner');
        $userRepository = $this->userRepository([]);
        $persisted = [];

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(1))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);

        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $passwordHasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), 'TempPass123!')
            ->willReturn('hashed-password');

        $controller = $this->controller($security, $entityManager, $passwordHasher);
        $response = $controller->userCreate(Request::create('/backend/users/new', 'POST', [
            '_csrf_token' => 'token',
            'email' => 'owner@example.com',
            'password' => 'TempPass123!',
            'passwordConfirmation' => 'TempPass123!',
            'role' => 'super_admin',
            'isActive' => '1',
            'membershipRole' => 'manager',
        ]), null, $this->tenantRepository());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/users', $response->headers->get('Location'));
        self::assertCount(1, $persisted);
        self::assertInstanceOf(User::class, $persisted[0]);
        self::assertSame('hashed-password', $persisted[0]->getPassword());
        self::assertContains('ROLE_SUPER_ADMIN', $persisted[0]->getRoles());
    }

    public function testRejectsDuplicateEmail(): void
    {
        $existingUser = new User('existing@example.com', ['admin']);
        $security = $this->security(['super_admin'], 'owner@example.com', 'Owner');
        $userRepository = $this->userRepository([$existingUser]);
        $tenantRepository = $this->tenantRepository([$this->tenant('Tech Investments', 'tech-investments')]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(User::class)
            ->willReturn($userRepository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $controller = $this->controller($security, $entityManager, null, $tenantRepository);
        $response = $controller->userCreate(Request::create('/backend/users/new', 'POST', [
            '_csrf_token' => 'token',
            'email' => 'existing@example.com',
            'password' => 'TempPass123!',
            'passwordConfirmation' => 'TempPass123!',
            'role' => 'manager',
            'isActive' => '1',
            'tenantIds' => [$tenantRepository->findAllOrdered()[0]->getId()->toRfc4122()],
            'membershipRole' => 'manager',
        ]), null, $tenantRepository);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Ya existe un usuario con ese email.', $response->getContent());
    }

    private function controller(
        Security $security,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?TenantRepository $tenantRepository = null,
    ): BackendUiController {
        $entityManager ??= $this->createStub(EntityManagerInterface::class);
        $passwordHasher ??= $this->createStub(UserPasswordHasherInterface::class);
        $tenantRepository ??= $this->tenantRepository();

        $requestStack = new RequestStack();
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $requestStack->push($request);

        $activeTenantContext = new \App\Service\ActiveTenantContext($requestStack, $tenantRepository);

        return new BackendUiController(
            $security,
            $entityManager,
            $passwordHasher,
            $this->createStub(\App\Service\RuntimeConfigurationService::class),
            $activeTenantContext,
            $this->twig(),
            null,
            null,
            null,
            null,
        );
    }

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(__DIR__.'/../../templates'), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    /**
     * @param User[] $users
     */
    private function userRepository(array $users = []): UserRepository
    {
        return new class($users) extends UserRepository {
            /**
             * @param User[] $users
             */
            public function __construct(private array $users)
            {
            }

            public function findBy(array $criteria, array|null $orderBy = null, $limit = null, $offset = null): array
            {
                return $this->users;
            }

            public function findOneByEmail(string $email): ?User
            {
                foreach ($this->users as $user) {
                    if ($user->getEmail() === strtolower(trim($email))) {
                        return $user;
                    }
                }

                return null;
            }
        };
    }

    /**
     * @param Tenant[] $tenants
     */
    private function tenantRepository(array $tenants = []): TenantRepository
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
        };
    }

    private function security(array $roles, string $email, ?string $name = null): Security
    {
        $security = $this->createStub(Security::class);
        $user = new User($email, $roles, $name);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static function (string $role) use ($user): bool {
            $roles = $user->getRoles();

            if (in_array('ROLE_SUPER_ADMIN', $roles, true) && !in_array('ROLE_ADMIN', $roles, true)) {
                $roles[] = 'ROLE_ADMIN';
            }

            if (in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_MANAGER', $roles, true)) {
                $roles[] = 'ROLE_MANAGER';
            }

            if (in_array('ROLE_MANAGER', $roles, true) && !in_array('ROLE_AGENT', $roles, true)) {
                $roles[] = 'ROLE_AGENT';
            }

            return in_array($role, $roles, true);
        });

        return $security;
    }

    private function tenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setActive(true);

        return $tenant;
    }
}
