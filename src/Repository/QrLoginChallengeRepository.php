<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;

/**
 * @extends EntityRepository<QrLoginChallenge>
 */
class QrLoginChallengeRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(QrLoginChallenge::class));
    }

    public function save(QrLoginChallenge $challenge): void
    {
        $this->getEntityManager()->persist($challenge);
        $this->getEntityManager()->flush();
    }
}
