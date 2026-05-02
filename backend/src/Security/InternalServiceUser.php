<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

final class InternalServiceUser implements UserInterface
{
    public function getUserIdentifier(): string
    {
        return 'internal-service';
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_INTERNAL_SERVICE'];
    }

    public function eraseCredentials(): void
    {
    }
}
