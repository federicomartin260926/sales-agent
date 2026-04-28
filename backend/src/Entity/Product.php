<?php

namespace App\Entity;

use App\Domain\CommercialDomainSchema;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'products')]
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

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: 'text')]
    private string $description = '';

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: 'text')]
    private string $valueProposition = '';

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

    public function __construct(Tenant $tenant, string $name = '')
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->name = $name;
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
            'description' => $this->description,
            'valueProposition' => $this->valueProposition,
            'salesPolicy' => $this->salesPolicy,
            'isActive' => $this->isActive,
        ];
    }
}
