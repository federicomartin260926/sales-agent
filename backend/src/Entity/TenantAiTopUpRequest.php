<?php

namespace App\Entity;

use App\Repository\TenantAiTopUpRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantAiTopUpRequestRepository::class)]
#[ORM\Table(
    name: 'tenant_ai_top_up_requests',
    indexes: [
        new ORM\Index(name: 'idx_tenant_ai_top_up_requests_tenant', columns: ['tenant_id']),
        new ORM\Index(name: 'idx_tenant_ai_top_up_requests_status', columns: ['status']),
        new ORM\Index(name: 'idx_tenant_ai_top_up_requests_created_at', columns: ['created_at']),
    ]
)]
class TenantAiTopUpRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requestedBy = null;

    #[ORM\Column(name: 'requested_amount_eur', type: 'float')]
    private float $requestedAmountEur;

    #[ORM\Column(name: 'approved_tokens', type: 'integer', nullable: true)]
    private ?int $approvedTokens = null;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $resolvedBy = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $adminNotes = null;

    public function __construct(Tenant $tenant, float $requestedAmountEur, string $message)
    {
        $this->id = Uuid::v7();
        $this->tenant = $tenant;
        $this->requestedAmountEur = $requestedAmountEur;
        $this->message = trim($message);
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

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): void
    {
        $this->requestedBy = $requestedBy;
    }

    public function getRequestedAmountEur(): float
    {
        return $this->requestedAmountEur;
    }

    public function setRequestedAmountEur(float $requestedAmountEur): void
    {
        $this->requestedAmountEur = $requestedAmountEur;
    }

    public function getApprovedTokens(): ?int
    {
        return $this->approvedTokens;
    }

    public function setApprovedTokens(?int $approvedTokens): void
    {
        if ($approvedTokens !== null && $approvedTokens < 1) {
            $approvedTokens = null;
        }

        $this->approvedTokens = $approvedTokens;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): void
    {
        $this->message = trim($message);
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $status = strtolower(trim($status));
        $this->status = in_array($status, [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED], true)
            ? $status
            : self::STATUS_PENDING;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): void
    {
        $this->resolvedAt = $resolvedAt;
    }

    public function getResolvedBy(): ?User
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?User $resolvedBy): void
    {
        $this->resolvedBy = $resolvedBy;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function setAdminNotes(?string $adminNotes): void
    {
        $adminNotes = $adminNotes !== null ? trim($adminNotes) : null;
        $this->adminNotes = $adminNotes !== '' ? $adminNotes : null;
    }

    public function markPending(): void
    {
        $this->status = self::STATUS_PENDING;
        $this->resolvedAt = null;
        $this->resolvedBy = null;
    }

    public function approve(User $resolvedBy, ?int $approvedTokens = null, ?string $adminNotes = null): void
    {
        $this->status = self::STATUS_APPROVED;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->setApprovedTokens($approvedTokens);
        $this->setAdminNotes($adminNotes ?? 'Aprobada por super admin');
    }

    public function reject(User $resolvedBy, ?string $adminNotes = null): void
    {
        $this->status = self::STATUS_REJECTED;
        $this->resolvedBy = $resolvedBy;
        $this->resolvedAt = new \DateTimeImmutable();
        $this->setApprovedTokens(null);
        $this->setAdminNotes($adminNotes ?? 'Rechazada por super admin');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'tenant_id' => $this->tenant->getId()->toRfc4122(),
            'requested_by_id' => $this->requestedBy?->getId()->toRfc4122(),
            'requested_amount_eur' => $this->requestedAmountEur,
            'approved_tokens' => $this->approvedTokens,
            'message' => $this->message,
            'status' => $this->status,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'resolved_at' => $this->resolvedAt?->format(\DateTimeInterface::ATOM),
            'resolved_by_id' => $this->resolvedBy?->getId()->toRfc4122(),
            'admin_notes' => $this->adminNotes,
        ];
    }
}
