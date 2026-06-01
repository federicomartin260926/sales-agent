<?php

namespace App\Entity;

use App\Repository\TenantAiUsagePolicyRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantAiUsagePolicyRepository::class)]
#[ORM\Table(
    name: 'tenant_ai_usage_policies',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_tenant_ai_usage_policy_tenant', columns: ['tenant_id']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_tenant_ai_usage_policy_tenant', columns: ['tenant_id']),
    ]
)]
class TenantAiUsagePolicy
{
    public const DEFAULT_MAX_AUDIO_TRANSCRIPTION_SECONDS = 60;
    public const DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE = 'El audio es demasiado largo para procesarlo automáticamente. Por favor, envíame un audio más corto o escríbeme el mensaje por texto.';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\OneToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(name: 'ai_enabled', type: 'boolean')]
    private bool $aiEnabled = true;

    #[ORM\Column(name: 'monthly_cost_limit_eur', type: 'float', nullable: true)]
    private ?float $monthlyCostLimitEur = null;

    #[ORM\Column(name: 'daily_cost_limit_eur', type: 'float', nullable: true)]
    private ?float $dailyCostLimitEur = null;

    #[ORM\Column(name: 'default_model', length: 100, nullable: true)]
    private ?string $defaultModel = null;

    #[ORM\Column(name: 'fallback_model', length: 100, nullable: true)]
    private ?string $fallbackModel = null;

    #[ORM\Column(name: 'max_audio_transcription_seconds', type: 'integer', nullable: true)]
    private ?int $maxAudioTranscriptionSeconds = null;

    #[ORM\Column(name: 'audio_limit_exceeded_message', type: 'text', nullable: true)]
    private ?string $audioLimitExceededMessage = null;

    #[ORM\Column(name: 'limit_action', length: 50)]
    private string $limitAction = 'handoff_human';

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Tenant $tenant)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
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

    public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    public function setAiEnabled(bool $aiEnabled): void
    {
        $this->aiEnabled = $aiEnabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMonthlyCostLimitEur(): ?float
    {
        return $this->monthlyCostLimitEur;
    }

    public function setMonthlyCostLimitEur(?float $monthlyCostLimitEur): void
    {
        $this->monthlyCostLimitEur = $monthlyCostLimitEur;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDailyCostLimitEur(): ?float
    {
        return $this->dailyCostLimitEur;
    }

    public function setDailyCostLimitEur(?float $dailyCostLimitEur): void
    {
        $this->dailyCostLimitEur = $dailyCostLimitEur;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getDefaultModel(): ?string
    {
        return $this->defaultModel;
    }

    public function setDefaultModel(?string $defaultModel): void
    {
        $this->defaultModel = $defaultModel !== null ? trim($defaultModel) : null;
        if ($this->defaultModel === '') {
            $this->defaultModel = null;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFallbackModel(): ?string
    {
        return $this->fallbackModel;
    }

    public function setFallbackModel(?string $fallbackModel): void
    {
        $this->fallbackModel = $fallbackModel !== null ? trim($fallbackModel) : null;
        if ($this->fallbackModel === '') {
            $this->fallbackModel = null;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMaxAudioTranscriptionSeconds(): int
    {
        return $this->maxAudioTranscriptionSeconds ?? self::DEFAULT_MAX_AUDIO_TRANSCRIPTION_SECONDS;
    }

    public function setMaxAudioTranscriptionSeconds(?int $maxAudioTranscriptionSeconds): void
    {
        if ($maxAudioTranscriptionSeconds === null) {
            $this->maxAudioTranscriptionSeconds = null;
            $this->updatedAt = new \DateTimeImmutable();

            return;
        }

        $this->maxAudioTranscriptionSeconds = max(1, $maxAudioTranscriptionSeconds);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAudioLimitExceededMessage(): string
    {
        $message = $this->audioLimitExceededMessage;
        if ($message === null) {
            return self::DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE;
        }

        $message = trim($message);
        if ($message === '') {
            return self::DEFAULT_AUDIO_LIMIT_EXCEEDED_MESSAGE;
        }

        return $message;
    }

    public function setAudioLimitExceededMessage(?string $audioLimitExceededMessage): void
    {
        $audioLimitExceededMessage = $audioLimitExceededMessage !== null ? trim($audioLimitExceededMessage) : null;
        $this->audioLimitExceededMessage = $audioLimitExceededMessage !== '' ? $audioLimitExceededMessage : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getLimitAction(): string
    {
        return $this->limitAction;
    }

    public function setLimitAction(string $limitAction): void
    {
        $limitAction = trim($limitAction);
        $this->limitAction = $limitAction !== '' ? $limitAction : 'handoff_human';
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

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenant_id' => $this->tenant->getId()->toRfc4122(),
            'ai_enabled' => $this->aiEnabled,
            'monthly_cost_limit_eur' => $this->monthlyCostLimitEur,
            'daily_cost_limit_eur' => $this->dailyCostLimitEur,
            'default_model' => $this->defaultModel,
            'fallback_model' => $this->fallbackModel,
            'max_audio_transcription_seconds' => $this->getMaxAudioTranscriptionSeconds(),
            'audio_limit_exceeded_message' => $this->getAudioLimitExceededMessage(),
            'limit_action' => $this->limitAction,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
