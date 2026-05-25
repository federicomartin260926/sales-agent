<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantMembership>
 */
class TenantMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantMembership::class);
    }

    public function save(TenantMembership $membership, bool $flush = true): void
    {
        $this->getEntityManager()->persist($membership);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return TenantMembership[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.tenant', 't')
            ->andWhere('m.user = :user')
            ->andWhere('m.isActive = true')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Tenant[]
     */
    public function findAccessibleTenantsByUser(User $user): array
    {
        return array_values(array_map(
            static fn (TenantMembership $membership): Tenant => $membership->getTenant(),
            $this->findActiveByUser($user)
        ));
    }

    public function findActiveByUserAndTenant(User $user, Tenant $tenant): ?TenantMembership
    {
        $membership = $this->createQueryBuilder('m')
            ->innerJoin('m.tenant', 't')
            ->andWhere('m.user = :user')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.isActive = true')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $membership instanceof TenantMembership ? $membership : null;
    }

    public function hasActiveMembership(User $user, Tenant $tenant): bool
    {
        return $this->findActiveByUserAndTenant($user, $tenant) instanceof TenantMembership;
    }
}
