<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantAiTopUpRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantAiTopUpRequest>
 */
class TenantAiTopUpRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantAiTopUpRequest::class);
    }

    public function save(TenantAiTopUpRequest $request, bool $flush = true): void
    {
        $this->getEntityManager()->persist($request);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return TenantAiTopUpRequest[]
     */
    public function findRecentByTenant(Tenant $tenant, int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TenantAiTopUpRequest[]
     */
    public function findApprovedByTenantAndPeriod(Tenant $tenant, string $periodKey): array
    {
        $requests = $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', TenantAiTopUpRequest::STATUS_APPROVED)
            ->orderBy('r.resolvedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $requests,
            static function (TenantAiTopUpRequest $request) use ($periodKey): bool {
                $approvedPeriodKey = $request->getApprovedPeriodKey();
                if ($approvedPeriodKey !== null && $approvedPeriodKey !== '') {
                    return $approvedPeriodKey === $periodKey;
                }

                $resolvedAt = $request->getResolvedAt();
                return $resolvedAt instanceof \DateTimeImmutable && $resolvedAt->format('Y-m') === $periodKey;
            }
        ));
    }

    public function sumApprovedTokensByTenantAndPeriod(Tenant $tenant, string $periodKey): int
    {
        $total = 0;
        foreach ($this->findApprovedByTenantAndPeriod($tenant, $periodKey) as $request) {
            $approvedTokens = $request->getApprovedTokens();
            if ($approvedTokens === null) {
                $approvedTokens = max(0, (int) round($request->getRequestedAmountEur()));
            }

            $total += max(0, $approvedTokens);
        }

        return $total;
    }

    /**
     * @return TenantAiTopUpRequest[]
     */
    public function findLegacyApprovedWithoutPeriodByTenant(Tenant $tenant): array
    {
        $requests = $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.status = :status')
            ->andWhere('r.approvedPeriodKey IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', TenantAiTopUpRequest::STATUS_APPROVED)
            ->orderBy('r.resolvedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $requests,
            static fn (TenantAiTopUpRequest $request): bool => $request->getApprovedTokens() !== null || (int) round($request->getRequestedAmountEur()) > 0
        ));
    }
}
