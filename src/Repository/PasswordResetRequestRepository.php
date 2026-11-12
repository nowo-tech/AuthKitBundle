<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\PasswordReset\PasswordResetRequestRow;
use Throwable;

use function array_map;
use function is_string;

/**
 * Reads and writes the `auth_kit_password_reset` rows (1.46.0, `password_reset.storage: table`) with DBAL statements
 * on the connection of the entity manager that maps {@see PasswordResetRequest}: every read is fresh (FrankenPHP
 * worker mode, W-01), and no entity manager is flushed (the application's pending changes stay pending, REQ-ARCH-001
 * rule 6). The table name comes from the (prefixed) mapping. Stateless.
 */
final readonly class PasswordResetRequestRepository
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Stores the row as the user's only pending reset credential: the user's earlier rows are deleted in the same
     * transaction (a savepoint inside a transaction the application opened).
     */
    public function save(PasswordResetRequestRow $row): void
    {
        $connection = $this->connection();
        $table      = $this->table();
        $connection->beginTransaction();
        try {
            $this->deleteOn($connection, $table, $row->userClass, $row->userId, [$row->userIdentifier]);
            $connection->insert($table, [
                'profile'         => $row->profile,
                'user_class'      => $row->userClass,
                'user_id'         => $row->userId,
                'user_identifier' => $row->userIdentifier,
                'token_hash'      => $row->tokenHash,
                'code_hash'       => $row->codeHash,
                'expires_at'      => $row->expiresAt,
                'created_at'      => $row->createdAt,
            ], ['expires_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE]);
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Deletes the rows of a user: by id when it has one, otherwise by each given identifier (rows of a user without
     * an id). Returns the number of deleted rows.
     *
     * @param list<string> $identifiers
     */
    public function delete(string $userClass, ?string $userId, array $identifiers): int
    {
        return $this->deleteOn($this->connection(), $this->table(), $userClass, $userId, $identifiers);
    }

    /**
     * The rows whose `token_hash` equals the hash (normally one).
     *
     * @return list<PasswordResetRequestRow>
     */
    public function findByTokenHash(string $tokenHash): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM ' . $this->table() . ' WHERE token_hash = :hash ORDER BY id DESC',
            ['hash' => $tokenHash],
        );

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * The user's pending row (the newest one), by id when it has one, otherwise by identifier.
     */
    public function findForUser(string $userClass, ?string $userId, string $userIdentifier): ?PasswordResetRequestRow
    {
        [$where, $parameters] = $userId !== null
            ? ['user_id = :id', ['id' => $userId]]
            : ['user_id IS NULL AND user_identifier = :identifier', ['identifier' => $userIdentifier]];
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM ' . $this->table() . ' WHERE user_class = :class AND ' . $where . ' ORDER BY id DESC',
            ['class' => $userClass] + $parameters,
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param list<string> $identifiers
     */
    private function deleteOn(Connection $connection, string $table, string $userClass, ?string $userId, array $identifiers): int
    {
        if ($userId !== null) {
            return (int) $connection->executeStatement(
                'DELETE FROM ' . $table . ' WHERE user_class = :class AND user_id = :id',
                ['class' => $userClass, 'id' => $userId],
            );
        }

        return (int) $connection->executeStatement(
            'DELETE FROM ' . $table . ' WHERE user_class = :class AND user_id IS NULL AND user_identifier IN (:identifiers)',
            ['class' => $userClass, 'identifiers' => $identifiers],
            ['identifiers' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PasswordResetRequestRow
    {
        $connection = $this->connection();
        /** @var DateTimeImmutable $expiresAt */
        $expiresAt = $connection->convertToPHPValue($row['expires_at'], Types::DATETIME_IMMUTABLE);
        /** @var DateTimeImmutable $createdAt */
        $createdAt = $connection->convertToPHPValue($row['created_at'], Types::DATETIME_IMMUTABLE);

        return new PasswordResetRequestRow(
            (string) $row['profile'],
            (string) $row['user_class'],
            is_string($row['user_id']) ? $row['user_id'] : null,
            (string) $row['user_identifier'],
            (string) $row['token_hash'],
            is_string($row['code_hash']) ? $row['code_hash'] : null,
            $expiresAt,
            $createdAt,
        );
    }

    private function table(): string
    {
        return $this->entityManager()->getClassMetadata(PasswordResetRequest::class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManagerForClass(PasswordResetRequest::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The AuthKit entities are not mapped (doctrine.register_mapping): password_reset.storage "table" needs them.');
        }

        return $em;
    }
}
