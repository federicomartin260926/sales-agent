<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantAiTopUpRequest>
 */
class TenantAiTopUpRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantAiTopUpRequest::class);
    }

    public function save(TenantAiTopUpRequest $request, bool $flush = true): void
    {
        $this->getEntityManager()->persist($request);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return TenantAiTopUpRequest[]
     */
    public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
