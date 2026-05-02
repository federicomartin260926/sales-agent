<?php

namespace App\Repository;

use App\Entity\Playbook;
use App\Entity\Tenant;
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

    public function findActiveGeneralByTenant(Tenant $tenant): ?Playbook
    {
        $playbooks = $this->createQueryBuilder('p')
            ->leftJoin('p.product', 'pr')
            ->addSelect('pr')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.isActive = true')
            ->andWhere('p.product IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        if (count($playbooks) !== 1) {
            return null;
        }

        $playbook = $playbooks[0];

        return $playbook instanceof Playbook ? $playbook : null;
    }
}
