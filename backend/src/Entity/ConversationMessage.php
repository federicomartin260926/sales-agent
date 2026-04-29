<?php

namespace App\Entity;

use App\Repository\ConversationMessageRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ConversationMessageRepository::class)]
#[ORM\Table(
    name: 'conversation_messages',
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

    #[ORM\Column(type: 'text')]
    private string $body;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

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

    public function getBody(): string
    {
        return $this->body;
    }

    public function setBody(string $body): void
    {
        $this->body = $body;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): void
    {
        $this->rawPayload = $rawPayload;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
