<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use App\Repository\TenantMembershipRepository;
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
use Twig\Loader\ArrayLoader;

final class BackendUiTenantAccessTest extends TestCase
{
    public function testSuperAdminCanEnterAnyTenantWithoutMembership(): void
    {
        $tenantA = $this->tenant('Tech Investments');
        $tenantB = $this->tenant('Northwind');
        $user = new User('owner@example.com', ['super_admin'], 'Owner');
        $resolver = $this->resolver($user, [$tenantA, $tenantB], []);

        $request = Request::create('/backend/tenants/'.$tenantB->getId()->toRfc4122().'/enter', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenantA, $tenantB], $tenantA);
        $controller = $this->controller($user, $context, $resolver);
        $tenants = $this->tenantRepository([$tenantA, $tenantB]);

        $response = $controller->tenantEnter($tenantB->getId()->toRfc4122(), $request, $tenants);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/tenants/'.$tenantB->getId()->toRfc4122().'/edit', $response->headers->get('Location'));
        self::assertSame($tenantB->getId()->toRfc4122(), $context->getActiveTenantId());
    }

    public function testTenantEnterRejectsForeignTenantAndClearsSession(): void
    {
        $tenantA = $this->tenant('Tech Investments');
        $tenantB = $this->tenant('Northwind');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $resolver = $this->resolver($user, [$tenantA], [$this->membership($user, $tenantA, 'manager')]);

        $request = Request::create('/backend/tenants/'.$tenantB->getId()->toRfc4122().'/enter', 'POST', [
            '_csrf_token' => 'token',
        ]);
        $request->setSession(new Session());
        $context = $this->activeTenantContext($request, [$tenantA, $tenantB], $tenantB);
        $controller = $this->controller($user, $context, $resolver);
        $tenants = $this->tenantRepository([$tenantA, $tenantB]);

        $response = $controller->tenantEnter($tenantB->getId()->toRfc4122(), $request, $tenants);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertFalse($context->hasActiveTenant());
    }

    public function testNonSuperAdminCannotCreateTenants(): void
    {
        $tenant = $this->tenant('Tech Investments');
        $user = new User('manager@example.com', ['manager'], 'Manager');
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $controller = $this->controller($user, $this->activeTenantContext($request, [$tenant], $tenant));

        $response = $controller->tenantCreate(Request::create('/backend/tenants/new', 'GET'));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
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

    private function controller(User $user, ActiveTenantContext $context, ?TenantAccessResolver $resolver = null): BackendUiController
    {
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

        $csrfTokenManager = $this->createStub(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('isTokenValid')->willReturn(true);

        $controller = new BackendUiController(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(RuntimeConfigurationService::class),
            $context,
            new Environment(new ArrayLoader(), ['cache' => false]),
            null,
            null,
            $csrfTokenManager,
            $resolver,
        );

        return $controller;
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

    /**
     * @param TenantMembership[] $memberships
     */
    private function resolver(User $user, array $accessibleTenants, array $memberships): TenantAccessResolver
    {
        return new TenantAccessResolver(
            $this->tenantRepository($accessibleTenants),
            new class($user, $memberships) extends TenantMembershipRepository {
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

    private function tenant(string $name): Tenant
    {
        return new Tenant($name, strtolower(str_replace(' ', '-', $name)));
    }

    private function membership(User $user, Tenant $tenant, string $role): TenantMembership
    {
        return new TenantMembership($user, $tenant, $role);
    }
}
