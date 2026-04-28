<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

abstract class AbstractApiController extends AbstractController
{
    protected function readJson(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function normalizeJsonField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    protected function notFound(string $message = 'Not found'): JsonResponse
    {
        return $this->json(['message' => $message], JsonResponse::HTTP_NOT_FOUND);
    }

    protected function badRequest(string $message): JsonResponse
    {
        return $this->json(['message' => $message], JsonResponse::HTTP_BAD_REQUEST);
    }

    protected function conflict(string $message): JsonResponse
    {
        return $this->json(['message' => $message], JsonResponse::HTTP_CONFLICT);
    }
}
