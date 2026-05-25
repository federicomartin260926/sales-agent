<?php

namespace App\Entity;

use App\Repository\TenantMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantMembershipRepository::class)]
#[ORM\Table(
    name: 'tenant_memberships',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_tenant_membership_user_tenant', columns: ['user_id', 'tenant_id']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_tenant_membership_user', columns: ['user_id']),
        new ORM\Index(name: 'idx_tenant_membership_tenant', columns: ['tenant_id']),
        new ORM\Index(name: 'idx_tenant_membership_active', columns: ['is_active']),
    ]
)]
class TenantMembership
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'tenantMemberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 50)]
    private string $role = 'manager';

    #[ORM\Column(name: 'is_active', type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Tenant $tenant, string $role = 'manager')
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->tenant = $tenant;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setRole($role);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): void
    {
        $role = strtolower(trim($role));
        $this->role = match ($role) {
            'manager', 'editor', 'viewer', 'agent' => $role,
            default => 'manager',
        };
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function canManageTenant(): bool
    {
        return in_array($this->role, ['manager', 'editor'], true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'user_id' => $this->user->getId()->toRfc4122(),
            'tenant_id' => $this->tenant->getId()->toRfc4122(),
            'role' => $this->role,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
