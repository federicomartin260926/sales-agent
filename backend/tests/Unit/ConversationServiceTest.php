<?php

namespace App\Tests\Unit;

use App\Entity\Conversation;
use App\Entity\EntryPoint;
use App\Entity\EntryPointUtm;
use App\Entity\Playbook;
use App\Entity\Product;
use App\Entity\Tenant;
use App\Repository\ConversationRepository;
use App\Service\ConversationService;
use PHPUnit\Framework\TestCase;

final class ConversationServiceTest extends TestCase
{
    private function createTenant(): Tenant
    {
        $tenant = new Tenant('Negocio Demo', 'negocio-demo');
        $tenant->setWhatsappPublicPhone('+34600000000');

        return $tenant;
    }

    private function createProduct(Tenant $tenant): Product
    {
        return new Product($tenant, 'CRM Automation');
    }

    private function createPlaybook(Tenant $tenant, ?Product $product = null): Playbook
    {
        $playbook = new Playbook($tenant, 'Guía comercial', $product);
        $playbook->setConfig([
            'objective' => 'Calificar leads.',
            'qualificationQuestions' => ['¿Qué negocio tienes?'],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
                'positiveSignals' => [],
                'negativeSignals' => [],
            ],
            'handoffRules' => ['Derivar a humano.'],
            'allowedActions' => ['askQuestion'],
        ]);

        return $playbook;
    }

    public function testUpsertCreatesConversationAndMarksEntryPointUtmMatched(): void
    {
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $this->createPlaybook($tenant, $product);
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');
        $entryPoint->setDefaultMessage('Hola, quiero información.');

        $entryPointUtm = new EntryPointUtm($entryPoint, 'abc123');
        $entryPointUtm->setUtmSource('google');
        $entryPointUtm->setUtmMedium('cpc');
        $entryPointUtm->setUtmCampaign('crm_pymes');

        $conversationRepository = new class extends ConversationRepository {
            public array $savedConversations = [];

            public function __construct()
            {
            }

            public function findActiveByTenantPhone(Tenant $tenant, string $customerPhone): ?Conversation
            {
                return null;
            }

            public function save(Conversation $conversation, bool $flush = true): void
            {
                $this->savedConversations[] = $conversation;
            }
        };

        $service = new ConversationService($conversationRepository);
        $result = $service->upsert(
            $tenant,
            '+34999999999',
            $product,
            $entryPoint,
            $entryPointUtm,
            'Ana García',
            'Hola, quiero información.',
            'wa-conversation-1',
        );

        self::assertTrue($result['created']);
        self::assertCount(1, $conversationRepository->savedConversations);
        self::assertSame('matched', $entryPointUtm->getStatus());
        self::assertNotNull($entryPointUtm->getMatchedAt());
        self::assertSame($product->getId()->toRfc4122(), $result['conversation']->getProduct()?->getId()->toRfc4122());
        self::assertSame($entryPoint->getId()->toRfc4122(), $result['conversation']->getEntryPoint()?->getId()->toRfc4122());
        self::assertSame($entryPointUtm->getId()->toRfc4122(), $result['conversation']->getEntryPointUtm()?->getId()->toRfc4122());
        self::assertSame('google', $result['conversation']->getUtmSource());
        self::assertSame('crm_pymes', $result['conversation']->getUtmCampaign());
    }

    public function testUpsertReusesActiveConversationByTenantAndPhone(): void
    {
        $tenant = $this->createTenant();
        $product = $this->createProduct($tenant);
        $entryPoint = new EntryPoint($product, 'crm-demo', 'CRM Demo');

        $existingConversation = new Conversation($tenant, '+34999999999');
        $existingConversation->setProduct($product);
        $existingConversation->setEntryPoint($entryPoint);

        $conversationRepository = new class($existingConversation) extends ConversationRepository {
            public function __construct(private readonly Conversation $conversation)
            {
            }

            public function findActiveByTenantPhone(Tenant $tenant, string $customerPhone): ?Conversation
            {
                return $this->conversation;
            }

            public function save(Conversation $conversation, bool $flush = true): void
            {
            }
        };

        $service = new ConversationService($conversationRepository);
        $result = $service->upsert($tenant, '+34999999999', $product, $entryPoint, null, null, 'Nuevo mensaje');

        self::assertFalse($result['created']);
        self::assertSame($existingConversation, $result['conversation']);
        self::assertSame('Nuevo mensaje', $result['conversation']->getFirstMessage());
    }
}
