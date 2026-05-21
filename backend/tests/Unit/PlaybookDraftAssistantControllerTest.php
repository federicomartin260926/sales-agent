<?php

namespace App\Tests\Unit;

use App\Controller\Web\PlaybookDraftAssistantController;
use App\Service\PlaybookDraftAssistantService;
use App\Service\RuntimeConfigurationService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlaybookDraftAssistantControllerTest extends TestCase
{
    public function testReturnsJsonResponseWithoutPersistingAnything(): void
    {
        $security = $this->createSecurity();
        $service = new PlaybookDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );
        $controller = new PlaybookDraftAssistantController($security, $service, $this->csrfTokenManager());

        $request = Request::create(
            '/backend/ai/playbook-draft-assistant',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_csrf_token' => 'assistant-token',
                'conversation' => [],
                'currentMessage' => 'Quiero una guía para campaña de depilación láser verano.',
                'currentFormValues' => [
                    'tenantId' => '',
                    'productId' => '',
                    'name' => '',
                    'objective' => '',
                    'qualificationQuestions' => '',
                    'maxScore' => '',
                    'handoffThreshold' => '',
                    'positiveSignals' => '',
                    'negativeSignals' => '',
                    'agendaRules' => '',
                    'handoffRules' => '',
                    'allowedActions' => '',
                    'notes' => '',
                    'isActive' => true,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $response = $controller->__invoke($request);

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('answer', $payload);
        self::assertSame('asking', $payload['status']);
        self::assertNotEmpty($payload['questions']);
        self::assertArrayHasKey('draft', $payload);
    }

    private function createSecurity(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_MANAGER');

        return $security;
    }

    private function runtimeConfigurationService(array $values): RuntimeConfigurationService
    {
        return new class($values) extends RuntimeConfigurationService {
            public function __construct(private readonly array $values)
            {
            }

            public function snapshot(): array
            {
                return ['values' => $this->values];
            }
        };
    }

    private function csrfTokenManager(): CsrfTokenManagerInterface
    {
        return new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'assistant-token');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'assistant-token');
            }

            public function removeToken(string $tokenId): ?string
            {
                return 'assistant-token';
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return $token->getValue() === 'assistant-token' && $token->getId() === 'playbook_ai_draft_assistant';
            }
        };
    }
}
