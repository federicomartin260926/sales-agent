<?php

namespace App\Tests\Unit;

use App\Controller\Web\ExternalToolController;
use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeConfigurationService;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExternalToolControllerTest extends TestCase
{
    private function createRuntimeConfigurationService(array $values): RuntimeConfigurationService
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

    private function createController(
        Security $security,
        ?HttpClientInterface $httpClient = null,
        ?RuntimeConfigurationService $runtimeConfigurationService = null,
        ?TenantRepository $tenantRepository = null,
        ?ExternalToolRepository $externalToolRepository = null,
    ): ExternalToolController {
        $tenantRepository ??= new class extends TenantRepository {
            public function __construct()
            {
            }

            public function findAllOrdered(): array
            {
                return [];
            }
        };

        $externalToolRepository ??= new class extends ExternalToolRepository {
            public function __construct()
            {
            }

            public function findAllOrdered(): array
            {
                return [];
            }
        };

        $runtimeConfigurationService ??= $this->createRuntimeConfigurationService([]);

        return new ExternalToolController(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            $httpClient ?? $this->createStub(HttpClientInterface::class),
            'test-bearer-token',
            $runtimeConfigurationService,
        );
    }

    private function createTwigEnvironment(): Environment
    {
        $loader = new FilesystemLoader(__DIR__.'/../../templates');

        return new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    public function testIndexRendersRealUserNameInHeader(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');
        $security->method('getUser')->willReturn(new User('federicomartin2609@gmail.com', ['admin']));

        $controller = $this->createController($security);
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Federico Martín', $response->getContent());
        self::assertStringNotContainsString('<strong>Usuario</strong>', $response->getContent());
    }

    public function testMcpTestUsesOpenAiRuntimeProviderAndDoesNotConfuseToolProvider(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tool = new ExternalTool($tenant, 'Tenant MCP', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setConfig([
            'enabled_for_llm' => true,
            'server_label' => 'tech_investments_mcp',
            'allowed_tools' => ['appointment_events'],
            'require_approval' => 'never',
        ]);

        $runtimeConfigurationService = $this->createRuntimeConfigurationService([
            'llm_default_profile' => 'openai',
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_model' => 'gpt-4.1-mini',
            'openai_api_key' => 'sk-test',
            'openai_timeout_seconds' => '15',
            'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
            'ollama_model' => 'llama3.1',
            'ollama_timeout_seconds' => '15',
        ]);

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse(json_encode([
                'found' => true,
                'reply' => 'Tu próxima cita es mañana a las 10:00.',
                'provider' => 'openai_remote_mcp',
                'model' => '',
                'data_to_save' => [
                    'mcp_response_id' => 'resp_123',
                    'mcp_tool_traces' => [
                        [
                            'type' => 'mcp_call',
                            'tool_name' => 'appointment_events',
                            'status' => 'completed',
                            'output' => ['found' => true],
                        ],
                    ],
                ],
            ]));
        });

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tool;
            }

            public function findAllOrdered(): array
            {
                return [$this->tool];
            }

            public function findByTenantOrdered(Tenant $tenant): array
            {
                return [$this->tool];
            }
        };

        $controller = $this->createController(
            $security,
            $httpClient,
            $runtimeConfigurationService,
            $tenantRepository,
            $externalToolRepository,
        );
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->test(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/test', 'POST', [
                '_csrf_token' => 'anything',
            ])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertCount(1, $requests);

        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('Resultado de prueba', $body);
        self::assertStringContainsString('provider: openai | model: gpt-4.1-mini', $body);
        self::assertStringContainsString('mcp_response_id: resp_123', $body);
        self::assertStringContainsString('appointment_events', $body);
        self::assertStringNotContainsString('provider_not_supported', $body);
    }

    public function testMcpTestReturnsProviderNotSupportedWhenRuntimeUsesOllama(): void
    {
        $tenant = new Tenant('Negocio Demo', 'tenant-1');
        $tool = new ExternalTool($tenant, 'Tenant MCP', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setConfig([
            'enabled_for_llm' => true,
            'server_label' => 'tech_investments_mcp',
            'allowed_tools' => ['appointment_events'],
            'require_approval' => 'never',
        ]);

        $runtimeConfigurationService = $this->createRuntimeConfigurationService([
            'llm_default_profile' => 'ollama',
            'openai_base_url' => 'https://api.openai.com/v1',
            'openai_model' => 'gpt-4.1-mini',
            'openai_api_key' => 'sk-test',
            'ollama_base_url' => 'http://ollama-vpn-bridge:11434',
            'ollama_model' => 'llama3.1',
            'ollama_timeout_seconds' => '15',
        ]);

        $httpClient = new MockHttpClient(static function (): MockResponse {
            return new MockResponse(json_encode([
                'reply' => 'Cuéntame qué citas o reuniones quieres revisar y te ayudo con eso.',
                'provider' => 'ollama',
                'data_to_save' => [],
            ]));
        });

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tool;
            }

            public function findAllOrdered(): array
            {
                return [$this->tool];
            }

            public function findByTenantOrdered(Tenant $tenant): array
            {
                return [$this->tool];
            }
        };

        $controller = $this->createController(
            $security,
            $httpClient,
            $runtimeConfigurationService,
            $tenantRepository,
            $externalToolRepository,
        );
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->test(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/test', 'POST', [
                '_csrf_token' => 'anything',
            ])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $body = $response->getContent();
        self::assertIsString($body);
        self::assertStringContainsString('provider_not_supported', $body);
        self::assertStringContainsString('provider: ollama', $body);
    }
}
