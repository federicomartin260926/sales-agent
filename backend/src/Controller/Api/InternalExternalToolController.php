<?php

namespace App\Controller\Api;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/external-tools/{tenantId}/{type}', methods: ['GET'])]
final class InternalExternalToolController extends AbstractApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    public function __invoke(string $tenantId, string $type, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $externalTool = $this->externalTools->findActiveByTenantAndType($tenant, $type);
        if (!$externalTool instanceof ExternalTool) {
            return $this->json([
                'ok' => true,
                'tool' => null,
            ]);
        }

        return $this->json([
            'ok' => true,
            'tool' => $this->toolPayload($externalTool),
        ]);
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = $this->tenants->find(trim($tenantId));
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return $tenant;
    }

    private function toolPayload(ExternalTool $externalTool): array
    {
        return [
            'id' => $externalTool->getId()->toRfc4122(),
            'tenant_id' => $externalTool->getTenant()->getId()->toRfc4122(),
            'name' => $externalTool->getName(),
            'type' => $externalTool->getType(),
            'provider' => $externalTool->getProvider(),
            'webhook_url' => $externalTool->getWebhookUrl(),
            'auth_type' => $externalTool->getAuthType(),
            'bearer_token' => $externalTool->getBearerToken(),
            'timeout_seconds' => $externalTool->getTimeoutSeconds(),
            'config' => $externalTool->getConfig(),
        ];
    }
}
