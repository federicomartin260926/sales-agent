<?php

namespace App\Controller\Api;

use App\Service\RuntimeConfigurationService;
use App\Security\InternalBearerTokenValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class InternalRuntimeSettingsController extends AbstractApiController
{
    public function __construct(
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    #[Route('/api/internal/runtime-settings', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        return $this->json($this->runtimeConfigurationService->snapshot());
    }
}
