<?php

namespace App\Repository;

use App\Entity\ExternalTool;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalTool>
 */
class ExternalToolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalTool::class);
    }

    public function save(ExternalTool $externalTool, bool $flush = true): void
    {
        $this->getEntityManager()->persist($externalTool);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ExternalTool $externalTool, bool $flush = true): void
    {
        $this->getEntityManager()->remove($externalTool);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return ExternalTool[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.tenant', 'tenant')
            ->addSelect('tenant')
            ->orderBy('tenant.name', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return ExternalTool[]
     */
    public function findByTenantOrdered(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->join('t.tenant', 'tenant')
            ->addSelect('tenant')
            ->andWhere('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByTenantAndType(Tenant $tenant, string $type): ?ExternalTool
    {
        return $this->createQueryBuilder('t')
            ->join('t.tenant', 'tenant')
            ->addSelect('tenant')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.type = :type')
            ->andWhere('t.isActive = true')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', trim($type))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
