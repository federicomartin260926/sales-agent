<?php

namespace App\Entity;

use App\Repository\ConversationMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationMessageRepository::class)]
#[ORM\Table(
    name: 'conversation_messages',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_conversation_messages_external_message_id', columns: ['external_message_id']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_conversation_messages_conversation', columns: ['conversation_id']),
    ]
)]
class ConversationMessage
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Conversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Conversation $conversation;

    #[ORM\Column(length: 20)]
    private string $direction;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $role = null;

    #[ORM\Column(name: 'message_type', length: 30, nullable: true)]
    private ?string $messageType = null;

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(name: 'external_message_id', length: 255, nullable: true)]
    private ?string $externalMessageId = null;

    #[ORM\Column(name: 'external_timestamp', length: 50, nullable: true)]
    private ?string $externalTimestamp = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $provider = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'latency_ms', type: 'integer', nullable: true)]
    private ?int $latencyMs = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $intent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $action = null;

    #[ORM\Column(name: 'needs_human', type: 'boolean')]
    private bool $needsHuman = false;

    #[ORM\Column(name: 'error_code', length: 100, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(name: 'error_message', type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Conversation $conversation, string $direction, string $body)
    {
        $this->id = Uuid::v7();
        $this->conversation = $conversation;
        $this->direction = $direction;
        $this->body = $body;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function setConversation(Conversation $conversation): void
    {
        $this->conversation = $conversation;
    }

    public function getDirection(): string
    {
        return $this->direction;
    }

    public function setDirection(string $direction): void
    {
        $this->direction = $direction;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): void
    {
        $this->role = $role;
    }

    public function getMessageType(): ?string
    {
        return $this->messageType;
    }

    public function setMessageType(?string $messageType): void
    {
        $this->messageType = $messageType;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function getExternalMessageId(): ?string
    {
        return $this->externalMessageId;
    }

    public function setExternalMessageId(?string $externalMessageId): void
    {
        if (is_string($externalMessageId)) {
            $externalMessageId = trim($externalMessageId);
        }

        $this->externalMessageId = $externalMessageId !== '' ? $externalMessageId : null;
    }

    public function getExternalTimestamp(): ?string
    {
        return $this->externalTimestamp;
    }

    public function setExternalTimestamp(?string $externalTimestamp): void
    {
        $this->externalTimestamp = $externalTimestamp;
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

    public function getLatencyMs(): ?int
    {
        return $this->latencyMs;
    }

    public function setLatencyMs(?int $latencyMs): void
    {
        $this->latencyMs = $latencyMs;
    }

    public function getIntent(): ?string
    {
        return $this->intent;
    }

    public function setIntent(?string $intent): void
    {
        $this->intent = $intent;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): void
    {
        $this->score = $score;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): void
    {
        $this->action = $action;
    }

    public function isNeedsHuman(): bool
    {
        return $this->needsHuman;
    }

    public function setNeedsHuman(bool $needsHuman): void
    {
        $this->needsHuman = $needsHuman;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function setErrorCode(?string $errorCode): void
    {
        $this->errorCode = $errorCode;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): void
    {
        $this->rawPayload = $rawPayload;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'conversationId' => $this->conversation->getId()->toRfc4122(),
            'direction' => $this->direction,
            'role' => $this->role,
            'messageType' => $this->messageType,
            'body' => $this->body,
            'externalMessageId' => $this->externalMessageId,
            'externalTimestamp' => $this->externalTimestamp,
            'provider' => $this->provider,
            'model' => $this->model,
            'latencyMs' => $this->latencyMs,
            'intent' => $this->intent,
            'score' => $this->score,
            'action' => $this->action,
            'needsHuman' => $this->needsHuman,
            'errorCode' => $this->errorCode,
            'errorMessage' => $this->errorMessage,
            'rawPayload' => $this->rawPayload,
            'metadata' => $this->metadata,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
