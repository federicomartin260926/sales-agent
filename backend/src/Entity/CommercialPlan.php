<?php

namespace App\Entity;

use App\Repository\CommercialPlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommercialPlanRepository::class)]
#[ORM\Table(name: 'commercial_plans', uniqueConstraints: [
    new ORM\UniqueConstraint(name: 'uniq_commercial_plans_code', columns: ['code']),
])]
class CommercialPlan
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 50)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean')]
    private bool $featured = false;

    #[ORM\Column(name: 'monthly_price_eur', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $monthlyPriceEur = null;

    #[ORM\Column(name: 'yearly_price_eur', type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $yearlyPriceEur = null;

    #[ORM\Column(length: 10)]
    private string $currency = 'EUR';

    #[ORM\Column(name: 'display_order', type: 'integer')]
    private int $displayOrder = 0;

    #[ORM\Column(type: 'json')]
    private array $features = [];

    #[ORM\Column(type: 'json')]
    private array $limits = [];

    #[ORM\Column(name: 'stripe_product_id', length: 255, nullable: true)]
    private ?string $stripeProductId = null;

    #[ORM\Column(name: 'stripe_monthly_price_id', length: 255, nullable: true)]
    private ?string $stripeMonthlyPriceId = null;

    #[ORM\Column(name: 'stripe_yearly_price_id', length: 255, nullable: true)]
    private ?string $stripeYearlyPriceId = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $code = '', string $name = '')
    {
        $this->id = Uuid::v7();
        $this->code = $code;
        $this->name = $name;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = strtolower(trim($code));
        $this->touch();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $trimmed = $description !== null ? trim($description) : null;
        $this->description = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->touch();
    }

    public function isFeatured(): bool
    {
        return $this->featured;
    }

    public function setFeatured(bool $featured): void
    {
        $this->featured = $featured;
        $this->touch();
    }

    public function getMonthlyPriceEur(): ?string
    {
        return $this->monthlyPriceEur;
    }

    public function setMonthlyPriceEur(?string $monthlyPriceEur): void
    {
        $trimmed = $monthlyPriceEur !== null ? trim($monthlyPriceEur) : null;
        $this->monthlyPriceEur = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getYearlyPriceEur(): ?string
    {
        return $this->yearlyPriceEur;
    }

    public function setYearlyPriceEur(?string $yearlyPriceEur): void
    {
        $trimmed = $yearlyPriceEur !== null ? trim($yearlyPriceEur) : null;
        $this->yearlyPriceEur = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $currency = strtoupper(trim($currency));
        $this->currency = $currency !== '' ? $currency : 'EUR';
        $this->touch();
    }

    public function getDisplayOrder(): int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(int $displayOrder): void
    {
        $this->displayOrder = $displayOrder;
        $this->touch();
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): void
    {
        $this->features = $features;
        $this->touch();
    }

    public function getLimits(): array
    {
        return $this->limits;
    }

    public function setLimits(array $limits): void
    {
        $this->limits = $limits;
        $this->touch();
    }

    public function getStripeProductId(): ?string
    {
        return $this->stripeProductId;
    }

    public function setStripeProductId(?string $stripeProductId): void
    {
        $trimmed = $stripeProductId !== null ? trim($stripeProductId) : null;
        $this->stripeProductId = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getStripeMonthlyPriceId(): ?string
    {
        return $this->stripeMonthlyPriceId;
    }

    public function setStripeMonthlyPriceId(?string $stripeMonthlyPriceId): void
    {
        $trimmed = $stripeMonthlyPriceId !== null ? trim($stripeMonthlyPriceId) : null;
        $this->stripeMonthlyPriceId = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getStripeYearlyPriceId(): ?string
    {
        return $this->stripeYearlyPriceId;
    }

    public function setStripeYearlyPriceId(?string $stripeYearlyPriceId): void
    {
        $trimmed = $stripeYearlyPriceId !== null ? trim($stripeYearlyPriceId) : null;
        $this->stripeYearlyPriceId = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
