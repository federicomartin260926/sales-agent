<?php

namespace App\Tests\Unit;

use App\Controller\Web\AiModelCostController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AiModelCostReferenceRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use App\Service\RuntimeConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AiModelCostControllerTest extends TestCase
{
    public function testIndexRendersFullSidebarAndModelosIaTitle(): void
    {
        $tenant = $this->tenant('Mary Esteticista', 'mary-esteticista');
        $controller = $this->controller($tenant);

        $response = $controller->index(Request::create('/backend/ai-costs', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Modelos IA', $response->getContent());
        self::assertStringContainsString('Negocio activo', $response->getContent());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('Administración técnica', $response->getContent());
        self::assertStringContainsString('Plataforma', $response->getContent());
        self::assertStringContainsString('<a class="active" href="/backend/ai-costs">Modelos IA</a>', $response->getContent());
    }

    private function controller(Tenant $tenant): AiModelCostController
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('owner@example.com', ['super_admin'], 'Owner'));

        $requestStack = new RequestStack();
        $request = Request::create('/backend/ai-costs');
        $request->setSession(new Session());
        $requestStack->push($request);

        $tenantRepository = new class($tenant) extends TenantRepository {
            public function __construct(private Tenant $tenant)
            {
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->tenant;
            }
        };

        $activeTenantContext = new ActiveTenantContext($requestStack, $tenantRepository);
        $activeTenantContext->setActiveTenant($tenant);

        return new AiModelCostController(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $this->createTwigEnvironment(),
            $this->createStub(RuntimeConfigurationService::class),
            $activeTenantContext,
            $this->createStub(AiModelCostReferenceRepository::class),
            null,
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

    private function tenant(string $name, string $slug): Tenant
    {
        $tenant = new Tenant($name, $slug);
        $tenant->setActive(true);

        return $tenant;
    }
}
