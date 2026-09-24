<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;

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

    /**
     * Loads the challenge from the database, overwriting any copy already held in the identity map.
     */
    public function findFresh(string $id): ?QrLoginChallenge
    {
        $challenge = $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $challenge instanceof QrLoginChallenge ? $challenge : null;
    }

    /**
     * Atomically moves the stored status from $from to $to.
     *
     * @return bool false when the row is no longer in state $from (another request won the race)
     */
    public function transitionStatus(QrLoginChallenge $challenge, QrLoginChallengeStatus $from, QrLoginChallengeStatus $to): bool
    {
        $affected = $this->getEntityManager()
            ->createQuery('UPDATE ' . QrLoginChallenge::class . ' c SET c.status = :to, c.updatedAt = :now WHERE c.id = :id AND c.status = :from')
            ->setParameter('to', $to->value)
            ->setParameter('now', new DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $challenge->getId())
            ->setParameter('from', $from->value)
            ->execute();

        return $affected === 1;
    }
}
