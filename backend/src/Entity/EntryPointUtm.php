<?php

namespace App\Entity;

use App\Repository\EntryPointUtmRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: EntryPointUtmRepository::class)]
#[ORM\Table(
    name: 'entry_point_utms',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_entry_point_utms_ref', columns: ['ref']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_entry_point_utms_entry_point', columns: ['entry_point_id']),
        new ORM\Index(name: 'idx_entry_point_utms_status', columns: ['status']),
    ]
)]
class EntryPointUtm
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_MATCHED = 'matched';
    public const STATUS_EXPIRED = 'expired';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: EntryPoint::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private EntryPoint $entryPoint;

    #[ORM\Column(length: 32)]
    private string $ref;

    #[ORM\Column(name: 'utm_source', length: 255, nullable: true)]
    private ?string $utmSource = null;

    #[ORM\Column(name: 'utm_medium', length: 255, nullable: true)]
    private ?string $utmMedium = null;

    #[ORM\Column(name: 'utm_campaign', length: 255, nullable: true)]
    private ?string $utmCampaign = null;

    #[ORM\Column(name: 'utm_term', length: 255, nullable: true)]
    private ?string $utmTerm = null;

    #[ORM\Column(name: 'utm_content', length: 255, nullable: true)]
    private ?string $utmContent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gclid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fbclid = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $matchedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    public function __construct(EntryPoint $entryPoint, string $ref)
    {
        $this->id = Uuid::v7();
        $this->entryPoint = $entryPoint;
        $this->ref = $ref;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->add(new \DateInterval('P7D'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEntryPoint(): EntryPoint
    {
        return $this->entryPoint;
    }

    public function setEntryPoint(EntryPoint $entryPoint): void
    {
        $this->entryPoint = $entryPoint;
    }

    public function getRef(): string
    {
        return $this->ref;
    }

    public function setRef(string $ref): void
    {
        $this->ref = $ref;
    }

    public function getUtmSource(): ?string
    {
        return $this->utmSource;
    }

    public function setUtmSource(?string $utmSource): void
    {
        $this->utmSource = $utmSource;
    }

    public function getUtmMedium(): ?string
    {
        return $this->utmMedium;
    }

    public function setUtmMedium(?string $utmMedium): void
    {
        $this->utmMedium = $utmMedium;
    }

    public function getUtmCampaign(): ?string
    {
        return $this->utmCampaign;
    }

    public function setUtmCampaign(?string $utmCampaign): void
    {
        $this->utmCampaign = $utmCampaign;
    }

    public function getUtmTerm(): ?string
    {
        return $this->utmTerm;
    }

    public function setUtmTerm(?string $utmTerm): void
    {
        $this->utmTerm = $utmTerm;
    }

    public function getUtmContent(): ?string
    {
        return $this->utmContent;
    }

    public function setUtmContent(?string $utmContent): void
    {
        $this->utmContent = $utmContent;
    }

    public function getGclid(): ?string
    {
        return $this->gclid;
    }

    public function setGclid(?string $gclid): void
    {
        $this->gclid = $gclid;
    }

    public function getFbclid(): ?string
    {
        return $this->fbclid;
    }

    public function setFbclid(?string $fbclid): void
    {
        $this->fbclid = $fbclid;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function markMatched(): void
    {
        $this->status = self::STATUS_MATCHED;
        $this->matchedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMatchedAt(): ?\DateTimeImmutable
    {
        return $this->matchedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toRfc4122(),
            'entryPointId' => $this->entryPoint->getId()->toRfc4122(),
            'entryPointCode' => $this->entryPoint->getCode(),
            'ref' => $this->ref,
            'utmSource' => $this->utmSource,
            'utmMedium' => $this->utmMedium,
            'utmCampaign' => $this->utmCampaign,
            'utmTerm' => $this->utmTerm,
            'utmContent' => $this->utmContent,
            'gclid' => $this->gclid,
            'fbclid' => $this->fbclid,
            'status' => $this->status,
            'createdAt' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'matchedAt' => $this->matchedAt?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $this->expiresAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
