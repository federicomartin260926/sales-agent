<?php

namespace App\Entity;

use App\Domain\CommercialDomainSchema;
use App\Repository\TenantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants')]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $name;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180, unique: true)]
    private string $slug;

    #[Assert\Length(max: 5000)]
    #[ORM\Column(type: 'text')]
    private string $businessContext = '';

    #[Assert\Length(max: 120)]
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $tone = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(name: 'whatsapp_phone_number_id', length: 255, nullable: true)]
    private ?string $whatsappPhoneNumberId = null;

    #[Assert\Length(max: 50)]
    #[ORM\Column(name: 'whatsapp_public_phone', length: 50, nullable: true)]
    private ?string $whatsappPublicPhone = null;

    #[ORM\Column(name: 'human_handoff_enabled', type: 'boolean')]
    private bool $humanHandoffEnabled = false;

    #[Assert\Length(max: 50)]
    #[ORM\Column(name: 'human_handoff_whatsapp_public', length: 50, nullable: true)]
    private ?string $humanHandoffWhatsappPublic = null;

    #[Assert\Length(max: 4000)]
    #[ORM\Column(name: 'human_handoff_message', type: 'text', nullable: true)]
    private ?string $humanHandoffMessage = null;

    #[ORM\Column(name: 'human_handoff_strategy', length: 50)]
    private string $humanHandoffStrategy = 'disabled';

    #[Assert\Callback]
    public function validateSalesPolicy(ExecutionContextInterface $context): void
    {
        $error = CommercialDomainSchema::validateTenantSalesPolicy($this->salesPolicy);
        if ($error !== null) {
            $context->buildViolation($error)->atPath('salesPolicy')->addViolation();
        }
    }

    #[ORM\Column(type: 'json')]
    private array $salesPolicy = [];

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name = '', string $slug = '')
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->slug = $slug;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
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
        return $this->slug;
    }

    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    public function getBusinessContext(): string
    {
        return $this->businessContext;
    }

    public function setBusinessContext(string $businessContext): void
    {
        $this->businessContext = $businessContext;
    }

    public function getTone(): ?string
    {
        return $this->tone;
    }

    public function setTone(?string $tone): void
    {
        $this->tone = $tone;
    }

    public function getWhatsappPhoneNumberId(): ?string
    {
        return $this->whatsappPhoneNumberId;
    }

    public function setWhatsappPhoneNumberId(?string $whatsappPhoneNumberId): void
    {
        $this->whatsappPhoneNumberId = $whatsappPhoneNumberId;
    }

    public function getWhatsappPublicPhone(): ?string
    {
        return $this->whatsappPublicPhone;
    }

    public function setWhatsappPublicPhone(?string $whatsappPublicPhone): void
    {
        $this->whatsappPublicPhone = $whatsappPublicPhone;
    }

    public function isHumanHandoffEnabled(): bool
    {
        return $this->humanHandoffEnabled;
    }

    public function setHumanHandoffEnabled(bool $humanHandoffEnabled): void
    {
        $this->humanHandoffEnabled = $humanHandoffEnabled;
    }

    public function getHumanHandoffWhatsappPublic(): ?string
    {
        return $this->humanHandoffWhatsappPublic;
    }

    public function setHumanHandoffWhatsappPublic(?string $humanHandoffWhatsappPublic): void
    {
        $trimmed = $humanHandoffWhatsappPublic !== null ? trim($humanHandoffWhatsappPublic) : null;
        $this->humanHandoffWhatsappPublic = $trimmed !== '' ? $trimmed : null;
    }

    public function getHumanHandoffMessage(): ?string
    {
        return $this->humanHandoffMessage;
    }

    public function setHumanHandoffMessage(?string $humanHandoffMessage): void
    {
        $trimmed = $humanHandoffMessage !== null ? trim($humanHandoffMessage) : null;
        $this->humanHandoffMessage = $trimmed !== '' ? $trimmed : null;
    }

    public function getHumanHandoffStrategy(): string
    {
        return $this->humanHandoffStrategy;
    }

    public function setHumanHandoffStrategy(string $humanHandoffStrategy): void
    {
        $strategy = trim($humanHandoffStrategy);
        $this->humanHandoffStrategy = $strategy !== '' ? $strategy : 'disabled';
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
        return CommercialDomainSchema::summarizeTenantSalesPolicy($this->salesPolicy);
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'name' => $this->name,
            'slug' => $this->slug,
            'businessContext' => $this->businessContext,
            'tone' => $this->tone,
            'whatsappPhoneNumberId' => $this->whatsappPhoneNumberId,
            'whatsappPublicPhone' => $this->whatsappPublicPhone,
            'humanHandoffEnabled' => $this->humanHandoffEnabled,
            'humanHandoffWhatsappPublic' => $this->humanHandoffWhatsappPublic,
            'humanHandoffMessage' => $this->humanHandoffMessage,
            'humanHandoffStrategy' => $this->humanHandoffStrategy,
            'salesPolicy' => $this->salesPolicy,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
