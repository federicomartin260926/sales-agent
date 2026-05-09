<?php

namespace App\Entity;

use App\Repository\AiUsageEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AiUsageEventRepository::class)]
#[ORM\Table(
    name: 'ai_usage_events',
    indexes: [
        new ORM\Index(name: 'idx_ai_usage_events_tenant', columns: ['tenant_id']),
        new ORM\Index(name: 'idx_ai_usage_events_created_at', columns: ['created_at']),
    ]
)]
class AiUsageEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Conversation $conversation = null;

    #[ORM\ManyToOne(targetEntity: ConversationMessage::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ConversationMessage $conversationMessage = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'response_id', length: 255, nullable: true)]
    private ?string $responseId = null;

    #[ORM\Column(name: 'input_tokens', type: 'integer', nullable: true)]
    private ?int $inputTokens = null;

    #[ORM\Column(name: 'output_tokens', type: 'integer', nullable: true)]
    private ?int $outputTokens = null;

    #[ORM\Column(name: 'cached_tokens', type: 'integer', nullable: true)]
    private ?int $cachedTokens = null;

    #[ORM\Column(name: 'total_tokens', type: 'integer', nullable: true)]
    private ?int $totalTokens = null;

    #[ORM\Column(name: 'estimated_cost', type: 'float', nullable: true)]
    private ?float $estimatedCost = null;

    #[ORM\Column(name: 'latency_ms', type: 'integer', nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Tenant $tenant)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
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
    }

    public function getConversation(): ?Conversation
    {
        return $this->conversation;
    }

    public function setConversation(?Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function getConversationMessage(): ?ConversationMessage
    {
        return $this->conversationMessage;
    }

    public function setConversationMessage(?ConversationMessage $conversationMessage): void
    {
        $this->conversationMessage = $conversationMessage;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): void
    {
        $this->provider = $provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): void
    {
        $this->model = $model;
    }

    public function getResponseId(): ?string
    {
        return $this->responseId;
    }

    public function setResponseId(?string $responseId): void
    {
        $this->responseId = $responseId;
    }

    public function getInputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function setInputTokens(?int $inputTokens): void
    {
        $this->inputTokens = $inputTokens;
    }

    public function getOutputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function setOutputTokens(?int $outputTokens): void
    {
        $this->outputTokens = $outputTokens;
    }

    public function getCachedTokens(): ?int
    {
        return $this->cachedTokens;
    }

    public function setCachedTokens(?int $cachedTokens): void
    {
        $this->cachedTokens = $cachedTokens;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): void
    {
        $this->totalTokens = $totalTokens;
    }

    public function getEstimatedCost(): ?float
    {
        return $this->estimatedCost;
    }

    public function setEstimatedCost(?float $estimatedCost): void
    {
        $this->estimatedCost = $estimatedCost;
    }

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): void
    {
        $this->latencyMs = $latencyMs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenant_id' => $this->tenant->getId()->toRfc4122(),
            'conversation_id' => $this->conversation?->getId()->toRfc4122(),
            'conversation_message_id' => $this->conversationMessage?->getId()->toRfc4122(),
            'provider' => $this->provider,
            'model' => $this->model,
            'response_id' => $this->responseId,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'cached_tokens' => $this->cachedTokens,
            'total_tokens' => $this->totalTokens,
            'estimated_cost' => $this->estimatedCost,
            'latency_ms' => $this->latencyMs,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
