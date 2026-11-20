<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
use Nowo\AuthKitBundle\Entity\QrLoginChallenge;
use Nowo\AuthKitBundle\Enum\QrLoginChallengeStatus;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Psr\Clock\ClockInterface;

/**
 * @extends EntityRepository<QrLoginChallenge>
 */
class QrLoginChallengeRepository extends EntityRepository
{
    private ?HostFlushReporter $hostFlush = null;

    public function __construct(
        EntityManagerInterface $em,
        private readonly ?ClockInterface $clock = null,
    ) {
        parent::__construct($em, $em->getClassMetadata(QrLoginChallenge::class));
    }

    /**
     * 1.54.0 (REQ-ARCH-001 rule 6 deprecation layer): set by the container only without
     * `doctrine.private_entity_manager: true`, to report the application's pending changes a host-mode flush writes. A
     * repository Doctrine builds itself ({@see EntityManagerInterface::getRepository()}) has none.
     *
     * @internal
     */
    public function setHostFlushReporter(?HostFlushReporter $hostFlush): void
    {
        $this->hostFlush = $hostFlush; // @igor-ignore - set once by the container when the service is built
    }

    /**
     * 1.50.0 (Beacon spec 239): a unit of work of AuthKit's transaction runner on this repository's manager.
     */
    public function save(QrLoginChallenge $challenge): void
    {
        $manager = $this->getEntityManager();
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
        return (new AuthKitTransactionRunner($this->getEntityManager(), hostFlush: $this->hostFlush))->run($work);
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
