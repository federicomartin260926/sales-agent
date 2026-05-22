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

    /**
     * @return Product[]
     */
    public function findByTenantOrdered(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function findActiveByTenantOrdered(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function searchActiveByTenantAndText(Tenant $tenant, string $query, int $limit = 20): array
    {
        $tokens = $this->searchTokens($query);
        if ($tokens === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->join('p.tenant', 't')
            ->addSelect('t')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)));

        $orX = $qb->expr()->orX();
        foreach ($tokens as $index => $token) {
            $parameter = sprintf('token_%d', $index);
            $term = '%'.mb_strtolower($token).'%';
            $orX->add($qb->expr()->orX(
                $qb->expr()->like('LOWER(p.name)', ':' . $parameter),
                $qb->expr()->like('LOWER(COALESCE(p.slug, \'\'))', ':' . $parameter),
                $qb->expr()->like('LOWER(COALESCE(p.description, \'\'))', ':' . $parameter),
                $qb->expr()->like('LOWER(COALESCE(p.valueProposition, \'\'))', ':' . $parameter),
                $qb->expr()->like('LOWER(COALESCE(p.externalReference, \'\'))', ':' . $parameter),
            ));
            $qb->setParameter($parameter, $term);
        }

        $qb->andWhere($orX);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<string>
     */
    private function searchTokens(string $query): array
    {
        $normalized = trim(mb_strtolower($query));
        if ($normalized === '') {
            return [];
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $stopwords = [
            'de', 'del', 'la', 'el', 'los', 'las', 'un', 'una', 'unos', 'unas', 'para', 'por', 'con', 'sin',
            'sobre', 'y', 'o', 'u', 'a', 'al', 'en', 'que', 'quiero', 'necesito', 'busco', 'informacion', 'información',
            'info', 'mostrar', 'ver', 'me', 'mi', 'mis', 'tener', 'tengo', 'hay', 'servicio', 'servicios', 'producto',
            'productos', 'tratamiento', 'tratamientos', 'consulta', 'consultar', 'precio', 'precios', 'costo', 'coste',
        ];

        $filtered = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || mb_strlen($token) < 3) {
                continue;
            }

            if (in_array($token, $stopwords, true)) {
                continue;
            }

            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
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
