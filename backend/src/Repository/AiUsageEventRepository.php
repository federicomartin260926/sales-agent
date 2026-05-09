<?php

namespace App\Repository;

use App\Entity\AiUsageEvent;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiUsageEvent>
 */
class AiUsageEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiUsageEvent::class);
    }

    public function save(AiUsageEvent $event, bool $flush = true): void
    {
        $this->getEntityManager()->persist($event);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return array{estimated_cost_eur: float, input_tokens: int, output_tokens: int, cached_tokens: int, total_tokens: int}
     */
    public function summarizeSince(Tenant $tenant, \DateTimeImmutable $since): array
    {
        $row = $this->createQueryBuilder('e')
            ->select('COALESCE(SUM(COALESCE(e.estimatedCost, 0)), 0) AS estimated_cost_eur')
            ->addSelect('COALESCE(SUM(COALESCE(e.inputTokens, 0)), 0) AS input_tokens')
            ->addSelect('COALESCE(SUM(COALESCE(e.outputTokens, 0)), 0) AS output_tokens')
            ->addSelect('COALESCE(SUM(COALESCE(e.cachedTokens, 0)), 0) AS cached_tokens')
            ->addSelect('COALESCE(SUM(COALESCE(e.totalTokens, 0)), 0) AS total_tokens')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.createdAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'estimated_cost_eur' => (float) ($row['estimated_cost_eur'] ?? 0),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'cached_tokens' => (int) ($row['cached_tokens'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
        ];
    }

    /**
     * @return AiUsageEvent[]
     */
    public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
