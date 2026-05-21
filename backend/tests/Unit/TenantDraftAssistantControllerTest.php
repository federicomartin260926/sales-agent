<?php

namespace App\Tests\Unit;

use App\Controller\Web\TenantDraftAssistantController;
use App\Service\RuntimeConfigurationService;
use App\Service\TenantDraftAssistantService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TenantDraftAssistantControllerTest extends TestCase
{
    public function testReturnsJsonResponseWithoutPersistingAnything(): void
    {
        $security = $this->createSecurity();
        $service = new TenantDraftAssistantService(
            $this->runtimeConfigurationService([]),
            $this->createStub(HttpClientInterface::class),
        );
        $controller = new TenantDraftAssistantController($security, $service, $this->csrfTokenManager());

        $request = Request::create(
            '/backend/ai/tenant-draft-assistant',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                '_csrf_token' => 'assistant-token',
                'conversation' => [],
                'currentMessage' => 'Necesito una ficha para una clínica dental.',
                'currentFormValues' => [
                    'name' => 'Clínica Dental Demo',
                    'slug' => '',
                    'businessContext' => '',
                    'tone' => '',
                    'whatsappPhoneNumberId' => '',
                    'whatsappPublicPhone' => '',
                    'positioning' => '',
                    'qualificationFocus' => '',
                    'handoffRules' => '',
                    'salesBoundaries' => '',
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
        self::assertSame('clinica-dental-demo', $payload['draft']['slug']);
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
                return $token->getValue() === 'assistant-token' && $token->getId() === 'tenant_ai_draft_assistant';
            }
        };
    }
}
