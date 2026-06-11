<?php

namespace App\Controller\Api;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use App\Service\RuntimeSettingCipher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/mcp/{tenantId}/config', methods: ['GET'])]
final class InternalMcpConfigController extends AbstractApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ExternalToolRepository $externalTools,
        private readonly RuntimeSettingCipher $cipher,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    public function __invoke(string $tenantId, Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $tool = $this->externalTools->findRuntimeDefaultMcpByTenant($tenant);
        if (!$tool instanceof ExternalTool) {
            $candidates = $this->externalTools->findActiveMcpCandidatesByTenant($tenant);
            if (count($candidates) === 1 && $candidates[0] instanceof ExternalTool) {
                $tool = $candidates[0];
            }
        }
        if (!$tool instanceof ExternalTool || !$tool->isEnabledForLlm()) {
            return $this->json([
                'enabled' => false,
                'tools' => [],
            ]);
        }

        return $this->json($this->payload($tool));
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = $this->tenants->find(trim($tenantId));
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            return null;
        }

        return $tenant;
    }

    private function payload(ExternalTool $tool): array
    {
        $bearerToken = $this->decryptToken($tool->getBearerToken());
        $downstreamAuthorizationToken = $this->decryptToken($tool->getDownstreamAuthorizationToken()) ?? $bearerToken;

        return [
            'enabled' => true,
            'tool_id' => $tool->getId()->toRfc4122(),
            'tenant_id' => $tool->getTenant()->getId()->toRfc4122(),
            'provider' => $tool->getProvider(),
            'type' => $tool->getType(),
            'server_label' => $tool->getServerLabel() ?? $tool->getName(),
            'server_url' => $tool->getWebhookUrl(),
            'auth_type' => $tool->getAuthType(),
            'bearer_token' => $bearerToken,
            'downstream_authorization_token' => $downstreamAuthorizationToken,
            'downstream_authorization_configured' => $downstreamAuthorizationToken !== null && $downstreamAuthorizationToken !== '',
            'allowed_tools' => $tool->getAllowedTools(),
            'require_approval' => $tool->getRequireApproval() ?? 'auto',
            'timeout_seconds' => $tool->getTimeoutSeconds(),
            'config' => $this->normalizeObjectLike($tool->getConfig()),
        ];
    }

    private function decryptToken(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        try {
            $decrypted = $this->cipher->decrypt($token);
        } catch (\Throwable) {
            return null;
        }

        return trim($decrypted) !== '' ? trim($decrypted) : null;
    }

    private function normalizeObjectLike(mixed $value): mixed
    {
        if (is_array($value)) {
            return (object) $value;
        }

        return $value;
    }
}
