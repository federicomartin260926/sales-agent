<?php

namespace App\Tests\Unit;

use App\Command\BootstrapDefaultDataCommand;
use App\Entity\Playbook;
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
        $playbookRepository = $this->createStub(EntityRepository::class);

        $tenantRepository->method('findOneBy')->willReturn(null);
        $userRepository->method('findOneBy')->willReturn(null);
        $playbookRepository->method('findOneBy')->willReturn(null);

        $entityManager->expects(self::exactly(3))
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($tenantRepository, $userRepository, $playbookRepository) {
                return match ($class) {
                    Tenant::class => $tenantRepository,
                    User::class => $userRepository,
                    Playbook::class => $playbookRepository,
                    default => throw new \RuntimeException(sprintf('Unexpected repository %s', $class)),
                };
            });

        $persisted = [];
        $entityManager->expects(self::exactly(3))
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
        self::assertCount(3, $persisted);

        $user = $persisted[1];
        self::assertInstanceOf(User::class, $user);
        self::assertSame('federicomartin2609@gmail.com', $user->getEmail());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('hashed-password', $user->getPassword());

        $tenant = $persisted[0];
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame('federico-martin-demo', $tenant->getSlug());
        self::assertSame('consultivo', $tenant->getTone());

        $playbook = $persisted[2];
        self::assertInstanceOf(Playbook::class, $playbook);
        self::assertSame('Guía comercial de prueba', $playbook->getName());
        self::assertArrayHasKey('fallback_action', $playbook->getConfig());
    }

    public function testItSkipsWhenDataAlreadyExists(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);

        $tenant = new Tenant('Negocio demo Federico Martín', 'federico-martin-demo');
        $user = new User('federicomartin2609@gmail.com', ['admin'], 'Federico Martín');
        $playbook = new Playbook($tenant, 'Guía comercial de prueba');

        $tenantRepository = $this->createStub(EntityRepository::class);
        $userRepository = $this->createStub(EntityRepository::class);
        $playbookRepository = $this->createStub(EntityRepository::class);

        $tenantRepository->method('findOneBy')->willReturn($tenant);
        $userRepository->method('findOneBy')->willReturn($user);
        $playbookRepository->method('findOneBy')->willReturn($playbook);

        $entityManager->expects(self::exactly(3))
            ->method('getRepository')
            ->willReturnCallback(static function (string $class) use ($tenantRepository, $userRepository, $playbookRepository) {
                return match ($class) {
                    Tenant::class => $tenantRepository,
                    User::class => $userRepository,
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
