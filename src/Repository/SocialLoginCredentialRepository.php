<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\SocialLoginCredential;

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
        return $this->findOneBy(['provider' => $provider]);
    }

    /**
     * @return list<SocialLoginCredential>
     */
    public function findEnabledOrdered(): array
    {
        /** @var list<SocialLoginCredential> $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.enabled = true')
            ->orderBy('c.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
