<?php

namespace App\Entity;

use App\Repository\EntryPointRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EntryPointRepository::class)]
#[ORM\Table(
    name: 'entry_points',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_entry_points_code', columns: ['code']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_entry_points_product', columns: ['product_id']),
        new ORM\Index(name: 'idx_entry_points_playbook', columns: ['playbook_id']),
    ]
)]
class EntryPoint
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Product $product;

    #[ORM\ManyToOne(targetEntity: Playbook::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Playbook $playbook = null;

    #[ORM\Column(length: 120)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $source = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $medium = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $campaign = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $term = null;

    #[ORM\Column(name: 'crm_branch_ref', length: 255, nullable: true)]
    private ?string $crmBranchRef = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $defaultMessage = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Product $product, string $code, string $name)
    {
        $this->id = Uuid::v7();
        $this->product = $product;
        $this->code = $code !== '' ? $code : self::generateCode();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    private static function generateCode(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTenant(): Tenant
    {
        return $this->product->getTenant();
    }

    public function getPlaybook(): ?Playbook
    {
        return $this->playbook;
    }

    public function setPlaybook(?Playbook $playbook): void
    {
        $this->playbook = $playbook;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code !== '' ? $code : self::generateCode();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): void
    {
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMedium(): ?string
    {
        return $this->medium;
    }

    public function setMedium(?string $medium): void
    {
        $this->medium = $medium;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCampaign(): ?string
    {
        return $this->campaign;
    }

    public function setCampaign(?string $campaign): void
    {
        $this->campaign = $campaign;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function setContent(?string $content): void
    {
        $this->content = $content;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTerm(): ?string
    {
        return $this->term;
    }

    public function setTerm(?string $term): void
    {
        $this->term = $term;
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

    public function getDefaultMessage(): ?string
    {
        return $this->defaultMessage;
    }

    public function setDefaultMessage(?string $defaultMessage): void
    {
        $this->defaultMessage = $defaultMessage;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenantId' => $this->getTenant()->getId()->toRfc4122(),
            'productId' => $this->product?->getId()->toRfc4122(),
            'playbookId' => $this->playbook?->getId()->toRfc4122(),
            'code' => $this->code,
            'name' => $this->name,
            'source' => $this->source,
            'medium' => $this->medium,
            'campaign' => $this->campaign,
            'content' => $this->content,
            'term' => $this->term,
            'crmBranchRef' => $this->crmBranchRef,
            'defaultMessage' => $this->defaultMessage,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
