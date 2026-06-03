<?php

namespace App\Entity;

use App\Repository\AiModelCostReferenceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AiModelCostReferenceRepository::class)]
#[ORM\Table(
    name: 'ai_model_cost_references',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_ai_model_cost_references_usage_model', columns: ['usage_type', 'model']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_ai_model_cost_references_usage_type', columns: ['usage_type']),
        new ORM\Index(name: 'idx_ai_model_cost_references_active', columns: ['active']),
    ]
)]
class AiModelCostReference
{
    public const USAGE_TYPE_LLM_CHAT = 'llm_chat';
    public const USAGE_TYPE_AUDIO_TRANSCRIPTION = 'audio_transcription';

    public const COST_UNIT_MINUTE = 'minute';
    public const COST_UNIT_SECOND = 'second';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'usage_type', length: 50)]
    private string $usageType;

    #[ORM\Column(length: 100)]
    private string $model;

    #[ORM\Column(name: 'input_cost_per_million', type: 'float', nullable: true)]
    private ?float $inputCostPerMillion = null;

    #[ORM\Column(name: 'cached_input_cost_per_million', type: 'float', nullable: true)]
    private ?float $cachedInputCostPerMillion = null;

    #[ORM\Column(name: 'output_cost_per_million', type: 'float', nullable: true)]
    private ?float $outputCostPerMillion = null;

    #[ORM\Column(name: 'cost_unit', length: 20, nullable: true)]
    private ?string $costUnit = null;

    #[ORM\Column(name: 'cost_per_unit', type: 'float', nullable: true)]
    private ?float $costPerUnit = null;

    #[ORM\Column(length: 10)]
    private string $currency = 'USD';

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $usageType = self::USAGE_TYPE_LLM_CHAT, string $model = '')
    {
        $this->id = Uuid::v7();
        $this->usageType = $usageType;
        $this->model = $model;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUsageType(): string
    {
        return $this->usageType;
    }

    public function setUsageType(string $usageType): void
    {
        $usageType = strtolower(trim($usageType));
        if (!in_array($usageType, [self::USAGE_TYPE_LLM_CHAT, self::USAGE_TYPE_AUDIO_TRANSCRIPTION], true)) {
            $usageType = self::USAGE_TYPE_LLM_CHAT;
        }

        $this->usageType = $usageType;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): void
    {
        $this->model = trim($model);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getInputCostPerMillion(): ?float
    {
        return $this->inputCostPerMillion;
    }

    public function setInputCostPerMillion(?float $inputCostPerMillion): void
    {
        $this->inputCostPerMillion = $inputCostPerMillion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCachedInputCostPerMillion(): ?float
    {
        return $this->cachedInputCostPerMillion;
    }

    public function setCachedInputCostPerMillion(?float $cachedInputCostPerMillion): void
    {
        $this->cachedInputCostPerMillion = $cachedInputCostPerMillion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getOutputCostPerMillion(): ?float
    {
        return $this->outputCostPerMillion;
    }

    public function setOutputCostPerMillion(?float $outputCostPerMillion): void
    {
        $this->outputCostPerMillion = $outputCostPerMillion;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCostUnit(): ?string
    {
        return $this->costUnit;
    }

    public function setCostUnit(?string $costUnit): void
    {
        $costUnit = $costUnit !== null ? strtolower(trim($costUnit)) : null;
        if ($costUnit !== null && !in_array($costUnit, [self::COST_UNIT_MINUTE, self::COST_UNIT_SECOND], true)) {
            $costUnit = null;
        }

        $this->costUnit = $costUnit;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCostPerUnit(): ?float
    {
        return $this->costPerUnit;
    }

    public function setCostPerUnit(?float $costPerUnit): void
    {
        $this->costPerUnit = $costPerUnit;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $currency = strtoupper(trim($currency));
        $this->currency = $currency !== '' ? $currency : 'USD';
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $notes = $notes !== null ? trim($notes) : null;
        $this->notes = $notes !== '' ? $notes : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
