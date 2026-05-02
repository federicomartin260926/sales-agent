<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

final class InternalBearerTokenValidator
{
    public function __construct(
        #[Autowire('%env(string:SALES_AGENT_BEARER_TOKEN)%')]
        private readonly string $bearerToken = '',
    ) {
    }

    public function isAuthorized(Request $request): bool
    {
        if ($this->bearerToken === '') {
            return false;
        }

        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if ($authorization === '') {
            return false;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            return false;
        }

        return hash_equals($this->bearerToken, trim($matches[1]));
    }
}
