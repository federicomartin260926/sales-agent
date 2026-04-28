<?php

namespace App\Tests\Unit;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserRolesTest extends TestCase
{
    public function testLogicalRolesAreMappedToSymfonyRoles(): void
    {
        $user = new User('agent@example.com', ['agent', 'manager']);
        $roles = $user->getRoles();

        self::assertContains('ROLE_AGENT', $roles);
        self::assertContains('ROLE_MANAGER', $roles);
    }
}
