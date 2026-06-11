<?php

namespace App\Tests\Unit;

use App\Controller\Web\ExternalToolController;
use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
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
        ?ActiveTenantContext $activeTenantContext = null,
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $runtimeConfigurationService ??= $this->createRuntimeConfigurationService([]);
        $activeTenantContext ??= new ActiveTenantContext(new RequestStack(), $tenantRepository);

        return new ExternalToolController(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            $httpClient ?? $this->createStub(HttpClientInterface::class),
            'test-bearer-token',
            $runtimeConfigurationService,
            $activeTenantContext,
        );
    }

    private function createActiveTenantContext(Tenant $tenant): ActiveTenantContext
    {
        $requestStack = new RequestStack();
        $request = Request::create('/backend');
        $request->setSession(new Session());
        $requestStack->push($request);

        $repository = new class($tenant) extends TenantRepository {
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

        $context = new ActiveTenantContext($requestStack, $repository);
        $context->setActiveTenant($tenant);

        return $context;
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
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('federicomartin2609@gmail.com', ['admin']));

        $controller = $this->createController($security, null, null, null, null, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Federico Martín', $response->getContent());
        self::assertStringNotContainsString('<strong>Usuario</strong>', $response->getContent());
    }

    public function testIndexRedirectsWhenUserIsNotSuperAdmin(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role !== 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('manager@example.com', ['manager']));

        $controller = $this->createController($security);
        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/login', $response->headers->get('Location'));
    }

    public function testIndexPromptsForActiveTenantWhenNoneIsSelected(): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $controller = $this->createController($security);
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Selecciona un negocio para continuar', $response->getContent());
        self::assertStringContainsString('/backend/tenants', $response->getContent());
    }

    public function testIndexHighlightsRuntimeDefaultMcp(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $defaultTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $defaultTool->setWebhookUrl('https://mcp.example.test');
        $defaultTool->setRuntimeDefault(true);
        $defaultTool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);
        $otherTool = new ExternalTool($tenant, 'MCP secundario', 'mcp_remote', 'openai_remote_mcp');
        $otherTool->setWebhookUrl('https://mcp-2.example.test');
        $otherTool->setConfig(['enabled_for_llm' => true, 'server_label' => 'secondary_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($tenant, $defaultTool, $otherTool) extends ExternalToolRepository {
            public function __construct(
                private readonly Tenant $tenant,
                private readonly ExternalTool $defaultTool,
                private readonly ExternalTool $otherTool,
            ) {
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->defaultTool, $this->otherTool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->defaultTool;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->defaultTool, $this->otherTool];
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Principal', $response->getContent());
        self::assertStringContainsString('Marcar principal', $response->getContent());
        self::assertStringContainsString('Negocio activo', $response->getContent());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('Administración técnica', $response->getContent());
        self::assertStringContainsString('Servidores MCP', $response->getContent());
        self::assertStringContainsString('Plataforma', $response->getContent());
        self::assertStringContainsString('Negocios', $response->getContent());
        self::assertStringContainsString('Usuarios', $response->getContent());
        self::assertStringContainsString('Configuración', $response->getContent());
        self::assertStringContainsString('API Health', $response->getContent());
        self::assertStringContainsString('/backend/api-health', $response->getContent());
    }

    public function testIndexDoesNotListN8nWebhookToolsEvenIfTheyUseMcpType(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $mcpTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $mcpTool->setWebhookUrl('https://mcp.example.test');
        $mcpTool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);
        $n8nTool = new ExternalTool($tenant, 'Contact Context Mary', 'mcp_remote', 'n8n_webhook');
        $n8nTool->setWebhookUrl('https://n8n.example.test/webhook/contact-context');
        $n8nTool->setConfig(['summary' => 'Contexto operativo']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($mcpTool, $n8nTool) extends ExternalToolRepository {
            public function __construct(
                private readonly ExternalTool $mcpTool,
                private readonly ExternalTool $n8nTool,
            ) {
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->mcpTool, $this->n8nTool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->mcpTool;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->mcpTool];
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('MCP principal', $response->getContent());
        self::assertStringNotContainsString('Contact Context Mary', $response->getContent());
    }

    public function testIndexShowsDownstreamAuthorizationStatusWithoutExposingToken(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('super-secret-token'));
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->tool;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Autorización downstream: configurada', $response->getContent());
        self::assertStringNotContainsString('super-secret-token', $response->getContent());
    }

    public function testIndexFiltersOutNonMcpToolsFromThisScreen(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $mcpTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $mcpTool->setWebhookUrl('https://mcp.example.test');
        $mcpTool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);
        $handoffTool = new ExternalTool($tenant, 'Handoff webhook', 'handoff_webhook', 'n8n_webhook');
        $handoffTool->setWebhookUrl('https://n8n.example.test/webhook/handoff');

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($mcpTool, $handoffTool) extends ExternalToolRepository {
            public function __construct(
                private readonly ExternalTool $mcpTool,
                private readonly ExternalTool $handoffTool,
            ) {
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->mcpTool, $this->handoffTool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->mcpTool;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->mcpTool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('MCP principal', $response->getContent());
        self::assertStringNotContainsString('Handoff webhook', $response->getContent());
        self::assertStringNotContainsString('handoff_webhook', $response->getContent());
    }

    public function testEditFormShowsDownstreamAuthorizationStatusWithoutTokenValue(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('super-secret-token'));
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->tool->isRuntimeDefault() ? $this->tool : null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->edit($tool->getId()->toRfc4122(), Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Ficha', $response->getContent());
        self::assertStringContainsString('Conexión', $response->getContent());
        self::assertStringContainsString('Autorización', $response->getContent());
        self::assertStringContainsString('MCP runtime', $response->getContent());
        self::assertStringContainsString('Avanzado', $response->getContent());
        self::assertStringNotContainsString('id="tool-type"', $response->getContent());
        self::assertStringNotContainsString('id="tool-provider"', $response->getContent());
        self::assertStringNotContainsString('Tipo</label>', $response->getContent());
        self::assertStringNotContainsString('Provider</label>', $response->getContent());
        self::assertStringContainsString('Token CRM para n8n/MCP', $response->getContent());
        self::assertStringContainsString('Estado: Configurado', $response->getContent());
        self::assertStringNotContainsString('super-secret-token', $response->getContent());
    }

    public function testEditIgnoresManipulatedTypeAndProviderAndForcesMcpRemote(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->edit(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit', 'POST', [
                'name' => 'MCP principal',
                'type' => 'handoff_webhook',
                'provider' => 'n8n_webhook',
                'webhookUrl' => 'https://mcp.example.test',
                'authType' => 'bearer',
                'bearerToken' => 'test-downstream-token',
                'timeoutSeconds' => '5',
                'isActive' => '1',
                'isRuntimeDefault' => '1',
                'config' => '{"enabled_for_llm":true,"server_label":"principal_mcp"}',
                'serverLabel' => 'principal_mcp',
                'allowedTools' => "search_properties\nappointment_availability\ncrm_contact_submit",
                'requireApproval' => 'never',
                'enabledForLlm' => '1',
                'notes' => '',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('mcp_remote', $tool->getType());
        self::assertSame('mcp_remote', $tool->getProvider());
        self::assertSame('principal_mcp', $tool->getServerLabel());
    }

    public function testEditPreservesExistingDownstreamTokenWhenFieldIsEmpty(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('super-secret-token'));
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $originalToken = $tool->getBearerToken();

        $response = $controller->edit(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit', 'POST', [
                '_csrf_token' => '',
                'name' => 'MCP principal',
                'type' => 'mcp_remote',
                'provider' => 'openai_remote_mcp',
                'webhookUrl' => 'https://mcp.example.test',
                'authType' => 'bearer',
                'bearerToken' => '',
                'timeoutSeconds' => '5',
                'isActive' => '1',
                'isRuntimeDefault' => '1',
                'config' => '{"enabled_for_llm":true,"server_label":"principal_mcp"}',
                'serverLabel' => 'principal_mcp',
                'allowedTools' => "search_properties\nappointment_availability\ncrm_contact_submit",
                'requireApproval' => 'never',
                'enabledForLlm' => '1',
                'notes' => '',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame($originalToken, $tool->getBearerToken());
    }

    public function testEditInfersBearerAuthWhenDownstreamTokenIsSubmitted(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->edit(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit', 'POST', [
                '_csrf_token' => '',
                'name' => 'MCP principal',
                'type' => 'mcp_remote',
                'provider' => 'openai_remote_mcp',
                'webhookUrl' => 'https://mcp.example.test',
                'authType' => 'none',
                'bearerToken' => 'test-downstream-token',
                'timeoutSeconds' => '5',
                'isActive' => '1',
                'isRuntimeDefault' => '1',
                'config' => '{"enabled_for_llm":true,"server_label":"principal_mcp"}',
                'serverLabel' => 'principal_mcp',
                'allowedTools' => "search_properties\nappointment_availability\ncrm_contact_submit",
                'requireApproval' => 'never',
                'enabledForLlm' => '1',
                'notes' => '',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('bearer', $tool->getAuthType());
        self::assertSame('test-downstream-token', (new RuntimeSettingCipher('kernel-secret'))->decrypt($tool->getBearerToken() ?? ''));
    }

    public function testEditCanClearDownstreamTokenWithExplicitCheckbox(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $tool->setWebhookUrl('https://mcp.example.test');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('super-secret-token'));
        $tool->setConfig(['enabled_for_llm' => true, 'server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                return 0;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->edit(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$tool->getId()->toRfc4122().'/edit', 'POST', [
                '_csrf_token' => '',
                'name' => 'MCP principal',
                'type' => 'mcp_remote',
                'provider' => 'openai_remote_mcp',
                'webhookUrl' => 'https://mcp.example.test',
                'authType' => 'bearer',
                'bearerToken' => '',
                'clearBearerToken' => '1',
                'timeoutSeconds' => '5',
                'isActive' => '1',
                'isRuntimeDefault' => '1',
                'config' => '{"enabled_for_llm":true,"server_label":"principal_mcp"}',
                'serverLabel' => 'principal_mcp',
                'allowedTools' => "search_properties\nappointment_availability\ncrm_contact_submit",
                'requireApproval' => 'never',
                'enabledForLlm' => '1',
                'notes' => '',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertNull($tool->getBearerToken());
    }

    public function testMarkDefaultPromotesSelectedMcpAndUnmarksOthers(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $toolA = new ExternalTool($tenant, 'MCP A', 'mcp_remote', 'openai_remote_mcp');
        $toolA->setWebhookUrl('https://mcp-a.example.test');
        $toolA->setConfig(['enabled_for_llm' => true, 'server_label' => 'mcp_a']);
        $toolB = new ExternalTool($tenant, 'MCP B', 'mcp_remote', 'openai_remote_mcp');
        $toolB->setWebhookUrl('https://mcp-b.example.test');
        $toolB->setConfig(['enabled_for_llm' => true, 'server_label' => 'mcp_b']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private readonly Tenant $tenant)
            {
            }

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }
        };

        $externalToolRepository = new class($toolA, $toolB) extends ExternalToolRepository {
            public function __construct(
                private ExternalTool $toolA,
                private ExternalTool $toolB,
            ) {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->toolA->getId()->toRfc4122() === $id ? $this->toolA : $this->toolB;
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return $this->toolA->isRuntimeDefault() ? $this->toolA : ($this->toolB->isRuntimeDefault() ? $this->toolB : null);
            }

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->toolA, $this->toolB];
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
            {
                return [$this->toolA, $this->toolB];
            }

            public function unsetRuntimeDefaultForTenant(\App\Entity\Tenant $tenant, ?\App\Entity\ExternalTool $except = null, bool $flush = true): int
            {
                $count = 0;
                foreach ([$this->toolA, $this->toolB] as $tool) {
                    if ($except instanceof ExternalTool && $tool->getId()->toRfc4122() === $except->getId()->toRfc4122()) {
                        continue;
                    }
                    if ($tool->isRuntimeDefault()) {
                        $tool->setRuntimeDefault(false);
                        $count++;
                    }
                }

                return $count;
            }
        };

        $controller = $this->createController($security, null, null, $tenantRepository, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new Container();
        $container->set('twig', $this->createTwigEnvironment());
        $requestStack = new RequestStack();
        $request = Request::create('/backend/external-tools');
        $request->setSession(new Session());
        $requestStack->push($request);
        $container->set('request_stack', $requestStack);
        $controller->setContainer($container);

        $response = $controller->markDefault(
            $toolB->getId()->toRfc4122(),
            Request::create('/backend/external-tools/'.$toolB->getId()->toRfc4122().'/mark-default', 'POST', [
                '_csrf_token' => 'anything',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertFalse($toolA->isRuntimeDefault());
        self::assertTrue($toolB->isRuntimeDefault());
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
                'reply' => 'Tenemos un servicio de automatización que encaja con tu búsqueda.',
                'provider' => 'openai_remote_mcp',
                'model' => '',
                'data_to_save' => [
                    'mcp_response_id' => 'resp_123',
                    'mcp_tool_traces' => [
                        [
                            'type' => 'mcp_call',
                            'tool_name' => 'services_search',
                            'status' => 'completed',
                            'output' => ['found' => true, 'count' => 1],
                        ],
                    ],
                ],
            ]));
        });

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
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
            $this->createActiveTenantContext($tenant),
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
        self::assertStringContainsString('services_search', $body);
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
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
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

            public function findByTenantOrdered(\App\Entity\Tenant $tenant): array
            {
                return [$this->tool];
            }

            public function findRuntimeDefaultMcpByTenant(\App\Entity\Tenant $tenant): ?\App\Entity\ExternalTool
            {
                return null;
            }

            public function findActiveMcpCandidatesByTenant(\App\Entity\Tenant $tenant): array
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
            $this->createActiveTenantContext($tenant),
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

    public function testHandoffWebhookDoesNotPersistBearerTokenEvenIfToolHadOneBefore(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'Handoff webhook', 'handoff_webhook', 'n8n_webhook');
        $tool->setBearerToken('should-not-survive');

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn(true);
        $controller = $this->createController($security);

        $method = new \ReflectionMethod($controller, 'applyToolFormValues');
        $method->setAccessible(true);
        $method->invoke($controller, $tool, [
            'name' => 'Handoff webhook',
            'tenantId' => $tenant->getId()->toRfc4122(),
            'type' => 'handoff_webhook',
            'provider' => 'n8n_webhook',
            'webhookUrl' => 'https://n8n.example.test/webhook/handoff',
            'authType' => 'none',
            'bearerToken' => '',
            'clearBearerToken' => false,
            'timeoutSeconds' => '5',
            'isActive' => true,
            'isRuntimeDefault' => false,
            'config' => '{}',
            'serverLabel' => '',
            'allowedTools' => '',
            'requireApproval' => 'auto',
            'enabledForLlm' => false,
            'notes' => '',
        ], false, $tenant);

        self::assertSame('handoff_webhook', $tool->getType());
        self::assertSame('n8n_webhook', $tool->getProvider());
        self::assertSame('https://n8n.example.test/webhook/handoff', $tool->getWebhookUrl());
        self::assertNull($tool->getAuthType());
        self::assertNull($tool->getBearerToken());
    }
}
