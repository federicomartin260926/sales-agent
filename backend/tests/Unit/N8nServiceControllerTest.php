<?php

namespace App\Tests\Unit;

use App\Controller\Web\N8nServiceController;
use App\Entity\ExternalTool;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class N8nServiceControllerTest extends TestCase
{
    private function createController(
        Security $security,
        ?EntityManagerInterface $entityManager = null,
        ?TenantRepository $tenantRepository = null,
        ?ExternalToolRepository $externalToolRepository = null,
        ?ActiveTenantContext $activeTenantContext = null,
        ?\Symfony\Contracts\HttpClient\HttpClientInterface $httpClient = null,
    ): N8nServiceController {
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

            public function findByTenantAndProviderOrdered(\App\Entity\Tenant $tenant, string $provider): array
            {
                return [];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return null;
            }
        };

        $activeTenantContext ??= new ActiveTenantContext(new RequestStack(), $tenantRepository);

        return new N8nServiceController(
            $security,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            $httpClient ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 204])),
            $activeTenantContext,
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

            public function findAllOrdered(): array
            {
                return [$this->tenant];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }
        };

        $context = new ActiveTenantContext($requestStack, $repository);
        $context->setActiveTenant($tenant);

        return $context;
    }

    public function testIndexShowsOnlyN8nServicesAndMenuLinkForSuperAdmin(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $n8nTool = new ExternalTool($tenant, 'Contact Context Mary', 'contact_context', 'n8n_webhook');
        $n8nTool->setWebhookUrl('https://n8n.example.test/webhook/contact-context');
        $n8nTool->setAuthType('bearer');
        $n8nTool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('super-secret-token'));
        $n8nTool->setDownstreamAuthorizationToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('crm-downstream-token'));
        $n8nTool->setTimeoutSeconds(7);
        $n8nTool->setConfig(['summary' => 'Bloque de contexto operativo']);
        $mcpTool = new ExternalTool($tenant, 'MCP principal', 'mcp_remote', 'openai_remote_mcp');
        $mcpTool->setWebhookUrl('https://mcp.example.test');
        $mcpTool->setConfig(['server_label' => 'principal_mcp']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $externalToolRepository = new class($tenant, $n8nTool, $mcpTool) extends ExternalToolRepository {
            public function __construct(
                private readonly Tenant $tenant,
                private readonly ExternalTool $n8nTool,
                private readonly ExternalTool $mcpTool,
            ) {
            }

            public function findByTenantAndProviderOrdered(\App\Entity\Tenant $tenant, string $provider): array
            {
                return [$this->n8nTool, $this->mcpTool];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->n8nTool->getId()->toRfc4122() === $id ? $this->n8nTool : $this->mcpTool;
            }
        };

        $controller = $this->createController($security, null, null, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Servicios n8n', $response->getContent());
        self::assertStringContainsString('/backend/n8n-services', $response->getContent());
        self::assertStringContainsString('Contact Context Mary', $response->getContent());
        self::assertStringContainsString('Token del webhook n8n: configurado', $response->getContent());
        self::assertStringContainsString('Token downstream CRM: configurado', $response->getContent());
        self::assertStringNotContainsString('MCP principal', $response->getContent());
        self::assertStringNotContainsString('super-secret-token', $response->getContent());
        self::assertStringNotContainsString('crm-downstream-token', $response->getContent());
    }

    public function testNewForcesN8nWebhookProviderAndPersistsSelectedType(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $persistedTool = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function ($entity) use (&$persistedTool): void {
                $persistedTool = $entity;
            });
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->createController($security, $entityManager, null, null, $this->createActiveTenantContext($tenant));
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->new(Request::create('/backend/n8n-services/new', 'POST', [
            '_csrf_token' => '',
            'name' => 'Contact Context Mary',
            'type' => 'crm_contact_submit',
            'provider' => 'mcp_remote',
            'webhookUrl' => 'https://n8n.example.test/webhook/contact-context',
            'authType' => 'bearer',
            'bearerToken' => 'webhook-token',
            'downstreamAuthorizationToken' => 'downstream-token',
            'timeoutSeconds' => '7',
            'isActive' => '1',
            'config' => '{"summary":"Contexto operativo"}',
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertInstanceOf(ExternalTool::class, $persistedTool);
        self::assertSame('crm_contact_submit', $persistedTool->getType());
        self::assertSame('n8n_webhook', $persistedTool->getProvider());
        self::assertSame('https://n8n.example.test/webhook/contact-context', $persistedTool->getWebhookUrl());
        self::assertSame('bearer', $persistedTool->getAuthType());
        self::assertSame('downstream-token', (new RuntimeSettingCipher('kernel-secret'))->decrypt((string) $persistedTool->getDownstreamAuthorizationToken()));
    }

    public function testEditForcesN8nWebhookProviderEvenIfPostDataTriesToChangeIt(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'Contact Context Mary', 'contact_context', 'n8n_webhook');
        $tool->setWebhookUrl('https://n8n.example.test/webhook/contact-context');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('existing-token'));
        $tool->setConfig(['summary' => 'Contexto operativo']);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function findByTenantAndProviderOrdered(\App\Entity\Tenant $tenant, string $provider): array
            {
                return [$this->tool];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tool;
            }
        };

        $controller = $this->createController($security, null, null, $externalToolRepository, $this->createActiveTenantContext($tenant));
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->edit(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/n8n-services/'.$tool->getId()->toRfc4122().'/edit', 'POST', [
                '_csrf_token' => '',
                'name' => 'Contact Context Mary',
                'type' => 'handoff_webhook',
                'provider' => 'openai_remote_mcp',
                'webhookUrl' => 'https://n8n.example.test/webhook/contact-context',
                'authType' => 'none',
                'bearerToken' => '',
                'downstreamAuthorizationToken' => 'updated-downstream-token',
                'timeoutSeconds' => '6',
                'isActive' => '1',
                'config' => '{"summary":"Contexto operativo actualizado"}',
            ])
        );

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('handoff_webhook', $tool->getType());
        self::assertSame('n8n_webhook', $tool->getProvider());
        self::assertSame('https://n8n.example.test/webhook/contact-context', $tool->getWebhookUrl());
        self::assertSame('updated-downstream-token', (new RuntimeSettingCipher('kernel-secret'))->decrypt((string) $tool->getDownstreamAuthorizationToken()));
    }

    public function testConnectionUsesHeadAndDoesNotExecuteWebhook(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'Contact Context Mary', 'contact_context', 'n8n_webhook');
        $tool->setWebhookUrl('https://n8n.example.test/webhook/contact-context');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('webhook-token'));
        $tool->setDownstreamAuthorizationToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('downstream-token'));
        $tool->setTimeoutSeconds(7);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('', ['http_code' => 204]);
        });

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function findByTenantAndProviderOrdered(\App\Entity\Tenant $tenant, string $provider): array
            {
                return [$this->tool];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tool;
            }
        };

        $controller = $this->createController($security, null, null, $externalToolRepository, $this->createActiveTenantContext($tenant), $httpClient);
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->testConnection(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/n8n-services/'.$tool->getId()->toRfc4122().'/test-connection', 'POST', [
                '_csrf_token' => '',
            ])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]['method']);
        self::assertSame('https://n8n.example.test/webhook/contact-context', $requests[0]['url']);
        self::assertSame('Authorization: Bearer webhook-token', $requests[0]['options']['normalized_headers']['authorization'][0] ?? null);
        self::assertSame('X-Downstream-Authorization: Bearer downstream-token', $requests[0]['options']['normalized_headers']['x-downstream-authorization'][0] ?? null);
        self::assertStringContainsString('Resultado de prueba de conexión', $response->getContent());
        self::assertStringContainsString('ok: true', $response->getContent());
        self::assertStringNotContainsString('webhook-token', $response->getContent());
    }

    public function testConnectionHides404WhenWebhookCannotBeValidatedWithoutExecution(): void
    {
        $tenant = new Tenant('Tech Investments', 'tech-investments');
        $tool = new ExternalTool($tenant, 'Contact Context Mary', 'contact_context', 'n8n_webhook');
        $tool->setWebhookUrl('https://n8n.example.test/webhook/contact-context');
        $tool->setAuthType('bearer');
        $tool->setBearerToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('webhook-token'));
        $tool->setDownstreamAuthorizationToken((new RuntimeSettingCipher('kernel-secret'))->encrypt('downstream-token'));
        $tool->setTimeoutSeconds(7);

        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('admin@example.com', ['admin']));

        $requests = [];
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = compact('method', 'url', 'options');

            return new MockResponse('', ['http_code' => 404]);
        });

        $externalToolRepository = new class($tool) extends ExternalToolRepository {
            public function __construct(private readonly ExternalTool $tool)
            {
            }

            public function findByTenantAndProviderOrdered(\App\Entity\Tenant $tenant, string $provider): array
            {
                return [$this->tool];
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tool;
            }
        };

        $controller = $this->createController($security, null, null, $externalToolRepository, $this->createActiveTenantContext($tenant), $httpClient);
        $container = new \Symfony\Component\DependencyInjection\Container();
        $container->set('twig', $this->createTwigEnvironment());
        $controller->setContainer($container);

        $response = $controller->testConnection(
            $tool->getId()->toRfc4122(),
            Request::create('/backend/n8n-services/'.$tool->getId()->toRfc4122().'/test-connection', 'POST', [
                '_csrf_token' => '',
            ])
        );

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertCount(1, $requests);
        self::assertSame('HEAD', $requests[0]['method']);
        self::assertSame('https://n8n.example.test/webhook/contact-context', $requests[0]['url']);
        self::assertSame('Authorization: Bearer webhook-token', $requests[0]['options']['normalized_headers']['authorization'][0] ?? null);
        self::assertSame('X-Downstream-Authorization: Bearer downstream-token', $requests[0]['options']['normalized_headers']['x-downstream-authorization'][0] ?? null);
        self::assertStringContainsString('Resultado de prueba de conexión', $response->getContent());
        self::assertStringContainsString('El host responde, pero no se pudo validar el webhook sin ejecutarlo.', $response->getContent());
        self::assertStringNotContainsString('404', $response->getContent());
    }
}
