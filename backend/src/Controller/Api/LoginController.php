<?php

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LoginController extends AbstractApiController
{
    #[Route('/api/login', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->json([
            'message' => 'Use POST /api/login with email and password to authenticate.',
            'example' => [
                'email' => 'federicomartin2609@gmail.com',
                'password' => '1234',
            ],
        ]);
    }

    #[Route('/api/login', methods: ['POST'])]
    public function __invoke(): JsonResponse
    {
        return $this->json([
            'message' => 'Authentication is handled by the security firewall.',
        ], JsonResponse::HTTP_METHOD_NOT_ALLOWED);
    }
}
