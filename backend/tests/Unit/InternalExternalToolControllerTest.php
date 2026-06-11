<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalExternalToolController;
use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeSettingCipher;
use App\Security\InternalBearerTokenValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalExternalToolControllerTest extends TestCase
{
    public function testContactContextEndpointReturnsN8nWebhookServiceOnly(): void
    {
        $tenant = new Tenant('Mary Esteticista', 'mary-esteticista');
        $tool = new ExternalTool($tenant, 'Contact Context Mary', 'contact_context', 'n8n_webhook');
        $tool->setWebhookUrl('http://host.docker.internal:5680/webhook/sa-contact-context');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('webhook-token'));
        $tool->setDownstreamAuthorizationToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('downstream-token'));
        $tool->setTimeoutSeconds(10);
        $tool->setActive(true);

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }
        };

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public array $calls = [];

            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function findActiveByTenantTypeAndProvider(Tenant $tenant, string $type, string $provider): ?ExternalTool
            {
                $this->calls[] = [$tenant->getId()->toRfc4122(), $type, $provider];

                if ($type === 'contact_context' && $provider === 'n8n_webhook') {
                    return $this->tool;
                }

                return null;
            }
        };

        $validator = new InternalBearerTokenValidator('test-internal-token');

        $controller = new InternalExternalToolController(
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            $validator,
        );
        $controller->setContainer(new Container());

        $response = $controller(
            $tenant->getId()->toRfc4122(),
            'contact_context',
            Request::create('/api/internal/external-tools/'.$tenant->getId()->toRfc4122().'/contact_context', 'GET', [], [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer test-internal-token',
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('n8n_webhook', $payload['tool']['provider']);
        self::assertSame('contact_context', $payload['tool']['type']);
        self::assertTrue($payload['tool']['is_active']);
        self::assertSame('http://host.docker.internal:5680/webhook/sa-contact-context', $payload['tool']['webhook_url']);
        self::assertSame('webhook-token', $payload['tool']['bearer_token']);
        self::assertSame('downstream-token', $payload['tool']['downstream_authorization_token']);
        self::assertTrue($payload['tool']['downstream_authorization_configured']);
        self::assertSame([
            [$tenant->getId()->toRfc4122(), 'contact_context', 'n8n_webhook'],
        ], $externalToolRepository->calls);
    }
}
