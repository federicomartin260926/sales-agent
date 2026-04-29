<?php

namespace App\Controller\Api;

use App\Entity\Conversation;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ConversationRepository;
use App\Repository\EntryPointRepository;
use App\Repository\EntryPointUtmRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\ConversationService;
use App\Service\EntryPointUtmFactory;
use App\Service\RoutingResolver;
use App\Service\WhatsAppRedirectUrlBuilder;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
final class RoutingController extends AbstractApiController
{
    public function __construct(
        private readonly RoutingResolver $routingResolver,
        private readonly EntryPointUtmFactory $entryPointUtmFactory,
        private readonly WhatsAppRedirectUrlBuilder $whatsappRedirectUrlBuilder,
        private readonly EntryPointRepository $entryPoints,
        private readonly EntryPointUtmRepository $entryPointUtms,
        private readonly TenantRepository $tenants,
        private readonly ProductRepository $products,
        private readonly ConversationService $conversationService,
        private readonly ConversationRepository $conversations,
    ) {
    }

    #[Route('/r/wa/{entrypointCode}', methods: ['GET'])]
    public function redirectToWhatsApp(string $entrypointCode, Request $request): JsonResponse|RedirectResponse
    {
        $entryPoint = $this->routingResolver->findEntryPointByCode($entrypointCode);
        if (!$entryPoint instanceof EntryPoint) {
            return $this->notFound('EntryPoint not found');
        }

        if (!$entryPoint->getProduct() instanceof Product) {
            return $this->badRequest('EntryPoint has no product configured');
        }

        $tenant = $entryPoint->getTenant();
        $phone = trim((string) $tenant->getWhatsappPublicPhone());
        if ($phone === '') {
            return $this->badRequest('No public WhatsApp phone is configured for this tenant');
        }

        $entryPointUtm = $this->entryPointUtmFactory->create($entryPoint, [
            'utm_source' => $request->query->get('utm_source'),
            'utm_medium' => $request->query->get('utm_medium'),
            'utm_campaign' => $request->query->get('utm_campaign'),
            'utm_term' => $request->query->get('utm_term'),
            'utm_content' => $request->query->get('utm_content'),
            'gclid' => $request->query->get('gclid'),
            'fbclid' => $request->query->get('fbclid'),
        ]);

        try {
            return new RedirectResponse($this->whatsappRedirectUrlBuilder->build($entryPoint, $entryPointUtm->getRef()), JsonResponse::HTTP_FOUND);
        } catch (InvalidArgumentException $exception) {
            return $this->badRequest($exception->getMessage());
        }
    }

    #[Route('/internal/routing/entrypoint-ref/{ref}', methods: ['GET'])]
    public function resolveEntryPointRef(string $ref): JsonResponse
    {
        $entryPointUtm = $this->routingResolver->findEntryPointUtmByRef($ref);
        if (!$entryPointUtm instanceof EntryPointUtm) {
            return $this->notFound('Entry point ref not found');
        }

        $entryPoint = $entryPointUtm->getEntryPoint();
        $product = $entryPoint->getProduct();
        if (!$product instanceof Product) {
            return $this->notFound('Entry point product not found');
        }
        $tenant = $product->getTenant();

        return $this->json([
            'entry_point_utm_id' => $entryPointUtm->getId()->toRfc4122(),
            'ref' => $entryPointUtm->getRef(),
            'entry_point_id' => $entryPoint->getId()->toRfc4122(),
            'entry_point_code' => $entryPoint->getCode(),
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'tenant_slug' => $tenant->getSlug(),
            'product_id' => $product->getId()->toRfc4122(),
            'product_name' => $product->getName(),
            'playbook_id' => $entryPoint->getPlaybook()?->getId()->toRfc4122(),
            'crm_branch_ref' => $entryPoint->getCrmBranchRef(),
            'utm_source' => $entryPointUtm->getUtmSource(),
            'utm_medium' => $entryPointUtm->getUtmMedium(),
            'utm_campaign' => $entryPointUtm->getUtmCampaign(),
            'utm_term' => $entryPointUtm->getUtmTerm(),
            'utm_content' => $entryPointUtm->getUtmContent(),
            'gclid' => $entryPointUtm->getGclid(),
            'fbclid' => $entryPointUtm->getFbclid(),
            'status' => $entryPointUtm->getStatus(),
        ]);
    }

    #[Route('/internal/routing/whatsapp-phone/{phoneNumberId}', methods: ['GET'])]
    public function resolveWhatsappPhone(string $phoneNumberId): JsonResponse
    {
        $tenant = $this->routingResolver->findTenantByWhatsappPhoneNumberId($phoneNumberId);
        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        return $this->json([
            'tenant_id' => $tenant->getId()->toRfc4122(),
            'tenant_slug' => $tenant->getSlug(),
        ]);
    }

    #[Route('/internal/conversations/upsert', methods: ['POST'])]
    public function upsertConversation(Request $request): JsonResponse
    {
        $data = $this->readJson($request);

        $tenant = $this->resolveTenantFromPayload($data);
        $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
        if (!$tenant instanceof Tenant || $customerPhone === '') {
            return $this->badRequest('tenant_id and customer_phone are required');
        }

        $entryPoint = $this->resolveEntryPointFromPayload($data);
        $entryPointUtm = $this->resolveEntryPointUtmFromPayload($data);
        if ($entryPointUtm instanceof EntryPointUtm && $entryPoint === null) {
            $entryPoint = $entryPointUtm->getEntryPoint();
        }

        $product = $this->resolveProductFromPayload($data, $entryPoint);
        if ($entryPoint instanceof EntryPoint && $product === null) {
            $product = $entryPoint->getProduct();
        }

        $result = $this->conversationService->upsert(
            $tenant,
            $customerPhone,
            $product,
            $entryPoint,
            $entryPointUtm,
            isset($data['customer_name']) ? (string) $data['customer_name'] : null,
            isset($data['first_message']) ? (string) $data['first_message'] : null,
            isset($data['external_conversation_id']) ? (string) $data['external_conversation_id'] : null,
            [
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'utm_term' => $data['utm_term'] ?? null,
                'utm_content' => $data['utm_content'] ?? null,
                'gclid' => $data['gclid'] ?? null,
                'fbclid' => $data['fbclid'] ?? null,
            ],
            isset($data['crm_branch_ref']) ? (string) $data['crm_branch_ref'] : null,
        );

        /** @var Conversation $conversation */
        $conversation = $result['conversation'];

        return $this->json([
            'created' => $result['created'],
            'conversation' => $conversation->toArray(),
            'entry_point_utm_status' => $entryPointUtm?->getStatus(),
        ]);
    }

    private function resolveTenantFromPayload(array $data): ?Tenant
    {
        $tenantId = trim((string) ($data['tenant_id'] ?? ''));
        if ($tenantId === '') {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function resolveEntryPointFromPayload(array $data): ?EntryPoint
    {
        $entryPointId = trim((string) ($data['entry_point_id'] ?? $data['entrypoint_id'] ?? ''));
        if ($entryPointId === '') {
            return null;
        }

        $entryPoint = $this->entryPoints->find($entryPointId);

        return $entryPoint instanceof EntryPoint ? $entryPoint : null;
    }

    private function resolveEntryPointUtmFromPayload(array $data): ?EntryPointUtm
    {
        $entryPointUtmId = trim((string) ($data['entry_point_utm_id'] ?? ''));
        if ($entryPointUtmId !== '') {
            $entryPointUtm = $this->entryPointUtms->find($entryPointUtmId);

            return $entryPointUtm instanceof EntryPointUtm ? $entryPointUtm : null;
        }

        $ref = trim((string) ($data['entrypoint_ref'] ?? $data['entry_point_ref'] ?? ''));
        if ($ref === '') {
            return null;
        }

        return $this->routingResolver->findEntryPointUtmByRef($ref);
    }

    private function resolveProductFromPayload(array $data, ?EntryPoint $entryPoint): ?Product
    {
        $productId = trim((string) ($data['product_id'] ?? ''));
        if ($productId !== '') {
            $product = $this->products->find($productId);

            return $product instanceof Product ? $product : null;
        }

        if ($entryPoint instanceof EntryPoint) {
            return $entryPoint->getProduct();
        }

        return null;
    }
}
