<?php

namespace App\Tests\Unit;

use App\Controller\Web\BackendUiController;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class BackendUiControllerTest extends TestCase
{
    public function testLoginPageRendersBrowserForm(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $authenticationUtils = $this->createStub(AuthenticationUtils::class);
        $authenticationUtils->method('getLastAuthenticationError')->willReturn(null);
        $authenticationUtils->method('getLastUsername')->willReturn('federicomartin2609@gmail.com');

        $controller = new BackendUiController($security);
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

        $controller = new BackendUiController($security);
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

        $controller = new BackendUiController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Backend administrativo', $response->getContent());
        self::assertStringContainsString('/logout', $response->getContent());
        self::assertStringContainsString('/backend/profile', $response->getContent());
        self::assertStringContainsString('/backend/playbooks', $response->getContent());
        self::assertStringContainsString('Admin', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('Salir', $response->getContent());
        self::assertStringContainsString('Sales Agent CRM', $response->getContent());
    }

    public function testProfileRendersCurrentSessionSummary(): void
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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, ['ROLE_ADMIN', 'ROLE_MANAGER'], true));

        $controller = new BackendUiController($security);
        $response = $controller->profile();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Mi perfil', $response->getContent());
        self::assertStringContainsString('admin@example.com', $response->getContent());
        self::assertStringContainsString('/backend/dashboard', $response->getContent());
    }

    public function testDashboardRedirectsWhenNoUserIsAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $controller = new BackendUiController($security);
        $response = $controller->dashboard();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }

    public function testIndexRedirectsToLoginWhenNotAuthenticated(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        $controller = new BackendUiController($security);
        $response = $controller->index();

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }
}
