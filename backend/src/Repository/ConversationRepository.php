<?php

namespace App\Repository;

use App\Entity\Conversation;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Conversation>
 */
class ConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Conversation::class);
    }

    public function save(Conversation $conversation, bool $flush = true): void
    {
        $this->getEntityManager()->persist($conversation);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Conversation $conversation, bool $flush = true): void
    {
        $this->getEntityManager()->remove($conversation);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findActiveByTenantPhone(Tenant $tenant, string $customerPhone): ?Conversation
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.tenant', 't')
            ->addSelect('t')
            ->leftJoin('c.entryPoint', 'ep')
            ->addSelect('ep')
            ->leftJoin('c.product', 'p')
            ->addSelect('p')
            ->leftJoin('c.entryPointUtm', 'eu')
            ->addSelect('eu')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.customerPhone = :customerPhone')
            ->andWhere('c.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('customerPhone', $customerPhone)
            ->setParameter('status', 'active');

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * @return list<Conversation>
     */
    public function findByTenantAndExternalConversationId(Tenant $tenant, string $externalConversationId, ?string $customerPhone = null, int $limit = 2): array
    {
        $externalConversationId = trim($externalConversationId);
        if ($externalConversationId === '') {
            return [];
        }

        $qb = $this->createQueryBuilder('c')
            ->join('c.tenant', 't')
            ->addSelect('t')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.externalConversationId = :externalConversationId')
            ->setParameter('tenant', $tenant)
            ->setParameter('externalConversationId', $externalConversationId)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults(max(1, min(2, $limit)));

        if (is_string($customerPhone) && trim($customerPhone) !== '') {
            $qb->andWhere('c.customerPhone = :customerPhone')
                ->setParameter('customerPhone', trim($customerPhone));
        }

        $results = $qb->getQuery()->getResult();

        return array_values(array_filter($results, static fn ($conversation): bool => $conversation instanceof Conversation));
    }
}
