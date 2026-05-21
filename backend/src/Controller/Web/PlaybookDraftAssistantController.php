<?php

namespace App\Controller\Web;

use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\PlaybookDraftAssistantService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/ai/playbook-draft-assistant', methods: ['POST'])]
final class PlaybookDraftAssistantController
{
    public function __construct(
        private readonly Security $security,
        private readonly PlaybookDraftAssistantService $assistantService,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    public function __invoke(Request $request, ?TenantRepository $tenants = null, ?ProductRepository $products = null): JsonResponse
    {
        if (!$this->security->isGranted('ROLE_MANAGER')) {
            return new JsonResponse(['message' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $payload = $this->payloadFromRequest($request);
        if (!$this->isValidToken((string) ($payload['_csrf_token'] ?? ''))) {
            return new JsonResponse(['message' => 'Invalid CSRF token'], Response::HTTP_BAD_REQUEST);
        }

        $conversation = is_array($payload['conversation'] ?? null) ? $payload['conversation'] : [];
        $currentMessage = is_string($payload['currentMessage'] ?? null) ? $payload['currentMessage'] : '';
        $currentFormValues = is_array($payload['currentFormValues'] ?? null) ? $payload['currentFormValues'] : [];

        $hasTenantSelection = $this->hasTenantSelection($currentFormValues);
        $tenantContext = $this->tenantContextFromValues($currentFormValues, $tenants);
        if ($hasTenantSelection && $tenantContext === null) {
            return new JsonResponse(['message' => 'El negocio seleccionado no existe.'], Response::HTTP_BAD_REQUEST);
        }

        $productContext = $tenantContext !== null ? $this->productContextFromValues($currentFormValues, $tenantContext, $products) : null;
        if ($tenantContext !== null && $productContext === null && $this->hasProductSelection($currentFormValues)) {
            return new JsonResponse(['message' => 'El producto seleccionado no existe o no pertenece al negocio.'], Response::HTTP_BAD_REQUEST);
        }

        $response = $this->assistantService->buildResponse($conversation, $currentMessage, $currentFormValues, $tenantContext, $productContext);

        return new JsonResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromRequest(Request $request): array
    {
        $content = trim($request->getContent());
        if ($content === '') {
            return $request->request->all();
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return $request->request->all();
    }

    /**
     * @param array<string, mixed> $currentFormValues
     */
    private function tenantContextFromValues(array $currentFormValues, ?TenantRepository $tenants): ?array
    {
        $tenantId = trim((string) ($currentFormValues['tenantId'] ?? ''));
        if ($tenantId === '' || !$tenants instanceof TenantRepository) {
            return null;
        }

        $tenant = $tenants->find($tenantId);
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return $this->tenantContextFromEntity($tenant);
    }

    /**
     * @param array<string, mixed> $currentFormValues
     * @param array<string, mixed>|null $tenantContext
     */
    private function productContextFromValues(array $currentFormValues, ?array $tenantContext, ?ProductRepository $products): ?array
    {
        $productId = trim((string) ($currentFormValues['productId'] ?? ''));
        if ($productId === '') {
            return null;
        }

        if (!$products instanceof ProductRepository || $tenantContext === null) {
            return null;
        }

        $product = $products->find($productId);
        if (!$product instanceof Product) {
            return null;
        }

        if ($product->getTenant()->getId()->toRfc4122() !== (string) ($tenantContext['id'] ?? '')) {
            return null;
        }

        return $this->productContextFromEntity($product);
    }

    private function tenantContextFromEntity(Tenant $tenant): array
    {
        return [
            'id' => $tenant->getId()->toRfc4122(),
            'name' => $tenant->getName(),
            'businessContext' => $tenant->getBusinessContext(),
            'tone' => $tenant->getTone() ?? '',
            'salesPolicySummary' => $tenant->getSalesPolicySummary(),
            'whatsappPublicPhone' => $tenant->getWhatsappPublicPhone() ?? '',
        ];
    }

    private function productContextFromEntity(Product $product): array
    {
        return [
            'id' => $product->getId()->toRfc4122(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'valueProposition' => $product->getValueProposition(),
            'salesPolicySummary' => $product->getSalesPolicySummary(),
        ];
    }

    /**
     * @param array<string, mixed> $currentFormValues
     */
    private function hasTenantSelection(array $currentFormValues): bool
    {
        return trim((string) ($currentFormValues['tenantId'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $currentFormValues
     */
    private function hasProductSelection(array $currentFormValues): bool
    {
        return trim((string) ($currentFormValues['productId'] ?? '')) !== '';
    }

    private function isValidToken(string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('playbook_ai_draft_assistant', $value));
    }
}
