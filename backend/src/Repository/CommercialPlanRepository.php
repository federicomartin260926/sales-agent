<?php

namespace App\Repository;

use App\Entity\CommercialPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommercialPlan>
 */
class CommercialPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommercialPlan::class);
    }

    public function save(CommercialPlan $plan, bool $flush = true): void
    {
        $this->getEntityManager()->persist($plan);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return CommercialPlan[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.displayOrder', 'ASC')
            ->addOrderBy('p.active', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CommercialPlan[]
     */
    public function findActiveOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.displayOrder', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByCode(string $code): ?CommercialPlan
    {
        $plan = $this->findOneBy(['code' => strtolower(trim($code))]);

        return $plan instanceof CommercialPlan ? $plan : null;
    }
}
