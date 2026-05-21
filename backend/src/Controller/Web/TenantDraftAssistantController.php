<?php

namespace App\Controller\Web;

use App\Service\TenantDraftAssistantService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/ai/tenant-draft-assistant', methods: ['POST'])]
final class TenantDraftAssistantController
{
    public function __construct(
        private readonly Security $security,
        private readonly TenantDraftAssistantService $assistantService,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
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

        $response = $this->assistantService->buildResponse($conversation, $currentMessage, $currentFormValues);

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

    private function isValidToken(string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken('tenant_ai_draft_assistant', $value));
    }
}
