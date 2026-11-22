<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntities;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotFoundException;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityNotManagedException;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use OverflowException;
use SortDirection;

use function array_map;
use function count;
use function is_bool;
use function is_scalar;
use function json_encode;
use function max;
use function mb_substr;
use function min;
use function sprintf;
use function str_replace;
use function trim;

/**
 * Invitations: the open one of an e-mail, the one behind a link, the administration list and the purge. Every list is
 * bounded ({@see self::MAX_PAGE_SIZE}, {@see self::PURGE_BATCH}).
 *
 * @extends EntityRepository<Invitation>
 */
class InvitationRepository extends EntityRepository
{
    /** Largest page of the administration list. */
    public const MAX_PAGE_SIZE = 100;

    /** Open invitations of one e-mail scanned for `invitations.merge_key` (more: refused, SEC-004 CP I1). */
    public const MERGE_SCAN_LIMIT = 500;

    /** Collation-equal rows read per query while looking for exact e-mail matches (1.47.0). */
    public const CANDIDATE_BATCH = 50;

    /** Rows deleted per statement by {@see self::deleteClosedBefore()}. */
    public const PURGE_BATCH = 500;

    /** Longest search query (characters). */
    public const MAX_QUERY_LENGTH = 180;

    /** Status filters of the administration list (`open` = pending and expired, the default). */
    public const FILTERS = ['open', 'pending', 'expired', 'accepted', 'revoked', 'all'];

    public function __construct(EntityManagerInterface $em)
    {
        parent::__construct($em, $em->getClassMetadata(Invitation::class));
    }

    /**
     * 1.50.0 (Beacon spec 239): a unit of work of AuthKit's transaction runner on this repository's manager.
     *
     * 1.55.0: an existing invitation this repository's manager does not hold (loaded through another manager) is
     * refused before anything is written, never inserted a second time; change it through {@see InvitationManager}, or
     * re-resolve it with {@see self::adopt()} first and change that instance.
     *
     * @throws AuthKitEntityNotManagedException When the invitation exists and this manager does not hold the instance
     * @throws AuthKitEntityNotFoundException When its row no longer exists
     */
    public function save(Invitation $invitation): void
    {
        $manager = $this->getEntityManager();
        AuthKitEntities::assertSavable($manager, $invitation);
        (new AuthKitTransactionRunner($manager))->run(static fn () => $manager->persist($invitation));
    }

    /**
     * 2.0 (REQ-RUNTIME-003, audit XX-I1): stores the change of an existing invitation (a resend, a re-invite, a
     * revocation) only while its row is still open, in one unit of work: a conditional UPDATE writes the new token hash
     * and the revocation guarded on `acceptedAt IS NULL AND revokedAt IS NULL` (it also takes the row lock), and the
     * remaining changes are flushed in the same transaction. A concurrent acceptance therefore either commits first
     * (nothing is written here: the instance is refreshed from the row and false is returned) or waits and then finds
     * the old token gone or the invitation revoked (its own single-use claim fails). A new invitation is inserted.
     *
     * @throws AuthKitEntityNotManagedException When the invitation exists and this manager does not hold the instance
     * @throws AuthKitEntityNotFoundException When its row no longer exists
     *
     * @return bool false when the invitation was accepted or revoked meanwhile (nothing written)
     *
     * @internal used by InvitationManager
     */
    public function saveWhileOpen(Invitation $invitation): bool
    {
        if ($invitation->getId() === null) {
            $this->save($invitation);

            return true;
        }
        $manager = $this->getEntityManager();
        AuthKitEntities::assertSavable($manager, $invitation);

        // On the connection: the manager is flushed only after the guard (a flush first would write the change unguarded).
        return (new AuthKitTransactionRunner($manager))->runOnConnection(function () use ($manager, $invitation): bool {
            $query = $manager->createQueryBuilder()
                ->update(Invitation::class, 'i')
                ->set('i.tokenHash', ':hash')
                ->set('i.revokedAt', ':revokedAt')
                ->set('i.revokedBy', ':revokedBy')
                ->where('i.id = :id')
                ->andWhere('i.acceptedAt IS NULL')
                ->andWhere('i.revokedAt IS NULL')
                ->setParameter('hash', $invitation->getTokenHash())
                ->setParameter('revokedAt', $invitation->getRevokedAt(), Types::DATETIME_IMMUTABLE)
                ->setParameter('revokedBy', $invitation->getRevokedBy())
                ->setParameter('id', $invitation->getId())
                ->getQuery();
            if ($this->write(static fn (): mixed => $query->execute()) !== 1) {
                // Accepted or revoked meanwhile: drop this unit's changes, write nothing.
                $manager->refresh($invitation);

                return false;
            }
            $manager->flush();

            return true;
        });
    }

    /**
     * 1.55.0 (REQ-ARCH-001 rule 6): the instance this repository's manager holds for the invitation, loaded again by id
     * when the caller's instance comes from another manager (an invitation the application loaded through its own manager). The same object otherwise, and for a new invitation. The
     * caller's instance and manager are left untouched.
     *
     * @throws AuthKitEntityNotFoundException When the invitation no longer exists
     */
    public function adopt(Invitation $invitation): Invitation
    {
        return AuthKitEntities::adopt($this->getEntityManager(), $invitation);
    }

    /** The invitation still open (pending or expired) for the e-mail in the profile, if any (the newest). */
    public function findOpenByEmail(string $profile, string $email): ?Invitation
    {
        return $this->exactOpenByEmail($profile, $email, null, null, 1)[0] ?? null;
    }

    /**
     * The newest open invitation of the e-mail in the profile whose `payload[$key]` equals `$value`
     * (`invitations.merge_key`, 1.33): compared after {@see self::mergeValue()} (7 ≡ '7'; a missing key ≡ null, never
     * equal to '' or '0'). Every unexpired open invitation of the e-mail is scanned; more than
     * {@see self::MERGE_SCAN_LIMIT} throws (SEC-004 CP I1: fail closed rather than miss the match and open a second
     * live link). Expired open invitations (dead links until resent) do not count toward that limit (CP N2: stale rows
     * cannot block new invitations); the newest {@see self::MERGE_SCAN_LIMIT} of them are still matched.
     *
     * @param DateTimeImmutable|null $now (1.33.1) reference time for "expired"; null: now
     *
     * @throws OverflowException
     */
    public function findOpenByEmailAndPayloadKey(string $profile, string $email, string $key, mixed $value, ?DateTimeImmutable $now = null): ?Invitation
    {
        $now ??= new DateTimeImmutable();
        $live = $this->openByEmail($profile, $email, $now, true);
        if (count($live) > self::MERGE_SCAN_LIMIT) {
            throw new OverflowException(sprintf('More than %d open invitations for one e-mail.', self::MERGE_SCAN_LIMIT));
        }
        $wanted = self::mergeValue($value);
        foreach ([$live, $this->openByEmail($profile, $email, $now, false)] as $open) {
            foreach ($open as $invitation) {
                if (self::mergeValue($invitation->getPayload()[$key] ?? null) === $wanted) {
                    return $invitation;
                }
            }
        }

        return null;
    }

    /**
     * Open invitations of the e-mail, newest first, at most {@see self::MERGE_SCAN_LIMIT} + 1: unexpired or expired.
     *
     * @return list<Invitation>
     */
    private function openByEmail(string $profile, string $email, DateTimeImmutable $now, bool $unexpired): array
    {
        return $this->exactOpenByEmail($profile, $email, $now, $unexpired, self::MERGE_SCAN_LIMIT + 1);
    }

    /**
     * Open invitations whose stored e-mail is byte-identical to the normalised `$email`, newest first, at most `$limit`
     * (REQ-SEC-006, 1.47.0). The SQL equality only pre-selects: on a folding collation it also returns look-alike
     * addresses (`josé@` for `jose@`, `jose@ ` on PAD SPACE). Candidates are read in batches by descending id until
     * `$limit` exact rows are found or none is left, so look-alikes never hide (nor stand in for) the e-mail's own rows.
     *
     * @param bool|null $unexpired null: pending and expired
     *
     * @return list<Invitation>
     */
    private function exactOpenByEmail(string $profile, string $email, ?DateTimeImmutable $now, ?bool $unexpired, int $limit): array
    {
        $email    = Invitation::normalizeEmail($email);
        $exact    = [];
        $beforeId = null;
        do {
            $qb = $this->createQueryBuilder('i')
                ->andWhere('i.profile = :profile')
                ->andWhere('i.email = :email')
                ->andWhere('i.acceptedAt IS NULL')
                ->andWhere('i.revokedAt IS NULL')
                ->setParameter('profile', $profile)
                ->setParameter('email', $email)
                ->orderBy('i.id', SortDirection::Descending)
                ->setMaxResults(self::CANDIDATE_BATCH);
            if ($unexpired !== null) {
                $qb->andWhere($unexpired ? 'i.expiresAt > :now' : 'i.expiresAt <= :now')->setParameter('now', $now ?? new DateTimeImmutable());
            }
            if ($beforeId !== null) {
                $qb->andWhere('i.id < :beforeId')->setParameter('beforeId', $beforeId);
            }
            /** @var list<Invitation> $rows */
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $invitation) {
                $beforeId = (int) $invitation->getId();
                if ($invitation->getProfile() === $profile && $invitation->getEmail() === $email) {
                    $exact[] = $invitation;
                    if (count($exact) >= $limit) {
                        return $exact;
                    }
                }
            }
        } while (count($rows) === self::CANDIDATE_BATCH);

        return $exact;
    }

    /**
     * Comparable form of a merge key value: null stays null, booleans '1' / '0', other scalars their string, anything
     * else a JSON form that never equals a scalar's.
     */
    public static function mergeValue(mixed $value): ?string
    {
        return match (true) {
            $value === null   => null,
            is_bool($value)   => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default           => "\0" . json_encode($value),
        };
    }

    /**
     * Open invitations issued by an inviter (security identifier), oldest first, at most `$limit`.
     *
     * @return list<Invitation>
     */
    public function findOpenByInviter(string $inviter, int $limit, ?string $profile = null): array
    {
        // REQ-SEC-006 (1.47.0): exact inviter before the limit. A folding collation also returns a look-alike inviter's
        // invitations (`Admin` for `admin`, `admin ` on PAD SPACE); they are skipped while reading by ascending id, so
        // revoking one inviter's invitations never closes another's.
        $limit   = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $exact   = [];
        $afterId = 0;
        do {
            $qb = $this->createQueryBuilder('i')
                ->andWhere('i.invitedBy = :inviter')
                ->andWhere('i.acceptedAt IS NULL')
                ->andWhere('i.revokedAt IS NULL')
                ->andWhere('i.id > :afterId')
                ->setParameter('inviter', $inviter)
                ->setParameter('afterId', $afterId)
                ->orderBy('i.id', SortDirection::Ascending)
                ->setMaxResults($limit);
            if ($profile !== null) {
                $qb->andWhere('i.profile = :profile')->setParameter('profile', $profile);
            }
            /** @var list<Invitation> $rows */
            $rows = $qb->getQuery()->getResult();
            foreach ($rows as $invitation) {
                $afterId = (int) $invitation->getId();
                if ($invitation->getInvitedBy() === $inviter && ($profile === null || $invitation->getProfile() === $profile)) {
                    $exact[] = $invitation;
                    if (count($exact) >= $limit) {
                        return $exact;
                    }
                }
            }
        } while (count($rows) === $limit);

        return $exact;
    }

    public function findOneByTokenHash(string $tokenHash): ?Invitation
    {
        return $tokenHash === '' ? null : $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findOneByPublicId(string $profile, string $publicId): ?Invitation
    {
        return $this->findOneBy(['profile' => $profile, 'publicId' => $publicId]);
    }

    /**
     * Single use: marks the invitation accepted only if it is still the same link, open and not expired (one UPDATE,
     * so two concurrent acceptances cannot both succeed). Returns whether this call claimed it.
     */
    public function claim(Invitation $invitation, string $tokenHash, string $acceptedBy, DateTimeImmutable $now): bool
    {
        $query = $this->getEntityManager()->createQueryBuilder()
            ->update(Invitation::class, 'i')
            ->set('i.acceptedAt', ':now')
            ->set('i.acceptedBy', ':by')
            ->where('i.id = :id')
            ->andWhere('i.tokenHash = :hash')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->andWhere('i.expiresAt > :now')
            ->setParameter('now', $now)
            ->setParameter('by', mb_substr($acceptedBy, 0, Invitation::IDENTIFIER_MAX_LENGTH))
            ->setParameter('id', $invitation->getId())
            ->setParameter('hash', $tokenHash)
            ->getQuery();
        $updated = $this->write(static fn (): mixed => $query->execute());

        return $updated === 1;
    }

    /**
     * One page of the administration list of a profile, newest first.
     *
     * @return list<Invitation>
     */
    public function findForListing(string $profile, string $status, string $query, DateTimeImmutable $now, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('i')
            ->setMaxResults(max(1, min(self::MAX_PAGE_SIZE, $limit)))
            ->setFirstResult(max(0, $offset))
            ->orderBy('i.sentAt', SortDirection::Descending)
            ->addOrderBy('i.id', SortDirection::Descending);
        $this->applyFilters($qb, $profile, $status, $query, $now);

        /** @var list<Invitation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countForListing(string $profile, string $status, string $query, DateTimeImmutable $now): int
    {
        $qb = $this->createQueryBuilder('i')->select('COUNT(i.id)');
        $this->applyFilters($qb, $profile, $status, $query, $now);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Purge: revoked invitations, and expired ones never accepted, whose last change is older than `$before`
     * (accepted ones too when `$includeAccepted`). Deleted in batches of {@see self::PURGE_BATCH}, at most
     * `$maxBatches` statements per call.
     *
     * @return int deleted rows
     */
    public function deleteClosedBefore(DateTimeImmutable $before, ?string $profile = null, bool $includeAccepted = false, int $maxBatches = 1000, bool $dryRun = false): int
    {
        $deleted = 0;
        for ($batch = 0; $batch < $maxBatches; ++$batch) {
            $qb = $this->createQueryBuilder('i')
                ->select('i.id')
                ->andWhere($includeAccepted
                    ? '(i.revokedAt IS NOT NULL AND i.revokedAt < :before) OR (i.revokedAt IS NULL AND i.acceptedAt IS NOT NULL AND i.acceptedAt < :before) OR (i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt < :before)'
                    : '(i.revokedAt IS NOT NULL AND i.revokedAt < :before) OR (i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt < :before)')
                ->setParameter('before', $before)
                ->orderBy('i.id', SortDirection::Ascending)
                ->setFirstResult($dryRun ? $deleted : 0)
                ->setMaxResults(self::PURGE_BATCH);
            if ($profile !== null) {
                $qb->andWhere('i.profile = :profile')->setParameter('profile', $profile);
            }
            /** @var list<array{id: int|string}> $rows */
            $rows = $qb->getQuery()->getScalarResult();
            if ($rows === []) {
                break;
            }
            if (!$dryRun) {
                $delete = $this->getEntityManager()->createQueryBuilder()
                    ->delete(Invitation::class, 'i')
                    ->where('i.id IN (:ids)')
                    ->setParameter('ids', array_map(static fn (array $row): int => (int) $row['id'], $rows))
                    ->getQuery();
                $this->write(static fn (): mixed => $delete->execute());
            }
            $deleted += count($rows);
            if (count($rows) < self::PURGE_BATCH) {
                break;
            }
        }

        return $deleted;
    }

    private function applyFilters(QueryBuilder $qb, string $profile, string $status, string $query, DateTimeImmutable $now): void
    {
        $qb->andWhere('i.profile = :profile')->setParameter('profile', $profile);
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
        if ($query !== '') {
            $qb->andWhere("i.email LIKE :q ESCAPE '!'")
                ->setParameter('q', '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Invitation::normalizeEmail($query)) . '%');
        }
        match ($status) {
            'pending'  => $qb->andWhere('i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt > :now')->setParameter('now', $now),
            'expired'  => $qb->andWhere('i.acceptedAt IS NULL AND i.revokedAt IS NULL AND i.expiresAt <= :now')->setParameter('now', $now),
            'accepted' => $qb->andWhere('i.acceptedAt IS NOT NULL'),
            'revoked'  => $qb->andWhere('i.revokedAt IS NOT NULL'),
            'all'      => $qb,
            default    => $qb->andWhere('i.acceptedAt IS NULL AND i.revokedAt IS NULL'),
        };
    }

    /**
     * A bulk statement: in a savepoint inside an open transaction with the private entity manager (1.46.0), exactly
     * as before otherwise.
     *
     * @template T
     *
     * @param Closure(): T $statement
     *
     * @return T
     */
    private function write(Closure $statement): mixed
    {
        $manager = $this->getEntityManager();

        return AuthKitSavepoint::write($manager->getConnection(), $statement, $manager);
    }
}
