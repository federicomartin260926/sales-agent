<?php

namespace App\Tests\Unit;

use App\Controller\Api\InternalMcpConfigController;
use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Security\InternalBearerTokenValidator;
use App\Service\RuntimeSettingCipher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalMcpConfigControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    public function testReturnsDisabledConfigWhenTenantHasNoMcpTool(): void
    {
        $controller = $this->createController(null);
        $response = $controller->__invoke('tenant-1', Request::create('/api/internal/mcp/tenant-1/config', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['enabled']);
        self::assertSame([], $payload['tools']);
    }

    public function testReturnsMcpConfigWithDecryptedBearerToken(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-demo');
        $tool = new ExternalTool($tenant, 'Tenant MCP', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('mcp-token'));
        $tool->setConfig([
            'enabled_for_llm' => true,
            'server_label' => 'tenant_main_mcp',
            'allowed_tools' => ['search_properties', 'appointment_availability'],
            'require_approval' => 'never',
            'notes' => 'Tenant MCP',
        ]);

        $controller = $this->createController($tool);
        $response = $controller->__invoke('tenant-1', Request::create('/api/internal/mcp/tenant-1/config', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['enabled']);
        self::assertSame('tenant_main_mcp', $payload['server_label']);
        self::assertSame('https://mcp.example.test', $payload['server_url']);
        self::assertSame('bearer', $payload['auth_type']);
        self::assertSame('mcp-token', $payload['bearer_token']);
        self::assertSame(['search_properties', 'appointment_availability'], $payload['allowed_tools']);
        self::assertSame('never', $payload['require_approval']);
    }

    private function createController(?ExternalTool $tool): InternalMcpConfigController
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tenant->setActive(true);

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly ?Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }
        };

        if ($tool instanceof ExternalTool) {
            $tool->setTenant($tenant);
        }

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ?ExternalTool $tool)
            {
            }

            public function findActiveMcpByTenant(Tenant $tenant): ?ExternalTool
            {
                return $this->tool;
            }
        };

        $controller = new InternalMcpConfigController(
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            new InternalBearerTokenValidator(self::TOKEN),
        );

        return $controller;
    }
}
