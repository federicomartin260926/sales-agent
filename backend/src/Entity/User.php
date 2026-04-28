<?php

namespace App\Entity;

use App\Repository\UserRepository;
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

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email = '', array $roles = ['agent'])
    {
        $this->id = Uuid::v7();
        $this->email = strtolower(trim($email));
        $this->roles = $this->normalizeRoles($roles);
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

    public function getRoles(): array
    {
        $roles = array_values(array_unique(array_map(
            static fn (string $role): string => 'ROLE_'.strtoupper($role),
            $this->roles
        )));

        if (in_array('ROLE_ADMIN', $roles, true) && !in_array('ROLE_MANAGER', $roles, true)) {
            $roles[] = 'ROLE_MANAGER';
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
                default => $role,
            };
        }, $roles);

        $normalized = array_values(array_filter($normalized, static fn (string $role): bool => $role !== ''));

        return $normalized !== [] ? $normalized : ['agent'];
    }
}
