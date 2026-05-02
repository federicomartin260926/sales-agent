<?php

namespace App\Tests\Unit;

use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalBearerTokenValidatorTest extends TestCase
{
    public function testAcceptsMatchingBearerToken(): void
    {
        $validator = new InternalBearerTokenValidator('test-internal-token');
        $request = Request::create('/api/internal/runtime-settings', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer test-internal-token',
        ]);

        self::assertTrue($validator->isAuthorized($request));
    }

    public function testRejectsMissingBearerToken(): void
    {
        $validator = new InternalBearerTokenValidator('test-internal-token');
        $request = Request::create('/api/internal/runtime-settings', 'GET');

        self::assertFalse($validator->isAuthorized($request));
    }

    public function testRejectsWrongBearerToken(): void
    {
        $validator = new InternalBearerTokenValidator('test-internal-token');
        $request = Request::create('/api/internal/runtime-settings', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-token',
        ]);

        self::assertFalse($validator->isAuthorized($request));
    }
}
