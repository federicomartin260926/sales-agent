<?php

namespace App\Repository;

use App\Entity\EntryPoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryPoint>
 */
class EntryPointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryPoint::class);
    }

    public function save(EntryPoint $entryPoint, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entryPoint);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EntryPoint $entryPoint, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entryPoint);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return EntryPoint[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('ep')
            ->leftJoin('ep.product', 'p')
            ->addSelect('p')
            ->leftJoin('p.tenant', 't')
            ->addSelect('t')
            ->leftJoin('ep.playbook', 'pb')
            ->addSelect('pb')
            ->orderBy('ep.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveByCode(string $code): ?EntryPoint
    {
        return $this->createQueryBuilder('ep')
            ->leftJoin('ep.product', 'p')
            ->addSelect('p')
            ->leftJoin('p.tenant', 't')
            ->addSelect('t')
            ->leftJoin('ep.playbook', 'pb')
            ->addSelect('pb')
            ->andWhere('ep.code = :code')
            ->andWhere('ep.isActive = true')
            ->setParameter('code', $code)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
