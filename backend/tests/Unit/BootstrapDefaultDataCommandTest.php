<?php

namespace App\Tests\Unit;

use App\Command\BootstrapDefaultDataCommand;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapDefaultDataCommandTest extends TestCase
{
    public function testItCreatesInitialAdminTenantAndPlaybook(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $tenantRepository = $this->createStub(EntityRepository::class);
        $userRepository = $this->createStub(EntityRepository::class);
        $productRepository = $this->createStub(EntityRepository::class);
        $playbookRepository = $this->createStub(EntityRepository::class);

        $tenantRepository->method('findOneBy')->willReturn(null);
        $userRepository->method('findOneBy')->willReturn(null);
        $productRepository->method('findOneBy')->willReturn(null);
        $playbookRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) {
            return match ($criteria['name'] ?? null) {
                'Guía comercial de prueba' => null,
                'Guía comercial de WhatsApp Automation' => null,
                default => null,
            };
        });

        $entityManager->expects(self::exactly(4))
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($tenantRepository, $userRepository, $productRepository, $playbookRepository) {
                return match ($class) {
                    Tenant::class => $tenantRepository,
                    User::class => $userRepository,
                    Product::class => $productRepository,
                    Playbook::class => $playbookRepository,
                    default => throw new \RuntimeException(sprintf('Unexpected repository %s', $class)),
                };
            });

        $persisted = [];
        $entityManager->expects(self::exactly(7))
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $entityManager->expects(self::once())
            ->method('flush');

        $passwordHasher->expects(self::once())
            ->method('hashPassword')
            ->with(self::isInstanceOf(User::class), '1234')
            ->willReturn('hashed-password');

        $command = new BootstrapDefaultDataCommand($entityManager, $passwordHasher);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertCount(7, $persisted);

        $product = $persisted[1];
        self::assertInstanceOf(Product::class, $product);
        self::assertSame('WhatsApp Automation', $product->getName());
        self::assertSame('Producto demo para validar el flujo comercial de WhatsApp.', $product->getDescription());
        self::assertSame('Permite automatizar conversaciones de WhatsApp con contexto comercial.', $product->getValueProposition());
        self::assertArrayHasKey('positioning', $product->getSalesPolicy());
        self::assertArrayHasKey('pricingNotes', $product->getSalesPolicy());
        self::assertArrayHasKey('handoffRules', $product->getSalesPolicy());

        $secondProduct = $persisted[2];
        self::assertInstanceOf(Product::class, $secondProduct);
        self::assertSame('Lead Qualification Pack', $secondProduct->getName());
        self::assertArrayHasKey('positioning', $secondProduct->getSalesPolicy());

        $thirdProduct = $persisted[3];
        self::assertInstanceOf(Product::class, $thirdProduct);
        self::assertSame('Follow-up Assistant', $thirdProduct->getName());
        self::assertArrayHasKey('positioning', $thirdProduct->getSalesPolicy());

        $user = $persisted[4];
        self::assertInstanceOf(User::class, $user);
        self::assertSame('federicomartin2609@gmail.com', $user->getEmail());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('hashed-password', $user->getPassword());

        $tenant = $persisted[0];
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame('federico-martin-demo', $tenant->getSlug());
        self::assertSame('consultivo', $tenant->getTone());
        self::assertArrayHasKey('positioning', $tenant->getSalesPolicy());
        self::assertArrayHasKey('qualificationFocus', $tenant->getSalesPolicy());
        self::assertArrayHasKey('handoffRules', $tenant->getSalesPolicy());

        $generalPlaybook = $persisted[5];
        self::assertInstanceOf(Playbook::class, $generalPlaybook);
        self::assertSame('Guía comercial de prueba', $generalPlaybook->getName());
        self::assertNull($generalPlaybook->getProduct());
        self::assertArrayHasKey('objective', $generalPlaybook->getConfig());
        self::assertArrayHasKey('qualificationQuestions', $generalPlaybook->getConfig());
        self::assertArrayHasKey('scoring', $generalPlaybook->getConfig());
        self::assertArrayHasKey('allowedActions', $generalPlaybook->getConfig());

        $productPlaybook = $persisted[6];
        self::assertInstanceOf(Playbook::class, $productPlaybook);
        self::assertSame('Guía comercial de WhatsApp Automation', $productPlaybook->getName());
        self::assertSame($product, $productPlaybook->getProduct());
        self::assertArrayHasKey('objective', $productPlaybook->getConfig());
        self::assertArrayHasKey('qualificationQuestions', $productPlaybook->getConfig());
        self::assertArrayHasKey('scoring', $productPlaybook->getConfig());
        self::assertArrayHasKey('allowedActions', $productPlaybook->getConfig());
    }

    public function testItSkipsWhenDataAlreadyExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $tenant = new Tenant('Negocio demo Federico Martín', 'federico-martin-demo');
        $user = new User('federicomartin2609@gmail.com', ['admin'], 'Federico Martín');
        $product = new Product($tenant, 'WhatsApp Automation');
        $secondProduct = new Product($tenant, 'Lead Qualification Pack');
        $thirdProduct = new Product($tenant, 'Follow-up Assistant');
        $generalPlaybook = new Playbook($tenant, 'Guía comercial de prueba');
        $productPlaybook = new Playbook($tenant, 'Guía comercial de WhatsApp Automation', $product);

        $tenantRepository = $this->createStub(EntityRepository::class);
        $userRepository = $this->createStub(EntityRepository::class);
        $productRepository = $this->createStub(EntityRepository::class);
        $playbookRepository = $this->createStub(EntityRepository::class);

        $tenantRepository->method('findOneBy')->willReturn($tenant);
        $userRepository->method('findOneBy')->willReturn($user);
        $productRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($product, $secondProduct, $thirdProduct) {
            return match ($criteria['name'] ?? null) {
                'WhatsApp Automation' => $product,
                'Lead Qualification Pack' => $secondProduct,
                'Follow-up Assistant' => $thirdProduct,
                default => null,
            };
        });
        $playbookRepository->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($generalPlaybook, $productPlaybook) {
            return match ($criteria['name'] ?? null) {
                'Guía comercial de prueba' => $generalPlaybook,
                'Guía comercial de WhatsApp Automation' => $productPlaybook,
                default => null,
            };
        });

        $entityManager->expects(self::exactly(4))
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($tenantRepository, $userRepository, $productRepository, $playbookRepository) {
                return match ($class) {
                    Tenant::class => $tenantRepository,
                    User::class => $userRepository,
                    Product::class => $productRepository,
                    Playbook::class => $playbookRepository,
                    default => throw new \RuntimeException(sprintf('Unexpected repository %s', $class)),
                };
            });

        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');
        $passwordHasher->expects(self::never())->method('hashPassword');

        $command = new BootstrapDefaultDataCommand($entityManager, $passwordHasher);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Bootstrap data already exists.', $tester->getDisplay());
    }
}
