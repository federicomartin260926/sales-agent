<?php

namespace App\Tests\Unit;

use App\Controller\Api\LoginController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;

final class LoginControllerTest extends TestCase
{
    public function testLoginActionIsRegisteredAsPostRoute(): void
    {
        $reflection = new \ReflectionMethod(LoginController::class, '__invoke');
        $attributes = $reflection->getAttributes(Route::class);

        self::assertCount(1, $attributes);

        /** @var Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/api/login', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testLoginIndexIsRegisteredAsGetRoute(): void
    {
        $reflection = new \ReflectionMethod(LoginController::class, 'index');
        $attributes = $reflection->getAttributes(Route::class);

        self::assertCount(1, $attributes);

        /** @var Route $route */
        $route = $attributes[0]->newInstance();

        self::assertSame('/api/login', $route->getPath());
        self::assertSame(['GET'], $route->getMethods());
    }
}
