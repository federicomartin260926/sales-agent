<?php

namespace App\Repository;

use App\Entity\ExternalContactContextCache;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalContactContextCache>
 */
class ExternalContactContextCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalContactContextCache::class);
    }

    public function save(ExternalContactContextCache $cache, bool $flush = true): void
    {
        $this->getEntityManager()->persist($cache);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ExternalContactContextCache $cache, bool $flush = true): void
    {
        $this->getEntityManager()->remove($cache);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findLatestByTenantContactKeyProvider(string $tenantId, string $contactKey, string $provider = 'contact_context'): ?ExternalContactContextCache
    {
        $tenantId = trim($tenantId);
        $contactKey = trim($contactKey);
        $provider = trim($provider);

        if ($tenantId === '' || $contactKey === '' || $provider === '') {
            return null;
        }

        $result = $this->createQueryBuilder('c')
            ->andWhere('c.tenantId = :tenantId')
            ->andWhere('c.contactKey = :contactKey')
            ->andWhere('c.provider = :provider')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('contactKey', $contactKey)
            ->setParameter('provider', $provider)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result instanceof ExternalContactContextCache ? $result : null;
    }
}
