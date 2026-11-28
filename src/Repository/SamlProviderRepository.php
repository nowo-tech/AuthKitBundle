<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SamlProvider;
use SortDirection;

use function preg_match;

/**
 * SAML provider rows (2.10.0). Reads refresh the row (a long-running worker never answers from a stale identity map).
 *
 * @extends ServiceEntityRepository<SamlProvider>
 */
class SamlProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SamlProvider::class);
    }

    /**
     * The row of this provider key, or null (also for a key that cannot be one: no query).
     */
    public function findOneByProvider(string $provider): ?SamlProvider
    {
        if (preg_match(SamlProvider::PROVIDER_PATTERN, $provider) !== 1) {
            return null;
        }
        /** @var SamlProvider|null $row */
        $row = $this->createQueryBuilder('p')
            ->andWhere('p.provider = :provider')
            ->setParameter('provider', $provider)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $row;
    }

    /**
     * The rows of a host scope (tenant), ordered by label.
     *
     * @return list<SamlProvider>
     */
    public function findByScope(string $scope): array
    {
        /** @var list<SamlProvider> $rows */
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('p.label', SortDirection::Ascending)
            ->addOrderBy('p.provider', SortDirection::Ascending)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }
}
