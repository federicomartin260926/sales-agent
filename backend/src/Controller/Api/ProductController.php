<?php

namespace App\Controller\Api;

use App\Domain\CommercialDomainSchema;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/products')]
final class ProductController extends AbstractApiController
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(array_map(
            static fn (Product $product): array => $product->toArray(),
            $this->products->findAllOrdered()
        ));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->readJson($request);

        if (($data['tenantId'] ?? '') === '' || ($data['name'] ?? '') === '') {
            return $this->badRequest('tenantId and name are required');
        }

        $tenant = $this->tenants->find($data['tenantId']);
        if (!$tenant instanceof Tenant) {
            return $this->badRequest('tenant not found');
        }

        $slug = isset($data['slug']) && is_string($data['slug']) ? trim($data['slug']) : '';
        if ($slug === '') {
            $slug = (new Product($tenant, (string) $data['name']))->getSlug();
        }

        if (($error = $this->guardProductIdentity(
            $tenant,
            null,
            $slug,
            isset($data['externalSource']) ? (is_string($data['externalSource']) ? trim($data['externalSource']) : null) : null,
            isset($data['externalReference']) ? (is_string($data['externalReference']) ? trim($data['externalReference']) : null) : null,
        )) !== null) {
            return $error;
        }

        $product = new Product($tenant, (string) $data['name']);
        if (isset($data['slug']) && trim((string) $data['slug']) !== '') {
            $product->setSlug((string) $data['slug']);
        }
        if (array_key_exists('externalSource', $data)) {
            $product->setExternalSource(is_string($data['externalSource']) && trim($data['externalSource']) !== '' ? $data['externalSource'] : null);
        }
        if (array_key_exists('externalReference', $data)) {
            $product->setExternalReference(is_string($data['externalReference']) && trim($data['externalReference']) !== '' ? $data['externalReference'] : null);
        }
        $product->setDescription((string) ($data['description'] ?? ''));
        $product->setValueProposition((string) ($data['valueProposition'] ?? ''));
        if (array_key_exists('basePriceCents', $data)) {
            $product->setBasePriceCents($this->intOrNull($data['basePriceCents']));
        }
        if (array_key_exists('currency', $data)) {
            $product->setCurrency(is_string($data['currency']) && trim($data['currency']) !== '' ? $data['currency'] : null);
        }
        $salesPolicy = CommercialDomainSchema::normalizeProductSalesPolicy($data['salesPolicy'] ?? []);
        if (($error = CommercialDomainSchema::validateProductSalesPolicy($salesPolicy)) !== null) {
            return $this->badRequest($error);
        }
        $product->setSalesPolicy($salesPolicy);
        $product->setActive((bool) ($data['isActive'] ?? true));

        $this->products->save($product);

        return $this->json($product->toArray(), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $product = $this->products->find($id);

        if (!$product instanceof Product) {
            return $this->notFound('Product not found');
        }

        return $this->json($product->toArray());
    }

    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $product = $this->products->find($id);

        if (!$product instanceof Product) {
            return $this->notFound('Product not found');
        }

        $data = $this->readJson($request);

        if (array_key_exists('tenantId', $data)) {
            $tenant = $this->tenants->find($data['tenantId']);
            if (!$tenant instanceof Tenant) {
                return $this->badRequest('tenant not found');
            }
            $product->setTenant($tenant);
        }

        if (($error = $this->guardProductIdentity(
            $product->getTenant(),
            $product,
            array_key_exists('slug', $data) && is_string($data['slug']) && trim($data['slug']) !== '' ? trim($data['slug']) : null,
            array_key_exists('externalSource', $data) && is_string($data['externalSource']) ? trim($data['externalSource']) : null,
            array_key_exists('externalReference', $data) && is_string($data['externalReference']) ? trim($data['externalReference']) : null,
        )) !== null) {
            return $error;
        }

        if (array_key_exists('name', $data)) {
            $product->setName((string) $data['name']);
        }

        if (array_key_exists('slug', $data) && is_string($data['slug']) && trim($data['slug']) !== '') {
            $product->setSlug((string) $data['slug']);
        }

        if (array_key_exists('externalSource', $data)) {
            $product->setExternalSource(is_string($data['externalSource']) && trim($data['externalSource']) !== '' ? $data['externalSource'] : null);
        }

        if (array_key_exists('externalReference', $data)) {
            $product->setExternalReference(is_string($data['externalReference']) && trim($data['externalReference']) !== '' ? $data['externalReference'] : null);
        }

        if (array_key_exists('description', $data)) {
            $product->setDescription((string) $data['description']);
        }

        if (array_key_exists('valueProposition', $data)) {
            $product->setValueProposition((string) $data['valueProposition']);
        }

        if (array_key_exists('basePriceCents', $data)) {
            $product->setBasePriceCents($this->intOrNull($data['basePriceCents']));
        }

        if (array_key_exists('currency', $data)) {
            $product->setCurrency(is_string($data['currency']) && trim($data['currency']) !== '' ? $data['currency'] : null);
        }

        if (array_key_exists('salesPolicy', $data)) {
            $salesPolicy = CommercialDomainSchema::normalizeProductSalesPolicy($data['salesPolicy']);
            if (($error = CommercialDomainSchema::validateProductSalesPolicy($salesPolicy)) !== null) {
                return $this->badRequest($error);
            }
            $product->setSalesPolicy($salesPolicy);
        }

        if (array_key_exists('isActive', $data)) {
            $product->setActive((bool) $data['isActive']);
        }

        $this->em->flush();

        return $this->json($product->toArray());
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $product = $this->products->find($id);

        if (!$product instanceof Product) {
            return $this->notFound('Product not found');
        }

        $this->products->remove($product);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return null;
    }

    private function guardProductIdentity(
        Tenant $tenant,
        ?Product $currentProduct,
        ?string $slug,
        ?string $externalSource,
        ?string $externalReference,
    ): ?JsonResponse {
        if ($slug !== null && $slug !== '') {
            $existing = $this->products->findOneByTenantAndSlug($tenant, $slug);
            if ($existing instanceof Product && ($currentProduct === null || $existing->getId()->toRfc4122() !== $currentProduct->getId()->toRfc4122())) {
                return $this->badRequest('slug already exists for tenant');
            }
        }

        if ($externalSource !== null && $externalSource !== '' && $externalReference !== null && $externalReference !== '') {
            $existing = $this->products->findOneByExternalIdentity($tenant, $externalSource, $externalReference);
            if ($existing instanceof Product && ($currentProduct === null || $existing->getId()->toRfc4122() !== $currentProduct->getId()->toRfc4122())) {
                return $this->badRequest('external identity already exists for tenant');
            }
        }

        return null;
    }
}
