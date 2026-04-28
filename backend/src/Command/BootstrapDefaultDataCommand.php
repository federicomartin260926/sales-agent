<?php

namespace App\Command;

use App\Entity\Playbook;
use App\Entity\Product;
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
    description: 'Create the initial admin user plus the demo commercial tenant, products and playbooks if they do not exist.',
)]
final class BootstrapDefaultDataCommand extends Command
{
    private const ADMIN_EMAIL = 'federicomartin2609@gmail.com';
    private const INITIAL_PASSWORD = '1234';
    private const TENANT_NAME = 'Negocio demo Federico Martín';
    private const TENANT_SLUG = 'federico-martin-demo';
    private const PRODUCTS = [
        [
            'name' => 'WhatsApp Automation',
            'description' => 'Producto demo para validar el flujo comercial de WhatsApp.',
            'valueProposition' => 'Permite automatizar conversaciones de WhatsApp con contexto comercial.',
            'salesPolicy' => [
                'positioning' => 'Oferta de prueba para validar conversación y cualificación.',
                'pricingNotes' => 'Demo interna sin precio comercial real.',
                'handoffRules' => [
                    'Derivar a humano si el lead pide información fuera de la demo.',
                ],
                'notes' => 'Producto semilla para entorno de arranque.',
            ],
        ],
        [
            'name' => 'Lead Qualification Pack',
            'description' => 'Paquete demo para cualificar leads antes de pasar a ventas.',
            'valueProposition' => 'Reduce el ruido y prioriza leads con encaje real.',
            'salesPolicy' => [
                'positioning' => 'Enfoque en discovery y cualificación rápida.',
                'pricingNotes' => 'Paquete orientativo para pruebas internas.',
                'handoffRules' => [
                    'Derivar cuando el lead pida una propuesta formal.',
                ],
                'notes' => 'Semilla de catálogo para probar selección por contexto.',
            ],
        ],
        [
            'name' => 'Follow-up Assistant',
            'description' => 'Servicio demo para seguimiento comercial y recordatorios.',
            'valueProposition' => 'Mejora el seguimiento de oportunidades y reduce olvidos.',
            'salesPolicy' => [
                'positioning' => 'Apoyo comercial para acompañar oportunidades abiertas.',
                'pricingNotes' => 'Sin precio real en el entorno de prueba.',
                'handoffRules' => [
                    'Derivar a humano si el lead solicita negociación específica.',
                ],
                'notes' => 'Sirve para validar otro ángulo comercial distinto al de WhatsApp.',
            ],
        ],
    ];
    private const GENERAL_PLAYBOOK_NAME = 'Guía comercial de prueba';
    private const PRODUCT_PLAYBOOK_NAME = 'Guía comercial de WhatsApp Automation';

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
        /** @var ObjectRepository<Product> $productRepository */
        $productRepository = $this->entityManager->getRepository(Product::class);
        /** @var ObjectRepository<Playbook> $playbookRepository */
        $playbookRepository = $this->entityManager->getRepository(Playbook::class);

        $tenant = $tenantRepository->findOneBy(['slug' => self::TENANT_SLUG]);
        if (!$tenant instanceof Tenant) {
            $tenant = new Tenant(self::TENANT_NAME, self::TENANT_SLUG);
            $tenant->setBusinessContext('Negocio de arranque para pruebas del backend administrativo.');
            $tenant->setTone('consultivo');
            $tenant->setSalesPolicy([
                'positioning' => 'Respuestas claras, breves y orientadas a discovery.',
                'qualificationFocus' => 'Entender el negocio, el volumen y el canal principal.',
                'handoffRules' => 'Derivar a humano si el cliente pide seguimiento manual o un cierre específico.',
                'salesBoundaries' => [
                    'No prometer integraciones inexistentes.',
                    'No inventar plazos ni precios.',
                ],
                'notes' => 'Base de arranque para pruebas del backend administrativo.',
            ]);
            $tenant->setActive(true);

            $this->entityManager->persist($tenant);
            $changes[] = 'tenant';
        }

        $productsByName = [];
        foreach (self::PRODUCTS as $productSeed) {
            $existingProduct = $productRepository->findOneBy([
                'tenant' => $tenant,
                'name' => $productSeed['name'],
            ]);

            if ($existingProduct instanceof Product) {
                $productsByName[$productSeed['name']] = $existingProduct;
                continue;
            }

            $product = new Product($tenant, $productSeed['name']);
            $product->setDescription($productSeed['description']);
            $product->setValueProposition($productSeed['valueProposition']);
            $product->setSalesPolicy($productSeed['salesPolicy']);
            $product->setActive(true);

            $this->entityManager->persist($product);
            $changes[] = sprintf('product %s', $productSeed['name']);
            $productsByName[$productSeed['name']] = $product;
        }

        $product = $productsByName['WhatsApp Automation'] ?? null;
        if (!$product instanceof Product) {
            $product = $productRepository->findOneBy([
                'tenant' => $tenant,
                'name' => 'WhatsApp Automation',
            ]);
        }

        $user = $userRepository->findOneBy(['email' => self::ADMIN_EMAIL]);
        if (!$user instanceof User) {
            $user = new User(self::ADMIN_EMAIL, ['admin'], 'Federico Martín');
            $user->setPassword($this->passwordHasher->hashPassword($user, self::INITIAL_PASSWORD));
            $user->setActive(true);

            $this->entityManager->persist($user);
            $changes[] = 'admin user';
        } elseif ($user->getName() === '') {
            $user->setName('Federico Martín');
            $changes[] = 'admin user name';
        }

        $generalPlaybook = $playbookRepository->findOneBy([
            'tenant' => $tenant,
            'name' => self::GENERAL_PLAYBOOK_NAME,
        ]);
        if (!$generalPlaybook instanceof Playbook) {
            $generalPlaybook = new Playbook($tenant, self::GENERAL_PLAYBOOK_NAME);
            $generalPlaybook->setConfig([
                'objective' => 'Cualificar leads entrantes y proponer el siguiente paso comercial.',
                'qualificationQuestions' => [
                    '¿Qué tipo de negocio tienes?',
                    '¿Cuántas conversaciones o leads gestionas al mes?',
                    '¿Qué quieres automatizar exactamente?',
                ],
                'scoring' => [
                    'maxScore' => 10,
                    'handoffThreshold' => 7,
                    'positiveSignals' => [
                        'El cliente conoce su volumen y caso de uso.',
                        'Pide siguiente paso o demo.',
                    ],
                    'negativeSignals' => [
                        'No tiene decisión clara.',
                        'Pide soporte fuera de alcance.',
                    ],
                ],
                'agendaRules' => [
                    'Ofrecer agenda cuando el lead supera el umbral de interés.',
                ],
                'handoffRules' => [
                    'Derivar a humano si pide seguimiento manual.',
                ],
                'allowedActions' => [
                    'askQuestion',
                    'qualifyLead',
                    'proposeMeeting',
                    'handoffToHuman',
                ],
                'notes' => 'Guía de prueba general para validar el runtime conversacional.',
            ]);
            $generalPlaybook->setActive(true);

            $this->entityManager->persist($generalPlaybook);
            $changes[] = 'general playbook';
        } elseif ($generalPlaybook->getProduct() !== null) {
            $generalPlaybook->setProduct(null);
            $changes[] = 'general playbook reset';
        }

        $productPlaybook = $playbookRepository->findOneBy([
            'tenant' => $tenant,
            'name' => self::PRODUCT_PLAYBOOK_NAME,
        ]);
        if (!$productPlaybook instanceof Playbook) {
            $productPlaybook = new Playbook($tenant, self::PRODUCT_PLAYBOOK_NAME, $product);
            $productPlaybook->setConfig([
                'objective' => 'Cualificar leads interesados en WhatsApp Automation y avanzar a demo.',
                'qualificationQuestions' => [
                    '¿Qué problema quieres resolver con WhatsApp Automation?',
                    '¿Cuántos mensajes o leads gestionas al mes?',
                    '¿Ya tienes un equipo comercial o quieres automatizar la primera respuesta?',
                ],
                'scoring' => [
                    'maxScore' => 10,
                    'handoffThreshold' => 7,
                    'positiveSignals' => [
                        'Quiere automatizar WhatsApp de forma inmediata.',
                        'Tiene volumen suficiente para justificar automatización.',
                    ],
                    'negativeSignals' => [
                        'Solo busca información general sin necesidad concreta.',
                        'No tiene claro el caso de uso.',
                    ],
                ],
                'agendaRules' => [
                    'Ofrecer agenda cuando haya interés real por automatizar WhatsApp.',
                ],
                'handoffRules' => [
                    'Derivar a humano si piden pricing avanzado o integración específica.',
                ],
                'allowedActions' => [
                    'askQuestion',
                    'qualifyLead',
                    'proposeMeeting',
                    'handoffToHuman',
                ],
                'notes' => 'Guía específica del producto demo WhatsApp Automation.',
            ]);
            $productPlaybook->setActive(true);

            $this->entityManager->persist($productPlaybook);
            $changes[] = 'product playbook';
        } elseif ($productPlaybook->getProduct() === null) {
            $productPlaybook->setProduct($product);
            $changes[] = 'product playbook link';
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
