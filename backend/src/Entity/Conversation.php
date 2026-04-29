<?php

namespace App\Entity;

use App\Repository\ConversationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationRepository::class)]
#[ORM\Table(
    name: 'conversations',
    indexes: [
        new ORM\Index(name: 'idx_conversations_tenant', columns: ['tenant_id']),
        new ORM\Index(name: 'idx_conversations_entry_point', columns: ['entry_point_id']),
        new ORM\Index(name: 'idx_conversations_entry_point_utm', columns: ['entry_point_utm_id']),
        new ORM\Index(name: 'idx_conversations_product', columns: ['product_id']),
        new ORM\Index(name: 'idx_conversations_customer_phone', columns: ['customer_phone']),
        new ORM\Index(name: 'idx_conversations_status', columns: ['status']),
    ]
)]
class Conversation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: EntryPoint::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EntryPoint $entryPoint = null;

    #[ORM\ManyToOne(targetEntity: EntryPointUtm::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?EntryPointUtm $entryPointUtm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalConversationId = null;

    #[ORM\Column(name: 'customer_phone', length: 50)]
    private string $customerPhone;

    #[ORM\Column(name: 'customer_name', length: 255, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(length: 20)]
    private string $status = 'active';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $firstMessage = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastMessageAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'utm_source', length: 255, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(name: 'utm_medium', length: 255, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(name: 'utm_campaign', length: 255, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(name: 'utm_term', length: 255, nullable: true)]
    private ?string $utmTerm = null;

    #[ORM\Column(name: 'utm_content', length: 255, nullable: true)]
    private ?string $utmContent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gclid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fbclid = null;

    #[ORM\Column(name: 'crm_branch_ref', length: 255, nullable: true)]
    private ?string $crmBranchRef = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Tenant $tenant, string $customerPhone)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->customerPhone = $customerPhone;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): void
    {
        $this->product = $product;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEntryPoint(): ?EntryPoint
    {
        return $this->entryPoint;
    }

    public function setEntryPoint(?EntryPoint $entryPoint): void
    {
        $this->entryPoint = $entryPoint;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getEntryPointUtm(): ?EntryPointUtm
    {
        return $this->entryPointUtm;
    }

    public function setEntryPointUtm(?EntryPointUtm $entryPointUtm): void
    {
        $this->entryPointUtm = $entryPointUtm;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExternalConversationId(): ?string
    {
        return $this->externalConversationId;
    }

    public function setExternalConversationId(?string $externalConversationId): void
    {
        $this->externalConversationId = $externalConversationId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCustomerPhone(): string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(string $customerPhone): void
    {
        $this->customerPhone = $customerPhone;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): void
    {
        $this->customerName = $customerName;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFirstMessage(): ?string
    {
        return $this->firstMessage;
    }

    public function setFirstMessage(?string $firstMessage): void
    {
        $this->firstMessage = $firstMessage;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLastMessageAt(): ?\DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function setLastMessageAt(?\DateTimeImmutable $lastMessageAt): void
    {
        $this->lastMessageAt = $lastMessageAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUtmSource(): ?string
    {
        return $this->utmSource;
    }

    public function setUtmSource(?string $utmSource): void
    {
        $this->utmSource = $utmSource;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUtmMedium(): ?string
    {
        return $this->utmMedium;
    }

    public function setUtmMedium(?string $utmMedium): void
    {
        $this->utmMedium = $utmMedium;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUtmCampaign(): ?string
    {
        return $this->utmCampaign;
    }

    public function setUtmCampaign(?string $utmCampaign): void
    {
        $this->utmCampaign = $utmCampaign;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUtmTerm(): ?string
    {
        return $this->utmTerm;
    }

    public function setUtmTerm(?string $utmTerm): void
    {
        $this->utmTerm = $utmTerm;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }

    public function setUtmContent(?string $utmContent): void
    {
        $this->utmContent = $utmContent;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getGclid(): ?string
    {
        return $this->gclid;
    }

    public function setGclid(?string $gclid): void
    {
        $this->gclid = $gclid;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFbclid(): ?string
    {
        return $this->fbclid;
    }

    public function setFbclid(?string $fbclid): void
    {
        $this->fbclid = $fbclid;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCrmBranchRef(): ?string
    {
        return $this->crmBranchRef;
    }

    public function setCrmBranchRef(?string $crmBranchRef): void
    {
        $this->crmBranchRef = $crmBranchRef;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenantId' => $this->tenant->getId()->toRfc4122(),
            'productId' => $this->product?->getId()->toRfc4122(),
            'entryPointId' => $this->entryPoint?->getId()->toRfc4122(),
            'entryPointUtmId' => $this->entryPointUtm?->getId()->toRfc4122(),
            'externalConversationId' => $this->externalConversationId,
            'customerPhone' => $this->customerPhone,
            'customerName' => $this->customerName,
            'status' => $this->status,
            'firstMessage' => $this->firstMessage,
            'lastMessageAt' => $this->lastMessageAt?->format(\DateTimeInterface::ATOM),
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
            'utmSource' => $this->utmSource,
            'utmMedium' => $this->utmMedium,
            'utmCampaign' => $this->utmCampaign,
            'utmTerm' => $this->utmTerm,
            'utmContent' => $this->utmContent,
            'gclid' => $this->gclid,
            'fbclid' => $this->fbclid,
            'crmBranchRef' => $this->crmBranchRef,
        ];
    }
}
