<?php

namespace App\Tests\Unit;

use App\Controller\Web\ExternalToolController;
use App\Entity\User;
use App\Repository\ExternalToolRepository;
use App\Repository\TenantRepository;
use App\Service\RuntimeSettingCipher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ExternalToolControllerTest extends TestCase
{
    private function createController(Security $security): ExternalToolController
    {
        $tenantRepository = new class extends TenantRepository {
            public function __construct()
            {
            }

            public function findAllOrdered(): array
            {
                return [];
            }
        };

        $externalToolRepository = new class extends ExternalToolRepository {
            public function __construct()
            {
            }

            public function findAllOrdered(): array
            {
                return [];
            }
        };

        return new ExternalToolController(
            $security,
            $this->createStub(EntityManagerInterface::class),
            $tenantRepository,
            $externalToolRepository,
            new RuntimeSettingCipher('kernel-secret'),
            $this->createStub(HttpClientInterface::class),
            'test-bearer-token',
            $this->createStub(CsrfTokenManagerInterface::class),
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

        $response = $controller->index(new \Symfony\Component\HttpFoundation\Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('Federico Martín', $response->getContent());
        self::assertStringNotContainsString('<strong>Usuario</strong>', $response->getContent());
    }
}
