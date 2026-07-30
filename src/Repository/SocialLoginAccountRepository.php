<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
        return $this->findOneBy([
            'provider'       => $provider,
            'providerUserId' => $providerUserId,
        ]);
    }
}
