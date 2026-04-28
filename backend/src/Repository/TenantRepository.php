<?php

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
final class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function save(Tenant $tenant, bool $flush = true): void
    {
        $this->getEntityManager()->persist($tenant);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Tenant $tenant, bool $flush = true): void
    {
        $this->getEntityManager()->remove($tenant);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Tenant[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
