<?php

namespace App\Entity;

use App\Repository\ExternalContactContextCacheRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ExternalContactContextCacheRepository::class)]
#[ORM\Table(
    name: 'external_contact_context_cache',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_external_contact_context_cache_contact', columns: ['tenant_id', 'contact_key', 'provider']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_external_contact_context_cache_tenant_contact_provider', columns: ['tenant_id', 'contact_key', 'provider']),
        new ORM\Index(name: 'idx_external_contact_context_cache_tenant_conversation_provider', columns: ['tenant_id', 'external_conversation_id', 'provider']),
        new ORM\Index(name: 'idx_external_contact_context_cache_expires_at', columns: ['expires_at']),
    ]
)]
class ExternalContactContextCache
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(name: 'tenant_id', length: 64)]
    private string $tenantId;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $channel = null;

    #[ORM\Column(name: 'external_channel_id', length: 128, nullable: true)]
    private ?string $externalChannelId = null;

    #[ORM\Column(name: 'external_conversation_id', length: 255, nullable: true)]
    private ?string $externalConversationId = null;

    #[ORM\Column(name: 'contact_phone', length: 50, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(name: 'contact_email', length: 255, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(name: 'contact_key', length: 255)]
    private string $contactKey;

    #[ORM\Column(length: 50)]
    private string $provider = 'contact_context';

    #[ORM\Column(length: 50)]
    private string $source = 'mcp';

    #[ORM\Column(length: 50)]
    private string $status = 'success';

    #[ORM\Column(name: 'context_json', type: 'json', nullable: true)]
    private ?array $contextJson = null;

    #[ORM\Column(name: 'fetched_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $fetchedAt;

    #[ORM\Column(name: 'expires_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $tenantId, string $contactKey)
    {
        $now = new \DateTimeImmutable();
        $this->id = Uuid::v7();
        $this->tenantId = $tenantId;
        $this->contactKey = $contactKey;
        $this->fetchedAt = $now;
        $this->expiresAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenantId(): string
    {
        return $this->tenantId;
    }

    public function setTenantId(string $tenantId): void
    {
        $this->tenantId = $tenantId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getChannel(): ?string
    {
        return $this->channel;
    }

    public function setChannel(?string $channel): void
    {
        $this->channel = $channel;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExternalChannelId(): ?string
    {
        return $this->externalChannelId;
    }

    public function setExternalChannelId(?string $externalChannelId): void
    {
        $this->externalChannelId = $externalChannelId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExternalConversationId(): ?string
    {
        return $this->externalConversationId;
    }

    public function setExternalConversationId(?string $externalConversationId): void
    {
        $this->externalConversationId = $externalConversationId;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): void
    {
        $this->contactPhone = $contactPhone;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): void
    {
        $this->contactEmail = $contactEmail;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getContactKey(): string
    {
        return $this->contactKey;
    }

    public function setContactKey(string $contactKey): void
    {
        $this->contactKey = $contactKey;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getContextJson(): ?array
    {
        return $this->contextJson;
    }

    public function setContextJson(?array $contextJson): void
    {
        $this->contextJson = $contextJson;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getFetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }

    public function setFetchedAt(\DateTimeImmutable $fetchedAt): void
    {
        $this->fetchedAt = $fetchedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenant_id' => $this->tenantId,
            'channel' => $this->channel,
            'external_channel_id' => $this->externalChannelId,
            'external_conversation_id' => $this->externalConversationId,
            'contact_phone' => $this->contactPhone,
            'contact_email' => $this->contactEmail,
            'contact_key' => $this->contactKey,
            'provider' => $this->provider,
            'source' => $this->source,
            'status' => $this->status,
            'context_json' => $this->contextJson,
            'fetched_at' => $this->fetchedAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $this->expiresAt->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
