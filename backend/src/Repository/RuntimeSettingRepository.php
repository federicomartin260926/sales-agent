<?php

namespace App\Repository;

use App\Entity\RuntimeSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RuntimeSetting>
 */
class RuntimeSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RuntimeSetting::class);
    }

    public function save(RuntimeSetting $setting, bool $flush = true): void
    {
        $this->getEntityManager()->persist($setting);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(RuntimeSetting $setting, bool $flush = true): void
    {
        $this->getEntityManager()->remove($setting);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return RuntimeSetting[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.settingKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByKey(string $settingKey): ?RuntimeSetting
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.settingKey = :settingKey')
            ->setParameter('settingKey', $settingKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
