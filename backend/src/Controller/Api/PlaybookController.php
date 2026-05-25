<?php

namespace App\Controller\Api;

use App\Domain\CommercialDomainSchema;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use App\Service\TenantAccessResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;

#[Route('/api/playbooks')]
final class PlaybookController extends AbstractApiController
{
    public function __construct(
        private readonly PlaybookRepository $playbooks,
        private readonly TenantRepository $tenants,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ?TenantAccessResolver $tenantAccessResolver = null,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $tenant = $this->resolveTenantScope($request);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenantId is required');
        }

        if (!$this->canAccessTenant($tenant)) {
            return $this->json(['message' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        return $this->json(array_map(
            static fn (Playbook $playbook): array => $playbook->toArray(),
            $this->playbooks->findByTenantOrdered($tenant)
        ));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->readJson($request);

        $tenant = $this->resolveTenantScope($request, $data);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenantId is required');
        }

        if (!$this->canManageTenant($tenant)) {
            return $this->json(['message' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        if (($data['name'] ?? '') === '') {
            return $this->badRequest('tenantId and name are required');
        }

        if (array_key_exists('tenantId', $data) && trim((string) $data['tenantId']) !== '' && trim((string) $data['tenantId']) !== $tenant->getId()->toRfc4122()) {
            return $this->badRequest('tenantId cannot be changed');
        }

        $product = null;
        if (!empty($data['productId'])) {
            $product = $this->products->find($data['productId']);
            if (!$product instanceof Product) {
                return $this->badRequest('product not found');
            }
            if ($product->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return $this->badRequest('product must belong to the same tenant');
            }
        }

        $playbook = new Playbook($tenant, (string) $data['name'], $product);
        $config = CommercialDomainSchema::normalizePlaybookConfig($data['config'] ?? []);
        if (($error = CommercialDomainSchema::validatePlaybookConfig($config)) !== null) {
            return $this->badRequest($error);
        }
        $playbook->setConfig($config);
        $playbook->setActive((bool) ($data['isActive'] ?? true));

        $this->playbooks->save($playbook);

        return $this->json($playbook->toArray(), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $tenant = $this->resolveTenantScope($request);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenantId is required');
        }

        if (!$this->canAccessTenant($tenant)) {
            return $this->json(['message' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $playbook = $this->playbooks->find($id);

        if (!$playbook instanceof Playbook || $playbook->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return $this->notFound('Playbook not found');
        }

        return $this->json($playbook->toArray());
    }

    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $playbook = $this->playbooks->find($id);

        if (!$playbook instanceof Playbook) {
            return $this->notFound('Playbook not found');
        }

        $data = $this->readJson($request);

        $tenant = $this->resolveTenantScope($request, $data);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenantId is required');
        }

        if (!$this->canManageTenant($tenant)) {
            return $this->json(['message' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        if ($playbook->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return $this->notFound('Playbook not found');
        }

        if (array_key_exists('tenantId', $data) && trim((string) $data['tenantId']) !== '' && trim((string) $data['tenantId']) !== $tenant->getId()->toRfc4122()) {
            return $this->badRequest('tenantId cannot be changed');
        }

        if (array_key_exists('productId', $data)) {
            if ($data['productId'] === null || $data['productId'] === '') {
                $playbook->setProduct(null);
            } else {
                $product = $this->products->find($data['productId']);
                if (!$product instanceof Product) {
                    return $this->badRequest('product not found');
                }

                $tenantId = $playbook->getTenant()->getId()->toRfc4122();
                if ($product->getTenant()->getId()->toRfc4122() !== $tenantId) {
                    return $this->badRequest('product must belong to the same tenant');
                }

                $playbook->setProduct($product);
            }
        }

        if (array_key_exists('name', $data)) {
            $playbook->setName((string) $data['name']);
        }

        if (array_key_exists('config', $data)) {
            $config = CommercialDomainSchema::normalizePlaybookConfig($data['config']);
            if (($error = CommercialDomainSchema::validatePlaybookConfig($config)) !== null) {
                return $this->badRequest($error);
            }
            $playbook->setConfig($config);
        }

        if (array_key_exists('isActive', $data)) {
            $playbook->setActive((bool) $data['isActive']);
        }

        $this->em->flush();

        return $this->json($playbook->toArray());
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id, Request $request): JsonResponse
    {
        $tenant = $this->resolveTenantScope($request);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenantId is required');
        }

        if (!$this->canManageTenant($tenant)) {
            return $this->json(['message' => 'Forbidden'], JsonResponse::HTTP_FORBIDDEN);
        }

        $playbook = $this->playbooks->find($id);

        if (!$playbook instanceof Playbook || $playbook->getTenant()->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
            return $this->notFound('Playbook not found');
        }

        $this->playbooks->remove($playbook);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function resolveTenantScope(Request $request, array $data = []): ?Tenant
    {
        $tenantId = $this->requestTenantId($request, $data);
        if ($tenantId === '') {
            return null;
        }

        $tenant = $this->tenants->find($tenantId);
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function requestTenantId(Request $request, array $data = []): string
    {
        $tenantId = trim((string) $request->query->get('tenant_id', $request->query->get('tenantId', '')));
        if ($tenantId !== '') {
            return $tenantId;
        }

        if ($data === []) {
            return '';
        }

        return trim((string) ($data['tenant_id'] ?? $data['tenantId'] ?? ''));
    }

    private function canAccessTenant(Tenant $tenant): bool
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return true;
        }

        return $this->tenantAccessResolver->canAccessTenant($this->currentUser(), $tenant);
    }

    private function canManageTenant(Tenant $tenant): bool
    {
        if (!$this->tenantAccessResolver instanceof TenantAccessResolver) {
            return true;
        }

        return $this->tenantAccessResolver->canManageTenant($this->currentUser(), $tenant);
    }

    private function currentUser(): ?\Symfony\Component\Security\Core\User\UserInterface
    {
        $user = $this->security->getUser();

        return $user instanceof \Symfony\Component\Security\Core\User\UserInterface ? $user : null;
    }
}
