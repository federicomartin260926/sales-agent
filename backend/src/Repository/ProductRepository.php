<?php

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function save(Product $product, bool $flush = true): void
    {
        $this->getEntityManager()->persist($product);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Product $product, bool $flush = true): void
    {
        $this->getEntityManager()->remove($product);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @return Product[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndSlug(Tenant $tenant, string $slug): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.slug = :slug')
            ->setParameter('tenant', $tenant)
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByExternalIdentity(Tenant $tenant, string $source, string $reference): ?Product
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.externalSource = :source')
            ->andWhere('p.externalReference = :reference')
            ->setParameter('tenant', $tenant)
            ->setParameter('source', $source)
            ->setParameter('reference', $reference)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
