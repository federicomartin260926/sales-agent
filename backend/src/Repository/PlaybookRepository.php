<?php

namespace App\Repository;

use App\Entity\Playbook;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Playbook>
 */
class PlaybookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playbook::class);
    }

    public function save(Playbook $playbook, bool $flush = true): void
    {
        $this->getEntityManager()->persist($playbook);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Playbook $playbook, bool $flush = true): void
    {
        $this->getEntityManager()->remove($playbook);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Playbook[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->leftJoin('p.product', 'pr')
            ->addSelect('pr')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
