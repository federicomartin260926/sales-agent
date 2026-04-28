<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use App\Entity\User;
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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_MANAGER', 'ROLE_ADMIN'], true));

        $controller = $this->createController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Panel comercial de negocios', $response->getContent());
        self::assertStringContainsString('/logout', $response->getContent());
        self::assertStringContainsString('/backend/profile', $response->getContent());
        self::assertStringContainsString('/backend/playbooks', $response->getContent());
        self::assertStringContainsString('Admin', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('Salir', $response->getContent());
        self::assertStringContainsString('Negocios', $response->getContent());
    }

    public function testProfileRendersCurrentSessionSummary(): void
    {
        $user = $this->createAuthenticatedUser('admin@example.com', ['admin'], 'Federico Martín');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_ADMIN', 'ROLE_MANAGER'], true));

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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_ADMIN', 'ROLE_MANAGER'], true));

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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_ADMIN', 'ROLE_MANAGER'], true));

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
