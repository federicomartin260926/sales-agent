<?php

namespace App\Controller\Api;

use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\PlaybookRepository;
use App\Repository\ProductRepository;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/playbooks')]
final class PlaybookController extends AbstractApiController
{
    public function __construct(
        private readonly PlaybookRepository $playbooks,
        private readonly TenantRepository $tenants,
        private readonly ProductRepository $products,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(array_map(
            static fn (Playbook $playbook): array => $playbook->toArray(),
            $this->playbooks->findAllOrdered()
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
        $playbook->setConfig($this->normalizeJsonField($data['config'] ?? []));
        $playbook->setActive((bool) ($data['isActive'] ?? true));

        $this->playbooks->save($playbook);

        return $this->json($playbook->toArray(), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $playbook = $this->playbooks->find($id);

        if (!$playbook instanceof Playbook) {
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

        if (array_key_exists('tenantId', $data)) {
            $tenant = $this->tenants->find($data['tenantId']);
            if (!$tenant instanceof Tenant) {
                return $this->badRequest('tenant not found');
            }
            $playbook->setTenant($tenant);
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
            $playbook->setConfig($this->normalizeJsonField($data['config']));
        }

        if (array_key_exists('isActive', $data)) {
            $playbook->setActive((bool) $data['isActive']);
        }

        $this->em->flush();

        return $this->json($playbook->toArray());
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $playbook = $this->playbooks->find($id);

        if (!$playbook instanceof Playbook) {
            return $this->notFound('Playbook not found');
        }

        $this->playbooks->remove($playbook);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
