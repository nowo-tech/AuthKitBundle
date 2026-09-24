<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;

/**
 * @extends ServiceEntityRepository<SocialLoginAccount>
 */
class SocialLoginAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLoginAccount::class);
    }

    public function findOneByProviderSubject(string $provider, string $providerUserId): ?SocialLoginAccount
    {
        /** @var SocialLoginAccount|null $account */
        $account = $this->createQueryBuilder('a')
            ->andWhere('a.provider = :provider')
            ->andWhere('a.providerUserId = :providerUserId')
            ->setParameter('provider', $provider)
            ->setParameter('providerUserId', $providerUserId)
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $account;
    }
}
