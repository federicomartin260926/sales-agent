<?php

namespace App\Controller\Api;

use App\Service\RuntimeConfigurationService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InternalRuntimeSettingsController extends AbstractApiController
{
    public function __construct(
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        #[Autowire('%env(SALES_AGENT_BEARER_TOKEN)%')]
        private readonly string $bearerToken = '',
    ) {
    }

    #[Route('/api/internal/runtime-settings', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->runtimeConfigurationService->snapshot());
    }

    private function isAuthorized(Request $request): bool
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
