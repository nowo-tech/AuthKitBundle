<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use SortDirection;

use function count;

/**
 * @extends ServiceEntityRepository<SocialLoginAccount>
 */
class SocialLoginAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SocialLoginAccount::class);
    }

    /** Collation-equal rows read per query while looking for the exact subject. */
    public const CANDIDATE_BATCH = 10;

    /**
     * The account linked to this provider subject, scoped to `$userClass` when given (SEC-004 H2: a subject linked to
     * a user of one profile's class never resolves for a profile with another user class). The provider and subject
     * must match exactly (a case- or accent-insensitive collation must not match another subject).
     *
     * REQ-SEC-006 item 6 (1.46.1): the exact match happens before the page limit. The candidates are read in batches of
     * {@see self::CANDIDATE_BATCH} by ascending id until the exact row is found or none is left, so look-alike rows
     * (`AbC` for `abc`, `abc ` on PAD SPACE) can never hide the subject's own link.
     */
    public function findOneByProviderSubject(string $provider, string $providerUserId, ?string $userClass = null): ?SocialLoginAccount
    {
        $afterId = 0;
        do {
            $builder = $this->createQueryBuilder('a')
                ->andWhere('a.provider = :provider')
                ->andWhere('a.providerUserId = :providerUserId')
                ->andWhere('a.id > :afterId')
                ->setParameter('provider', $provider)
                ->setParameter('providerUserId', $providerUserId)
                ->setParameter('afterId', $afterId)
                ->orderBy('a.id', SortDirection::Ascending)
                ->setMaxResults(self::CANDIDATE_BATCH);

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
                $afterId = (int) $account->getId();
            }
        } while (count($accounts) === self::CANDIDATE_BATCH);

        return null;
    }
}
