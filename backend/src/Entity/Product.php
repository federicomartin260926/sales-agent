<?php

namespace App\Entity;

use App\Domain\CommercialDomainSchema;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(
    name: 'products',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_products_tenant_slug', columns: ['tenant_id', 'slug']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_products_external_identity', columns: ['tenant_id', 'external_source', 'external_reference']),
    ]
)]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $slug = null;

    #[Assert\Length(max: 100)]
    #[ORM\Column(name: 'external_source', length: 100, nullable: true)]
    private ?string $externalSource = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(name: 'external_reference', length: 255, nullable: true)]
    private ?string $externalReference = null;

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: 'text')]
    private string $valueProposition = '';

    #[ORM\Column(name: 'base_price_cents', type: 'integer', nullable: true)]
    private ?int $basePriceCents = null;

    #[Assert\Length(max: 10)]
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = null;

    #[Assert\Callback]
    public function validateSalesPolicy(ExecutionContextInterface $context): void
    {
        $error = CommercialDomainSchema::validateProductSalesPolicy($this->salesPolicy);
        if ($error !== null) {
            $context->buildViolation($error)->atPath('salesPolicy')->addViolation();
        }
    }

    #[ORM\Column(type: 'json')]
    private array $salesPolicy = [];

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function __construct(Tenant $tenant, string $name = '', ?string $slug = null)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->name = $name;
        $this->slug = $slug !== null && trim($slug) !== '' ? self::normalizeSlug($slug) : self::generateSlug($name);
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
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug ?? self::generateSlug($this->name);
    }

    public function setSlug(string $slug): void
    {
        $trimmed = trim($slug);
        $this->slug = $trimmed !== '' ? self::normalizeSlug($trimmed) : self::generateSlug($this->name);
    }

    public function getExternalSource(): ?string
    {
        return $this->externalSource;
    }

    public function setExternalSource(?string $externalSource): void
    {
        $trimmed = $externalSource !== null ? trim($externalSource) : null;
        $this->externalSource = $trimmed !== '' ? $trimmed : null;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): void
    {
        $trimmed = $externalReference !== null ? trim($externalReference) : null;
        $this->externalReference = $trimmed !== '' ? $trimmed : null;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    public function getValueProposition(): string
    {
        return $this->valueProposition;
    }

    public function setValueProposition(string $valueProposition): void
    {
        $this->valueProposition = $valueProposition;
    }

    public function getBasePriceCents(): ?int
    {
        return $this->basePriceCents;
    }

    public function setBasePriceCents(?int $basePriceCents): void
    {
        $this->basePriceCents = $basePriceCents;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void
    {
        $trimmed = $currency !== null ? trim($currency) : null;
        $this->currency = $trimmed !== '' ? strtoupper($trimmed) : null;
    }

    public function getSalesPolicy(): array
    {
        return $this->salesPolicy;
    }

    public function setSalesPolicy(array $salesPolicy): void
    {
        $this->salesPolicy = $salesPolicy;
    }

    public function getSalesPolicySummary(): string
    {
        return CommercialDomainSchema::summarizeProductSalesPolicy($this->salesPolicy);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenantId' => $this->tenant->getId()->toRfc4122(),
            'name' => $this->name,
            'slug' => $this->getSlug(),
            'externalSource' => $this->externalSource,
            'externalReference' => $this->externalReference,
            'description' => $this->description,
            'valueProposition' => $this->valueProposition,
            'basePriceCents' => $this->basePriceCents,
            'currency' => $this->currency,
            'salesPolicy' => $this->salesPolicy,
            'isActive' => $this->isActive,
        ];
    }

    private static function generateSlug(string $value): string
    {
        $slug = self::normalizeSlug($value);

        return $slug !== '' ? $slug : 'product';
    }

    private static function normalizeSlug(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $slug = $ascii !== false ? $ascii : $value;
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug;
    }
}
