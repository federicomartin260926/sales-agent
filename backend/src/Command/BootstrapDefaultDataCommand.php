<?php

namespace App\Command;

use App\Entity\Playbook;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:bootstrap:default-data',
    description: 'Create the initial admin user and demo playbook if they do not exist.',
)]
final class BootstrapDefaultDataCommand extends Command
{
    private const ADMIN_EMAIL = 'federicomartin2609@gmail.com';
    private const INITIAL_PASSWORD = '1234';
    private const TENANT_NAME = 'Federico Martin Demo';
    private const TENANT_SLUG = 'federico-martin-demo';
    private const PLAYBOOK_NAME = 'Playbook de prueba';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changes = [];

        /** @var ObjectRepository<Tenant> $tenantRepository */
        $tenantRepository = $this->entityManager->getRepository(Tenant::class);
        /** @var ObjectRepository<User> $userRepository */
        $userRepository = $this->entityManager->getRepository(User::class);
        /** @var ObjectRepository<Playbook> $playbookRepository */
        $playbookRepository = $this->entityManager->getRepository(Playbook::class);

        $tenant = $tenantRepository->findOneBy(['slug' => self::TENANT_SLUG]);
        if (!$tenant instanceof Tenant) {
            $tenant = new Tenant(self::TENANT_NAME, self::TENANT_SLUG);
            $tenant->setBusinessContext('Tenant de arranque para pruebas del backend administrativo.');
            $tenant->setTone('consultivo');
            $tenant->setSalesPolicy([
                'welcome' => 'Responder de forma clara y breve.',
                'handoff' => 'Derivar a humano si el cliente pide seguimiento manual.',
            ]);
            $tenant->setActive(true);

            $this->entityManager->persist($tenant);
            $changes[] = 'tenant';
        }

        $user = $userRepository->findOneBy(['email' => self::ADMIN_EMAIL]);
        if (!$user instanceof User) {
            $user = new User(self::ADMIN_EMAIL, ['admin']);
            $user->setPassword($this->passwordHasher->hashPassword($user, self::INITIAL_PASSWORD));
            $user->setActive(true);

            $this->entityManager->persist($user);
            $changes[] = 'admin user';
        }

        $playbook = $playbookRepository->findOneBy([
            'tenant' => $tenant,
            'name' => self::PLAYBOOK_NAME,
        ]);
        if (!$playbook instanceof Playbook) {
            $playbook = new Playbook($tenant, self::PLAYBOOK_NAME);
            $playbook->setConfig([
                'goal' => 'Responder a pruebas iniciales del backend',
                'tone' => 'consultivo',
                'steps' => [
                    'saludar',
                    'identificar necesidad',
                    'ofrecer siguiente paso',
                ],
                'fallback_action' => 'handoff',
            ]);
            $playbook->setActive(true);

            $this->entityManager->persist($playbook);
            $changes[] = 'playbook';
        }

        if ($changes !== []) {
            $this->entityManager->flush();
            $output->writeln(sprintf('Created bootstrap data: %s.', implode(', ', $changes)));
        } else {
            $output->writeln('Bootstrap data already exists.');
        }

        $output->writeln(sprintf('Admin login: %s / %s', self::ADMIN_EMAIL, self::INITIAL_PASSWORD));

        return Command::SUCCESS;
    }
}
