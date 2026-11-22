<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntities;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotFoundException;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotManagedException;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Psr\Clock\ClockInterface;

/**
 * @extends EntityRepository<QrLoginChallenge>
 */
class QrLoginChallengeRepository extends EntityRepository
{
    public function __construct(
        EntityManagerInterface $em,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct($em, $em->getClassMetadata(QrLoginChallenge::class));
    }

    /**
     * 1.55.0 (REQ-ARCH-001 rule 6): the instance this repository's manager holds for the challenge, loaded again by id
     * when the caller's instance comes from another manager (a challenge the application loaded through its own manager). The same object otherwise, and for a new challenge. The caller's
     * instance and manager are left untouched.
     *
     * @throws AuthKitEntityNotFoundException When the challenge no longer exists
     */
    public function adopt(QrLoginChallenge $challenge): QrLoginChallenge
    {
        return AuthKitEntities::adopt($this->getEntityManager(), $challenge);
    }

    /**
     * 1.50.0 (Beacon spec 239): a unit of work of AuthKit's transaction runner on this repository's manager.
     *
     * 1.55.0: an existing challenge this repository's manager does not hold (loaded through another manager) is refused
     * before anything is written, never inserted a second time; re-resolve it with {@see self::adopt()} first.
     *
     * @throws AuthKitEntityNotManagedException When the challenge exists and this manager does not hold the instance
     */
    public function save(QrLoginChallenge $challenge): void
    {
        $manager = $this->getEntityManager();
        AuthKitEntities::assertSavable($manager, $challenge);
        $this->transactional(static fn () => $manager->persist($challenge));
    }

    /**
     * 1.50.0: stores a new challenge; false when its public code is taken (another challenge has it), so the caller
     * draws another one. Written beside the unit of work in a savepoint: a taken code never closes the entity manager.
     */
    public function insertNew(QrLoginChallenge $challenge): bool
    {
        $manager = $this->getEntityManager();

        return $this->transactional(static fn (): bool => !AuthKitRows::insert($manager, $challenge) instanceof UniqueConstraintViolationException);
    }

    /**
     * 1.50.0: runs a unit of work on AuthKit's transaction runner (this repository's manager is flushed inside it).
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        return (new AuthKitTransactionRunner($this->getEntityManager()))->run($work);
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
        $manager = $this->getEntityManager();
        $query   = $manager
            ->createQuery('UPDATE ' . QrLoginChallenge::class . ' c SET c.status = :to, c.updatedAt = :now WHERE c.id = :id AND c.status = :from')
            ->setParameter('to', $to->value)
            ->setParameter('now', $this->clock?->now() ?? new DateTimeImmutable(), Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $challenge->getId())
            ->setParameter('from', $from->value);
        // 1.46.0: a savepoint inside an open transaction with the private entity manager (exactly as before otherwise).
        $affected = AuthKitSavepoint::write($manager->getConnection(), static fn (): mixed => $query->execute(), $manager);

        return $affected === 1;
    }
}
