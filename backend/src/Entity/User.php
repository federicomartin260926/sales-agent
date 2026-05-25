<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $name = null;

    /**
     * Logical roles: agent, manager, admin.
     *
     * @var string[]
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    /**
     * @var Collection<int, TenantMembership>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: TenantMembership::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $tenantMemberships;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email = '', array $roles = ['agent'], ?string $name = null)
    {
        $this->id = Uuid::v7();
        $this->email = strtolower(trim($email));
        $this->roles = $this->normalizeRoles($roles);
        $this->name = $this->normalizeName($name);
        $this->tenantMemberships = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = strtolower(trim($email));
    }

    public function getName(): string
    {
        return $this->name ?? '';
    }

    public function setName(?string $name): void
    {
        $this->name = $this->normalizeName($name);
    }

    public function getRoles(): array
    {
        $roles = array_values(array_unique(array_map(
            static fn (string $role): string => 'ROLE_'.strtoupper($role),
            $this->roles
        )));

        if (in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_MANAGER', $roles, true)) {
            $roles[] = 'ROLE_MANAGER';
        }

        if (in_array('ROLE_SUPER_ADMIN', $roles, true) && !in_array('ROLE_ADMIN', $roles, true)) {
            $roles[] = 'ROLE_ADMIN';
        }

        return $roles;
    }

    /**
     * @param string[] $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = $this->normalizeRoles($roles);
    }

    public function addRole(string $role): void
    {
        $normalized = $this->normalizeRoles([$role]);
        $role = $normalized[0] ?? '';
        if ($role === '') {
            return;
        }

        if (!in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
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

    /**
     * @return Collection<int, TenantMembership>
     */
    public function getTenantMemberships(): Collection
    {
        return $this->tenantMemberships;
    }

    /**
     * @return list<Tenant>
     */
    public function getAccessibleTenants(): array
    {
        $tenants = [];
        foreach ($this->tenantMemberships as $membership) {
            if (!$membership instanceof TenantMembership || !$membership->isActive()) {
                continue;
            }

            $tenants[] = $membership->getTenant();
        }

        return $tenants;
    }

    public function hasTenantAccess(Tenant $tenant): bool
    {
        foreach ($this->tenantMemberships as $membership) {
            if (!$membership instanceof TenantMembership || !$membership->isActive()) {
                continue;
            }

            if ($membership->getTenant()->getId()->toRfc4122() === $tenant->getId()->toRfc4122()) {
                return true;
            }
        }

        return false;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function eraseCredentials(): void
    {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'email' => $this->email,
            'name' => $this->getName(),
            'roles' => $this->roles,
            'isActive' => $this->isActive,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param string[] $roles
     *
     * @return string[]
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = array_map(static function (string $role): string {
            $role = strtolower(trim($role));

            return match ($role) {
                'role_agent', 'agent' => 'agent',
                'role_manager', 'manager' => 'manager',
                'role_admin', 'admin' => 'admin',
                'role_super_admin', 'super_admin', 'super-admin' => 'super_admin',
                default => $role,
            };
        }, $roles);

        $normalized = array_values(array_filter($normalized, static fn (string $role): bool => $role !== ''));

        return $normalized !== [] ? $normalized : ['agent'];
    }

    private function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $name = trim($name);

        return $name !== '' ? $name : null;
    }
}
