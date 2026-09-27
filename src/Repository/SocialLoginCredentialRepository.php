<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use SortDirection;

/**
 * @extends ServiceEntityRepository<SocialLoginCredential>
 */
class SocialLoginCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLoginCredential::class);
    }

    public function findOneByProvider(string $provider): ?SocialLoginCredential
    {
        /** @var SocialLoginCredential|null $credential */
        $credential = $this->createQueryBuilder('c')
            ->andWhere('c.provider = :provider')
            ->setParameter('provider', $provider)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $credential;
    }

    /**
     * @return list<SocialLoginCredential>
     */
    public function findEnabledOrdered(): array
    {
        /** @var list<SocialLoginCredential> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.enabled = true')
            ->orderBy('c.label', SortDirection::Ascending)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $rows;
    }
}
