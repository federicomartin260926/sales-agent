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
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class InternalMcpConfigControllerTest extends TestCase
{
    private const TOKEN = 'test-internal-token';

    public function testReturnsDisabledConfigWhenTenantHasNoMcpTool(): void
    {
        $controller = $this->createController([]);
        $response = $controller->__invoke('tenant-1', Request::create('/api/internal/mcp/tenant-1/config', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertFalse($payload['enabled']);
        self::assertSame([], $payload['tools']);
    }

    public function testReturnsRuntimeDefaultMcpConfigWithDecryptedBearerToken(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $toolOne = new ExternalTool($tenant, 'Tenant MCP A', 'mcp_remote', 'openai_remote_mcp');
        $toolOne->setWebhookUrl('https://mcp-a.example.test');
        $toolOne->setAuthType('bearer');
        $toolOne->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('mcp-token'));
        $toolOne->setRuntimeDefault(true);
        $toolOne->setConfig([
            'enabled_for_llm' => true,
            'server_label' => 'tenant_main_mcp',
            'allowed_tools' => ['search_properties', 'appointment_availability'],
            'require_approval' => 'never',
            'notes' => 'Tenant MCP',
        ]);

        $toolTwo = new ExternalTool($tenant, 'Tenant MCP B', 'mcp_remote', 'openai_remote_mcp');
        $toolTwo->setWebhookUrl('https://mcp-b.example.test');
        $toolTwo->setActive(true);
        $toolTwo->setConfig([
            'enabled_for_llm' => true,
            'server_label' => 'tenant_secondary_mcp',
        ]);

        $controller = $this->createController([$toolOne, $toolTwo]);
        $response = $controller->__invoke('tenant-1', Request::create('/api/internal/mcp/tenant-1/config', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['enabled']);
        self::assertSame('tenant_main_mcp', $payload['server_label']);
        self::assertSame('https://mcp-a.example.test', $payload['server_url']);
        self::assertSame('bearer', $payload['auth_type']);
        self::assertSame('mcp-token', $payload['bearer_token']);
        self::assertSame('mcp-token', $payload['downstream_authorization_token']);
        self::assertTrue($payload['downstream_authorization_configured']);
        self::assertSame(['search_properties', 'appointment_availability'], $payload['allowed_tools']);
        self::assertSame('never', $payload['require_approval']);
    }

    public function testReturnsSingleActiveMcpWhenNoDefaultExists(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
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

        $controller = $this->createController([$tool]);
        $response = $controller->__invoke('tenant-1', Request::create('/api/internal/mcp/tenant-1/config', 'GET', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::TOKEN,
        ]));

        self::assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertTrue($payload['enabled']);
        self::assertSame('tenant_main_mcp', $payload['server_label']);
    }

    /**
     * @param list<ExternalTool> $tools
     */
    private function createController(array $tools): InternalMcpConfigController
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

        foreach ($tools as $tool) {
            $tool->setTenant($tenant);
        }

        $externalToolRepository = new class($tools) extends ExternalToolRepository {
            /**
             * @param list<ExternalTool> $tools
             */
            public function __construct(private array $tools)
            {
            }

            public function findRuntimeDefaultMcpByTenant(Tenant $tenant): ?ExternalTool
            {
                foreach ($this->tools as $tool) {
                    if ($tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $tool->isRuntimeDefault()) {
                        return $tool;
                    }
                }

                return null;
            }

            /**
             * @return list<ExternalTool>
             */
            public function findActiveMcpCandidatesByTenant(Tenant $tenant): array
            {
                return array_values(array_filter(
                    $this->tools,
                    static fn (ExternalTool $tool): bool => $tool->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122() && $tool->isActive() && $tool->getType() === 'mcp_remote'
                ));
            }
        };

        $controller = new InternalMcpConfigController(
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            new InternalBearerTokenValidator(self::TOKEN),
        );
        $controller->setContainer(new Container());

        return $controller;
    }
}
