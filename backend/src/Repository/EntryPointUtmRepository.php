<?php

namespace App\Repository;

use App\Entity\EntryPointUtm;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EntryPointUtm>
 */
class EntryPointUtmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EntryPointUtm::class);
    }

    public function save(EntryPointUtm $entryPointUtm, bool $flush = true): void
    {
        $this->getEntityManager()->persist($entryPointUtm);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(EntryPointUtm $entryPointUtm, bool $flush = true): void
    {
        $this->getEntityManager()->remove($entryPointUtm);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByRef(string $ref): ?EntryPointUtm
    {
        return $this->createQueryBuilder('u')
            ->join('u.entryPoint', 'ep')
            ->addSelect('ep')
            ->join('ep.product', 'p')
            ->addSelect('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->leftJoin('ep.playbook', 'pb')
            ->addSelect('pb')
            ->andWhere('u.ref = :ref')
            ->setParameter('ref', trim($ref))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
