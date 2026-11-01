<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Nowo\AuthKitBundle\Entity\Invitation;
use SortDirection;

use function array_map;
use function count;
use function max;
use function mb_substr;
use function min;
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

    public function save(Invitation $invitation): void
    {
        $this->getEntityManager()->persist($invitation);
        $this->getEntityManager()->flush();
    }

    /** The invitation still open (pending or expired) for the e-mail in the profile, if any. */
    public function findOpenByEmail(string $profile, string $email): ?Invitation
    {
        /** @var Invitation|null $invitation */
        $invitation = $this->createQueryBuilder('i')
            ->andWhere('i.profile = :profile')
            ->andWhere('i.email = :email')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->setParameter('profile', $profile)
            ->setParameter('email', Invitation::normalizeEmail($email))
            ->orderBy('i.id', SortDirection::Descending)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $invitation;
    }

    /**
     * Open invitations issued by an inviter (security identifier), oldest first, at most `$limit`.
     *
     * @return list<Invitation>
     */
    public function findOpenByInviter(string $inviter, int $limit, ?string $profile = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.invitedBy = :inviter')
            ->andWhere('i.acceptedAt IS NULL')
            ->andWhere('i.revokedAt IS NULL')
            ->setParameter('inviter', $inviter)
            ->orderBy('i.id', SortDirection::Ascending)
            ->setMaxResults(max(1, min(self::MAX_PAGE_SIZE, $limit)));
        if ($profile !== null) {
            $qb->andWhere('i.profile = :profile')->setParameter('profile', $profile);
        }
        /** @var list<Invitation> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
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
        $updated = $this->getEntityManager()->createQueryBuilder()
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
            ->getQuery()
            ->execute();

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
                $this->getEntityManager()->createQueryBuilder()
                    ->delete(Invitation::class, 'i')
                    ->where('i.id IN (:ids)')
                    ->setParameter('ids', array_map(static fn (array $row): int => (int) $row['id'], $rows))
                    ->getQuery()
                    ->execute();
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
}
