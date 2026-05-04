<?php

namespace App\Entity;

use App\Repository\ExternalToolRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ExternalToolRepository::class)]
#[ORM\Table(
    name: 'external_tools',
    indexes: [
        new ORM\Index(name: 'idx_external_tools_tenant', columns: ['tenant_id']),
    ]
)]
class ExternalTool
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255)]
    private string $type;

    #[ORM\Column(length: 255)]
    private string $provider;

    #[ORM\Column(name: 'webhook_url', type: 'text', nullable: true)]
    private ?string $webhookUrl = null;

    #[ORM\Column(name: 'auth_type', length: 50, nullable: true)]
    private ?string $authType = null;

    #[ORM\Column(name: 'bearer_token', type: 'text', nullable: true)]
    private ?string $bearerToken = null;

    #[ORM\Column(name: 'timeout_seconds', type: 'integer')]
    private int $timeoutSeconds = 5;

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'json')]
    private array $config = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Tenant $tenant, string $name = '', string $type = '', string $provider = '')
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->name = $name;
        $this->type = $type;
        $this->provider = $provider;
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
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
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

    public function getWebhookUrl(): ?string
    {
        return $this->webhookUrl;
    }

    public function setWebhookUrl(?string $webhookUrl): void
    {
        $trimmed = $webhookUrl !== null ? trim($webhookUrl) : null;
        $this->webhookUrl = $trimmed !== '' ? $trimmed : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getAuthType(): ?string
    {
        return $this->authType;
    }

    public function setAuthType(?string $authType): void
    {
        $trimmed = $authType !== null ? trim($authType) : null;
        $this->authType = $trimmed !== '' ? $trimmed : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getBearerToken(): ?string
    {
        return $this->bearerToken;
    }

    public function setBearerToken(?string $bearerToken): void
    {
        $trimmed = $bearerToken !== null ? trim($bearerToken) : null;
        $this->bearerToken = $trimmed !== '' ? $trimmed : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function setTimeoutSeconds(int $timeoutSeconds): void
    {
        $this->timeoutSeconds = max(1, $timeoutSeconds);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setActive(bool $isActive): void
    {
        $this->isActive = $isActive;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenantId' => $this->tenant->getId()->toRfc4122(),
            'name' => $this->name,
            'type' => $this->type,
            'provider' => $this->provider,
            'webhookUrl' => $this->webhookUrl,
            'authType' => $this->authType,
            'bearerToken' => $this->bearerToken,
            'timeoutSeconds' => $this->timeoutSeconds,
            'isActive' => $this->isActive,
            'config' => $this->config,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
