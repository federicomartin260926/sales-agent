<?php

namespace App\Controller\Api;

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

        $product = new Product($tenant, (string) $data['name']);
        $product->setDescription((string) ($data['description'] ?? ''));
        $product->setValueProposition((string) ($data['valueProposition'] ?? ''));
        $product->setSalesPolicy($this->normalizeJsonField($data['salesPolicy'] ?? []));
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

        if (array_key_exists('name', $data)) {
            $product->setName((string) $data['name']);
        }

        if (array_key_exists('description', $data)) {
            $product->setDescription((string) $data['description']);
        }

        if (array_key_exists('valueProposition', $data)) {
            $product->setValueProposition((string) $data['valueProposition']);
        }

        if (array_key_exists('salesPolicy', $data)) {
            $product->setSalesPolicy($this->normalizeJsonField($data['salesPolicy']));
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
}
