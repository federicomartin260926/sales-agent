<?php

namespace App\Repository;

use App\Entity\TenantAiUsagePolicy;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantAiUsagePolicy>
 */
class TenantAiUsagePolicyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantAiUsagePolicy::class);
    }

    public function save(TenantAiUsagePolicy $policy, bool $flush = true): void
    {
        $this->getEntityManager()->persist($policy);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByTenant(Tenant $tenant): ?TenantAiUsagePolicy
    {
        return $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOrCreateByTenant(Tenant $tenant, bool $flush = true): TenantAiUsagePolicy
    {
        $policy = $this->findOneByTenant($tenant);
        if ($policy instanceof TenantAiUsagePolicy) {
            return $policy;
        }

        $policy = new TenantAiUsagePolicy($tenant);
        $this->save($policy, $flush);

        return $policy;
    }
}
