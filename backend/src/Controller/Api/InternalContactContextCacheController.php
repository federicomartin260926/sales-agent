<?php

namespace App\Controller\Api;

use App\Entity\ExternalContactContextCache;
use App\Repository\ExternalContactContextCacheRepository;
use App\Security\InternalBearerTokenValidator;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/contact-context-cache')]
final class InternalContactContextCacheController extends AbstractApiController
{
    public function __construct(
        private readonly ExternalContactContextCacheRepository $caches,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenantId = trim((string) $request->query->get('tenant_id', ''));
        $contactKey = trim((string) $request->query->get('contact_key', ''));
        $provider = trim((string) $request->query->get('provider', 'contact_context'));
        if ($tenantId === '' || $contactKey === '') {
            return $this->badRequest('tenant_id and contact_key are required');
        }

        $cache = $this->caches->findLatestByTenantContactKeyProvider($tenantId, $contactKey, $provider);
        if (!$cache instanceof ExternalContactContextCache) {
            return $this->json(['cache' => null], JsonResponse::HTTP_OK);
        }

        return $this->json(['cache' => $cache->toArray()]);
    }

    #[Route('', methods: ['POST'])]
    public function upsert(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $this->readJson($request);
        $tenantId = $this->normalizeNullableString($data['tenant_id'] ?? null);
        $contactKey = $this->normalizeNullableString($data['contact_key'] ?? null);
        if ($tenantId === null || $contactKey === null) {
            return $this->badRequest('tenant_id and contact_key are required');
        }

        $provider = $this->normalizeNullableString($data['provider'] ?? null) ?? 'contact_context';
        $cache = $this->caches->findLatestByTenantContactKeyProvider($tenantId, $contactKey, $provider);
        if (!$cache instanceof ExternalContactContextCache) {
            $cache = new ExternalContactContextCache($tenantId, $contactKey);
        }

        $cache->setTenantId($tenantId);
        $cache->setContactKey($contactKey);
        $cache->setProvider($provider);
        $cache->setSource($this->normalizeNullableString($data['source'] ?? null) ?? 'mcp');
        $cache->setStatus($this->normalizeNullableString($data['status'] ?? null) ?? 'success');
        $cache->setChannel($this->normalizeNullableString($data['channel'] ?? null));
        $cache->setExternalChannelId($this->normalizeNullableString($data['external_channel_id'] ?? null));
        $cache->setExternalConversationId($this->normalizeNullableString($data['external_conversation_id'] ?? null));
        $cache->setContactPhone($this->normalizeNullableString($data['contact_phone'] ?? null));
        $cache->setContactEmail($this->normalizeNullableString($data['contact_email'] ?? null));
        $cache->setContextJson(is_array($data['context_json'] ?? null) ? $data['context_json'] : null);

        $fetchedAt = $this->normalizeDateTime($data['fetched_at'] ?? null) ?? new DateTimeImmutable();
        $expiresAt = $this->normalizeDateTime($data['expires_at'] ?? null);
        if (!$expiresAt instanceof DateTimeImmutable) {
            $ttlMinutes = $this->normalizeTtlMinutes($data['ttl_minutes'] ?? null);
            $expiresAt = $fetchedAt->add(new DateInterval('PT'.$ttlMinutes.'M'));
        }
        $cache->setFetchedAt($fetchedAt);
        $cache->setExpiresAt($expiresAt);
        $cache->touch();

        $this->caches->save($cache);

        return $this->json([
            'updated' => true,
            'cache' => $cache->toArray(),
        ]);
    }

    #[Route('/invalidate', methods: ['POST', 'PATCH'])]
    public function invalidate(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $data = $this->readJson($request);
        $tenantId = $this->normalizeNullableString($data['tenant_id'] ?? null);
        $contactKey = $this->normalizeNullableString($data['contact_key'] ?? null);
        if ($tenantId === null || $contactKey === null) {
            return $this->badRequest('tenant_id and contact_key are required');
        }

        $cache = $this->caches->findLatestByTenantContactKeyProvider($tenantId, $contactKey, $this->normalizeNullableString($data['provider'] ?? null) ?? 'contact_context');
        if (!$cache instanceof ExternalContactContextCache) {
            return $this->json(['updated' => false, 'cache' => null], JsonResponse::HTTP_OK);
        }

        $cache->setStatus('stale');
        $cache->setExpiresAt(new DateTimeImmutable('-1 second'));
        $cache->touch();
        $this->caches->save($cache);

        return $this->json([
            'updated' => true,
            'cache' => $cache->toArray(),
        ]);
    }

    private function normalizeTtlMinutes(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 360;
        }

        $ttlMinutes = (int) $value;
        return $ttlMinutes > 0 ? $ttlMinutes : 360;
    }

    private function normalizeDateTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable(trim($value));
        } catch (\Throwable) {
            return null;
        }
    }
}
