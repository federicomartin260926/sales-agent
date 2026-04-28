<?php

namespace App\Controller\Api;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tenants')]
final class TenantController extends AbstractApiController
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json(array_map(
            static fn (Tenant $tenant): array => $tenant->toArray(),
            $this->tenants->findAllOrdered()
        ));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $this->readJson($request);

        if (($data['name'] ?? '') === '' || ($data['slug'] ?? '') === '') {
            return $this->badRequest('name and slug are required');
        }

        if ($this->tenants->findOneBy(['slug' => $data['slug']]) instanceof Tenant) {
            return $this->conflict('slug already exists');
        }

        $tenant = new Tenant((string) $data['name'], (string) $data['slug']);
        $tenant->setBusinessContext((string) ($data['businessContext'] ?? ''));
        $tenant->setTone(isset($data['tone']) ? (string) $data['tone'] : null);
        $tenant->setSalesPolicy($this->normalizeJsonField($data['salesPolicy'] ?? []));
        $tenant->setActive((bool) ($data['isActive'] ?? true));

        $this->tenants->save($tenant);

        return $this->json($tenant->toArray(), JsonResponse::HTTP_CREATED);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $tenant = $this->tenants->find($id);

        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        return $this->json($tenant->toArray());
    }

    #[Route('/{id}', methods: ['PUT', 'PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $tenant = $this->tenants->find($id);

        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $data = $this->readJson($request);

        if (array_key_exists('name', $data)) {
            $tenant->setName((string) $data['name']);
        }

        if (array_key_exists('slug', $data)) {
            $slug = (string) $data['slug'];
            $existing = $this->tenants->findOneBy(['slug' => $slug]);

            if ($existing instanceof Tenant && $existing->getId()->toRfc4122() !== $tenant->getId()->toRfc4122()) {
                return $this->conflict('slug already exists');
            }

            $tenant->setSlug($slug);
        }

        if (array_key_exists('businessContext', $data)) {
            $tenant->setBusinessContext((string) $data['businessContext']);
        }

        if (array_key_exists('tone', $data)) {
            $tenant->setTone($data['tone'] === null ? null : (string) $data['tone']);
        }

        if (array_key_exists('salesPolicy', $data)) {
            $tenant->setSalesPolicy($this->normalizeJsonField($data['salesPolicy']));
        }

        if (array_key_exists('isActive', $data)) {
            $tenant->setActive((bool) $data['isActive']);
        }

        $this->em->flush();

        return $this->json($tenant->toArray());
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $tenant = $this->tenants->find($id);

        if (!$tenant instanceof Tenant) {
            return $this->notFound('Tenant not found');
        }

        $this->tenants->remove($tenant);

        return $this->json(null, JsonResponse::HTTP_NO_CONTENT);
    }
}
