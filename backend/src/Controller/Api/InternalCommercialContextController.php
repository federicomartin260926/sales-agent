<?php

namespace App\Controller\Api;

use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/internal/commercial-context', methods: ['GET'])]
final class InternalCommercialContextController extends AbstractApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly ProductRepository $products,
        private readonly PlaybookRepository $playbooks,
        private readonly EntryPointRepository $entryPoints,
        private readonly EntryPointUtmRepository $entryPointUtms,
        private readonly InternalBearerTokenValidator $validator,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->validator->isAuthorized($request)) {
            return $this->json(['message' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        $tenantId = trim((string) $request->query->get('tenant_id', ''));
        if ($tenantId === '') {
            return $this->badRequest('tenant_id is required');
        }

        $tenant = $this->resolveTenant($tenantId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found or inactive');
        }

        $entryPointParamProvided = trim((string) $request->query->get('entrypoint_ref', '')) !== '' || trim((string) $request->query->get('entry_point_id', '')) !== '';
        $productParamProvided = trim((string) $request->query->get('product_id', '')) !== '';
        $playbookParamProvided = trim((string) $request->query->get('playbook_id', '')) !== '';

        $entryPoint = $this->resolveEntryPoint($request, $tenant);
        if ($entryPointParamProvided && !$entryPoint instanceof EntryPoint) {
            return $this->notFound('Entry point not found');
        }

        $product = $this->resolveProduct($request, $tenant, $entryPoint);
        if ($productParamProvided && !$product instanceof Product) {
            return $this->notFound('Product not found');
        }

        $playbook = $this->resolvePlaybook($request, $tenant, $entryPoint, $product);
        if ($playbookParamProvided && !$playbook instanceof Playbook) {
            return $this->notFound('Playbook not found');
        }

        $externalChannelId = trim((string) $request->query->get('external_channel_id', ''));
        $customerPhone = trim((string) $request->query->get('customer_phone', ''));

        return $this->json([
            'tenant' => $this->tenantPayload($tenant),
            'product' => $this->productPayload($product),
            'playbook' => $this->playbookPayload($playbook),
            'entry_point' => $this->entryPointPayload($entryPoint),
            'routing' => [
                'entrypoint_ref' => $this->normalizeNullableString($request->query->get('entrypoint_ref')),
                'external_channel_id' => $externalChannelId !== '' ? $externalChannelId : $tenant->getWhatsappPhoneNumberId(),
                'customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                'source' => 'commercial_context',
            ],
            'sales_runtime' => [
                'has_product_context' => $product instanceof Product,
                'has_playbook_context' => $playbook instanceof Playbook,
                'has_entry_point_context' => $entryPoint instanceof EntryPoint,
                'handoff_enabled' => $this->hasHandoffEnabled($tenant, $product, $playbook),
                'booking_enabled' => $this->hasBookingEnabled($playbook),
                'rag_enabled' => $this->hasRagEnabled($tenant, $product, $playbook),
            ],
        ]);
    }

    private function resolveTenant(string $tenantId): ?Tenant
    {
        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant || !$tenant->isActive()) {
            return null;
        }

        return $tenant;
    }

    private function resolveEntryPoint(Request $request, Tenant $tenant): ?EntryPoint
    {
        $entrypointRef = trim((string) $request->query->get('entrypoint_ref', ''));
        if ($entrypointRef !== '') {
            $entryPointUtm = $this->entryPointUtms->findByRef($entrypointRef);
            if (!$entryPointUtm instanceof EntryPointUtm) {
                return null;
            }

            $entryPoint = $entryPointUtm->getEntryPoint();
            if ($entryPoint->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return null;
            }

            if (!$entryPoint->isActive()) {
                return null;
            }

            return $entryPoint;
        }

        $entryPointId = trim((string) $request->query->get('entry_point_id', ''));
        if ($entryPointId === '') {
            return null;
        }

        $entryPoint = $this->entryPoints->find($entryPointId);
        if (!$entryPoint instanceof EntryPoint || !$entryPoint->isActive()) {
            return null;
        }

        if ($entryPoint->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return null;
        }

        return $entryPoint;
    }

    private function resolveProduct(Request $request, Tenant $tenant, ?EntryPoint $entryPoint): ?Product
    {
        $productId = trim((string) $request->query->get('product_id', ''));
        if ($productId !== '') {
            $product = $this->products->find($productId);
            if (!$product instanceof Product || !$product->isActive()) {
                return null;
            }

            if ($product->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return null;
            }

            return $product;
        }

        if ($entryPoint instanceof EntryPoint) {
            $product = $entryPoint->getProduct();
            if ($product->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122() || !$product->isActive()) {
                return null;
            }

            return $product;
        }

        return null;
    }

    private function resolvePlaybook(Request $request, Tenant $tenant, ?EntryPoint $entryPoint, ?Product $product): ?Playbook
    {
        $playbookId = trim((string) $request->query->get('playbook_id', ''));
        if ($playbookId !== '') {
            $playbook = $this->playbooks->find($playbookId);
            if (!$playbook instanceof Playbook || !$playbook->isActive()) {
                return null;
            }

            if ($playbook->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return null;
            }

            return $playbook;
        }

        if ($entryPoint instanceof EntryPoint) {
            $playbook = $entryPoint->getPlaybook();
            if ($playbook instanceof Playbook && $playbook->isActive() && $playbook->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()) {
                return $playbook;
            }
        }

        return $this->playbooks->findActiveGeneralByTenant($tenant);
    }

    private function tenantPayload(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getId()->toRfc4122(),
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'business_context' => $tenant->getBusinessContext(),
            'tone' => $tenant->getTone(),
            'sales_policy' => $this->normalizeObjectLike($tenant->getSalesPolicy()),
            'is_active' => $tenant->isActive(),
            'whatsapp_phone_number_id' => $tenant->getWhatsappPhoneNumberId(),
            'whatsapp_public_phone' => $tenant->getWhatsappPublicPhone(),
        ];
    }

    private function productPayload(?Product $product): ?array
    {
        if (!$product instanceof Product) {
            return null;
        }

        return [
            'id' => $product->getId()->toRfc4122(),
            'tenant_id' => $product->getTenant()->getId()->toRfc4122(),
            'name' => $product->getName(),
            'slug' => $product->getSlug(),
            'description' => $product->getDescription(),
            'value_proposition' => $product->getValueProposition(),
            'sales_policy' => $this->normalizeObjectLike($product->getSalesPolicy()),
            'base_price_cents' => $product->getBasePriceCents(),
            'currency' => $product->getCurrency(),
            'external_source' => $product->getExternalSource(),
            'external_reference' => $product->getExternalReference(),
            'is_active' => $product->isActive(),
        ];
    }

    private function playbookPayload(?Playbook $playbook): ?array
    {
        if (!$playbook instanceof Playbook) {
            return null;
        }

        return [
            'id' => $playbook->getId()->toRfc4122(),
            'tenant_id' => $playbook->getTenant()->getId()->toRfc4122(),
            'product_id' => $playbook->getProduct()?->getId()->toRfc4122(),
            'name' => $playbook->getName(),
            'config' => $this->normalizeObjectLike($playbook->getConfig()),
            'is_active' => $playbook->isActive(),
        ];
    }

    private function entryPointPayload(?EntryPoint $entryPoint): ?array
    {
        if (!$entryPoint instanceof EntryPoint) {
            return null;
        }

        return [
            'id' => $entryPoint->getId()->toRfc4122(),
            'code' => $entryPoint->getCode(),
            'name' => $entryPoint->getName(),
            'description' => null,
            'initial_message' => $entryPoint->getDefaultMessage(),
            'crm_branch_ref' => $entryPoint->getCrmBranchRef(),
            'is_active' => $entryPoint->isActive(),
        ];
    }

    private function hasHandoffEnabled(Tenant $tenant, ?Product $product, ?Playbook $playbook): bool
    {
        if ($this->hasNonEmptyValue($tenant->getSalesPolicy(), 'handoffRules')) {
            return true;
        }

        if ($product instanceof Product && $this->hasNonEmptyValue($product->getSalesPolicy(), 'handoffRules')) {
            return true;
        }

        if ($playbook instanceof Playbook) {
            $config = $playbook->getConfig();
            if ($this->hasNonEmptyValue($config, 'handoffRules')) {
                return true;
            }

            $allowedActions = $config['allowedActions'] ?? null;
            if (is_array($allowedActions) && $this->arrayContainsAny($allowedActions, ['handoff', 'handoff_to_human', 'handoffToHuman'])) {
                return true;
            }
        }

        return false;
    }

    private function hasBookingEnabled(?Playbook $playbook): bool
    {
        if (!$playbook instanceof Playbook) {
            return false;
        }

        $config = $playbook->getConfig();
        if ($this->hasNonEmptyValue($config, 'agendaRules')) {
            return true;
        }

        $allowedActions = $config['allowedActions'] ?? null;
        if (is_array($allowedActions) && $this->arrayContainsAny($allowedActions, ['booking', 'book', 'offer_booking', 'propose_meeting', 'meeting'])) {
            return true;
        }

        return false;
    }

    private function hasRagEnabled(Tenant $tenant, ?Product $product, ?Playbook $playbook): bool
    {
        $ragKeys = ['ragKnowledgeBase', 'ragProjectId', 'rag_project_id', 'rag_knowledge_base'];

        foreach ([$tenant->getSalesPolicy(), $product instanceof Product ? $product->getSalesPolicy() : [], $playbook instanceof Playbook ? $playbook->getConfig() : []] as $payload) {
            foreach ($ragKeys as $key) {
                if ($this->hasNonEmptyValue($payload, $key)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasNonEmptyValue(array $payload, string $key): bool
    {
        if (!array_key_exists($key, $payload)) {
            return false;
        }

        $value = $payload[$key];
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }

    /**
     * @param array<int, mixed> $values
     * @param array<int, string> $needles
     */
    private function arrayContainsAny(array $values, array $needles): bool
    {
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $normalized = strtolower(trim($value));
            foreach ($needles as $needle) {
                if ($normalized === strtolower($needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeObjectLike(mixed $value): mixed
    {
        if (is_array($value)) {
            return (object) $value;
        }

        return $value;
    }
}
