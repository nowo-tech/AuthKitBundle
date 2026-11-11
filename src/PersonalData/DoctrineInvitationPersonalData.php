<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\PersonalData;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;
use function ksort;

/**
 * Default {@see InvitationPersonalDataEraserInterface} and {@see InvitationPersonalDataExporterInterface} (1.45): DBAL
 * statements on the connection of the entity manager that maps {@see Invitation} (table prefix included), never a
 * flush. Managed `Invitation` entities of the current unit of work are not refreshed. Without that manager or table it
 * erases and exports nothing. Holds no per-call state (worker mode).
 *
 * Revoking sets `revoked_at` only (no `revoked_by`, no {@see Event\InvitationRevokedEvent}): the acceptance refuses a
 * revoked invitation, so the link stops working at once.
 */
final readonly class DoctrineInvitationPersonalData implements InvitationPersonalDataEraserInterface, InvitationPersonalDataExporterInterface
{
    /** Actor columns (security identifiers) cleared for a user-identifier subject. */
    public const ACTOR_COLUMNS = ['invited_by', 'last_sent_by', 'revoked_by', 'accepted_by'];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ?ClockInterface $clock = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
    }

    public function erase(InvitationPersonalDataSubject $subject): InvitationErasureResult
    {
        $target = $this->target();
        if ($target === null) {
            return new InvitationErasureResult();
        }
        [$connection, $table] = $target;
        if ($connection->isTransactionActive()) {
            // The caller owns the transaction (UserKit's erasure): no event, it could still roll back.
            return $this->eraseIn($connection, $table, $subject);
        }
        /** @var InvitationErasureResult $result */
        $result = $connection->transactional(fn (Connection $connection): InvitationErasureResult => $this->eraseIn($connection, $table, $subject));
        if ($result->total() > 0) {
            $this->eventDispatcher?->dispatch(new InvitationPersonalDataErasedEvent($subject, $result));
        }

        return $result;
    }

    public function export(InvitationPersonalDataSubject $subject): array
    {
        $export = ['received' => [], 'sent' => []];
        $target = $this->target();
        if ($target === null) {
            return $export;
        }
        [$connection, $table] = $target;
        $platform             = $connection->getDatabasePlatform();
        $now                  = $this->now();
        $columns              = ['profile', 'email', 'roles', 'message', 'locale', 'created_at', 'sent_at', 'expires_at', 'send_count', 'accepted_at', 'revoked_at'];
        if ($subject->isEmail()) {
            foreach (ExactRows::find($connection, $table, ['email' => $subject->value], $columns) as $row) {
                $export['received'][] = $this->entry($row, $platform, $now, false);
            }

            return $export;
        }
        $rows = [];
        foreach (['invited_by', 'last_sent_by'] as $column) {
            foreach (ExactRows::find($connection, $table, [$column => $subject->value], $columns) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        ksort($rows);
        foreach ($rows as $row) {
            $export['sent'][] = $this->entry($row, $platform, $now, true);
        }

        return $export;
    }

    private function eraseIn(Connection $connection, string $table, InvitationPersonalDataSubject $subject): InvitationErasureResult
    {
        if ($subject->isEmail()) {
            $ids = ExactRows::ids(ExactRows::find($connection, $table, ['email' => $subject->value]));

            return new InvitationErasureResult(deleted: ExactRows::writeByIds($connection, 'DELETE FROM ' . $table . ' WHERE id IN (:ids)', $ids));
        }
        // First the actor's open invitations stop working, then every reference to the actor goes.
        $open    = ExactRows::ids(ExactRows::find($connection, $table, ['invited_by' => $subject->value], [], 'accepted_at IS NULL AND revoked_at IS NULL'));
        $revoked = ExactRows::writeByIds(
            $connection,
            'UPDATE ' . $table . ' SET revoked_at = :now WHERE id IN (:ids) AND accepted_at IS NULL AND revoked_at IS NULL',
            $open,
            ['now' => $this->now()],
            ['now' => Types::DATETIME_IMMUTABLE],
        );
        $cleared = 0;
        foreach (self::ACTOR_COLUMNS as $column) {
            $ids = ExactRows::ids(ExactRows::find($connection, $table, [$column => $subject->value]));
            $cleared += ExactRows::writeByIds($connection, 'UPDATE ' . $table . ' SET ' . $column . ' = NULL WHERE id IN (:ids)', $ids);
        }

        return new InvitationErasureResult(revoked: $revoked, referencesCleared: $cleared);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function entry(array $row, AbstractPlatform $platform, DateTimeImmutable $now, bool $withEmail): array
    {
        $date = static function (mixed $value) use ($platform): ?string {
            $date = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($value, $platform);

            return $date instanceof DateTimeInterface ? $date->format(DateTimeInterface::ATOM) : null;
        };
        $roles      = Type::getType(Types::JSON)->convertToPHPValue($row['roles'] ?? null, $platform);
        $expiresAt  = Type::getType(Types::DATETIME_IMMUTABLE)->convertToPHPValue($row['expires_at'] ?? null, $platform);
        $acceptedAt = $date($row['accepted_at'] ?? null);
        $revokedAt  = $date($row['revoked_at'] ?? null);
        $entry      = ['profile' => (string) $row['profile']];
        if ($withEmail) {
            $entry['email'] = (string) $row['email'];
        }

        return $entry + [
            'status' => match (true) {
                $revokedAt !== null                                           => 'revoked',
                $acceptedAt !== null                                          => 'accepted',
                $expiresAt instanceof DateTimeImmutable && $expiresAt <= $now => 'expired',
                default                                                       => 'pending',
            },
            'roles'       => is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [],
            'message'     => is_string($row['message'] ?? null) ? $row['message'] : null,
            'locale'      => is_string($row['locale'] ?? null) ? $row['locale'] : null,
            'created_at'  => $date($row['created_at'] ?? null),
            'sent_at'     => $date($row['sent_at'] ?? null),
            'expires_at'  => $date($row['expires_at'] ?? null),
            'send_count'  => (int) ($row['send_count'] ?? 0),
            'accepted_at' => $acceptedAt,
            'revoked_at'  => $revokedAt,
        ];
    }

    /**
     * The connection and table of the invitations; null when no entity manager maps them or the table is missing.
     *
     * @return array{Connection, string}|null
     */
    private function target(): ?array
    {
        $manager = $this->doctrine->getManagerForClass(Invitation::class);
        if (!$manager instanceof EntityManagerInterface) {
            return null;
        }
        $table      = $manager->getClassMetadata(Invitation::class)->getTableName();
        $connection = $manager->getConnection();

        return $connection->createSchemaManager()->tablesExist([$table]) ? [$connection, $table] : null;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }
}
