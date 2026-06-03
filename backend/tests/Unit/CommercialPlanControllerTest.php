<?php

namespace App\Tests\Unit;

use App\Controller\Web\CommercialPlanController;
use App\Entity\CommercialPlan;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\CommercialPlanRepository;
use App\Repository\TenantRepository;
use App\Service\ActiveTenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class CommercialPlanControllerTest extends TestCase
{
    public function testIndexRendersModelosIaSidebarAndPlanList(): void
    {
        $tenant = $this->tenant('Mary Esteticista', 'mary-esteticista');
        $plans = [$this->plan('starter', 'Starter', true), $this->plan('pro', 'Pro', true)];

        $controller = $this->controller($tenant, $plans);
        $response = $controller->index(Request::create('/backend/plans', 'GET'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Planes comerciales', $response->getContent());
        self::assertStringContainsString('Negocio activo', $response->getContent());
        self::assertStringContainsString('Uso IA', $response->getContent());
        self::assertStringContainsString('Administración técnica', $response->getContent());
        self::assertStringContainsString('Plataforma', $response->getContent());
        self::assertStringContainsString('<a class="active" href="/backend/plans">Planes comerciales</a>', $response->getContent());
        self::assertStringContainsString('Starter', $response->getContent());
        self::assertStringContainsString('Pro', $response->getContent());
        self::assertStringContainsString('/backend/plans/'.$plans[0]->getId()->toRfc4122().'/edit', $response->getContent());
    }

    public function testEditRendersAndUpdatesPlan(): void
    {
        $tenant = $this->tenant('Mary Esteticista', 'mary-esteticista');
        $plan = $this->plan('starter', 'Starter', true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::identicalTo($plan));
        $entityManager->expects(self::once())->method('flush');

        $controller = $this->controller($tenant, [$plan], $entityManager);
        $response = $controller->edit($plan->getId()->toRfc4122(), Request::create('/backend/plans/'.$plan->getId()->toRfc4122().'/edit', 'POST', [
            'code' => 'starter',
            'name' => 'Starter Plus',
            'description' => 'Plan base renovado',
            'active' => '1',
            'featured' => '1',
            'monthlyPriceEur' => '39.00',
            'yearlyPriceEur' => '390.00',
            'currency' => 'EUR',
            'displayOrder' => '15',
            'features' => json_encode([
                'ai_agent' => true,
                'whatsapp_channel' => true,
                'human_handoff' => 'basic',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'limits' => json_encode([
                'included_monthly_ai_tokens' => 2000000,
                'monthly_conversations' => 1000,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'stripeProductId' => 'prod_test',
            'stripeMonthlyPriceId' => 'price_monthly_test',
            'stripeYearlyPriceId' => 'price_yearly_test',
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertSame('/backend/plans', $response->headers->get('Location'));
        self::assertSame('Starter Plus', $plan->getName());
        self::assertSame('Plan base renovado', $plan->getDescription());
        self::assertTrue($plan->isFeatured());
        self::assertSame('39.00', $plan->getMonthlyPriceEur());
        self::assertSame('390.00', $plan->getYearlyPriceEur());
        self::assertSame('EUR', $plan->getCurrency());
        self::assertSame(15, $plan->getDisplayOrder());
        self::assertSame('prod_test', $plan->getStripeProductId());
    }

    private function controller(Tenant $tenant, array $plans, ?EntityManagerInterface $entityManager = null): CommercialPlanController
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SUPER_ADMIN');
        $security->method('getUser')->willReturn(new User('owner@example.com', ['super_admin'], 'Owner'));

        $requestStack = new RequestStack();
        $request = Request::create('/backend/plans');
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

        $controller = new CommercialPlanController(
            $security,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->createTwigEnvironment(),
            $activeTenantContext,
            $this->commercialPlanRepository($plans, $plans[0] ?? null),
            null,
        );

        $container = new Container();
        $container->set('request_stack', $requestStack);
        $controller->setContainer($container);

        return $controller;
    }

    /**
     * @param CommercialPlan[] $plans
     */
    private function commercialPlanRepository(array $plans, ?CommercialPlan $foundPlan = null): CommercialPlanRepository
    {
        return new class($plans, $foundPlan) extends CommercialPlanRepository {
            /**
             * @param CommercialPlan[] $plans
             */
            public function __construct(
                private array $plans,
                private ?CommercialPlan $foundPlan,
            ) {
            }

            public function findAllOrdered(): array
            {
                return $this->plans;
            }

            public function find($id, $lockMode = null, $lockVersion = null): ?object
            {
                return $this->foundPlan;
            }
        };
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

    private function plan(string $code, string $name, bool $featured): CommercialPlan
    {
        $plan = new CommercialPlan($code, $name);
        $plan->setDescription(sprintf('%s description', $name));
        $plan->setActive(true);
        $plan->setFeatured($featured);
        $plan->setMonthlyPriceEur('29.00');
        $plan->setYearlyPriceEur('290.00');
        $plan->setCurrency('EUR');
        $plan->setDisplayOrder($featured ? 10 : 30);
        $plan->setFeatures([
            'ai_agent' => true,
            'whatsapp_channel' => true,
        ]);
        $plan->setLimits([
            'included_monthly_ai_tokens' => 1000000,
        ]);

        return $plan;
    }
}
