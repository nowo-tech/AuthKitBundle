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

    /**
     * The account linked to this provider subject, scoped to `$userClass` when given (SEC-004 H2: a subject linked to
     * a user of one profile's class never resolves for a profile with another user class). The provider and subject
     * must match exactly (a case- or accent-insensitive collation must not match another subject).
     */
    public function findOneByProviderSubject(string $provider, string $providerUserId, ?string $userClass = null): ?SocialLoginAccount
    {
        $builder = $this->createQueryBuilder('a')
            ->andWhere('a.provider = :provider')
            ->andWhere('a.providerUserId = :providerUserId')
            ->setParameter('provider', $provider)
            ->setParameter('providerUserId', $providerUserId)
            ->setMaxResults(10);

        if ($userClass !== null) {
            $builder->andWhere('a.userClass = :userClass')->setParameter('userClass', $userClass);
        }

        /** @var list<SocialLoginAccount> $accounts */
        $accounts = $builder->getQuery()->setHint(Query::HINT_REFRESH, true)->getResult();

        foreach ($accounts as $account) {
            if ($account->getProvider() === $provider
                && $account->getProviderUserId() === $providerUserId
                && ($userClass === null || $account->getUserClass() === $userClass)
            ) {
                return $account;
            }
        }

        return null;
    }
}
