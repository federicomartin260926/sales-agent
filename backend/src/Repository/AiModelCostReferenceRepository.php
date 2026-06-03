<?php

namespace App\Repository;

use App\Entity\AiModelCostReference;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiModelCostReference>
 */
class AiModelCostReferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiModelCostReference::class);
    }

    public function save(AiModelCostReference $reference, bool $flush = true): void
    {
        $this->getEntityManager()->persist($reference);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return AiModelCostReference[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.usageType', 'ASC')
            ->addOrderBy('r.active', 'DESC')
            ->addOrderBy('r.model', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AiModelCostReference[]
     */
    public function findActiveByUsageType(string $usageType): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.usageType = :usageType')
            ->andWhere('r.active = true')
            ->setParameter('usageType', strtolower(trim($usageType)))
            ->orderBy('r.model', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByUsageTypeAndModel(string $usageType, string $model): ?AiModelCostReference
    {
        $reference = $this->createQueryBuilder('r')
            ->andWhere('r.usageType = :usageType')
            ->andWhere('LOWER(r.model) = :model')
            ->setParameter('usageType', strtolower(trim($usageType)))
            ->setParameter('model', strtolower(trim($model)))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $reference instanceof AiModelCostReference ? $reference : null;
    }
}
