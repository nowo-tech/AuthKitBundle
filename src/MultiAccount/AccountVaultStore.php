<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\MultiAccount;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;

use function array_map;

/**
 * Vault rows (1.34): one parameterised DBAL statement per operation; token rotation is a conditional UPDATE (only
 * the current token can rotate), so two tabs cannot both rotate the same entry. Table name from the (prefixed)
 * metadata. Stateless.
 */
final readonly class AccountVaultStore
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Rows of a profile whose current or previous token hash is one of `$hashes`.
     *
     * @param list<string> $hashes
     *
     * @return list<array{id: int, public_id: string, user_identifier: string, token_hash: string, previous_token_hash: string|null, rotated_at: DateTimeImmutable, sealed: string|null, expires_at: DateTimeImmutable}>
     */
    public function find(string $profile, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, public_id, user_identifier, token_hash, previous_token_hash, rotated_at, sealed, expires_at FROM ' . $this->table() . ' WHERE profile = :profile AND (token_hash IN (:hashes) OR previous_token_hash IN (:hashes))',
            ['profile' => $profile, 'hashes' => $hashes],
            ['hashes' => ArrayParameterType::STRING],
        );
        $platform = $this->connection()->getDatabasePlatform();
        $date     = Type::getType(Types::DATETIME_IMMUTABLE);

        return array_map(static function (array $row) use ($date, $platform): array {
            /** @var DateTimeImmutable $rotated */
            $rotated = $date->convertToPHPValue($row['rotated_at'], $platform);
            /** @var DateTimeImmutable $expires */
            $expires = $date->convertToPHPValue($row['expires_at'], $platform);

            return [
                'id'                  => (int) $row['id'],
                'public_id'           => (string) $row['public_id'],
                'user_identifier'     => (string) $row['user_identifier'],
                'token_hash'          => (string) $row['token_hash'],
                'previous_token_hash' => $row['previous_token_hash'] !== null ? (string) $row['previous_token_hash'] : null,
                'rotated_at'          => $rotated,
                'sealed'              => $row['sealed'] !== null ? (string) $row['sealed'] : null,
                'expires_at'          => $expires,
            ];
        }, $rows);
    }

    public function insert(string $publicId, string $profile, string $userIdentifier, string $tokenHash, ?string $sealed, DateTimeImmutable $now, DateTimeImmutable $expiresAt): int
    {
        $this->connection()->insert($this->table(), [
            'public_id'           => $publicId,
            'profile'             => $profile,
            'user_identifier'     => $userIdentifier,
            'token_hash'          => $tokenHash,
            'previous_token_hash' => null,
            'rotated_at'          => $now,
            'sealed'              => $sealed,
            'created_at'          => $now,
            'last_used_at'        => $now,
            'expires_at'          => $expiresAt,
        ], ['rotated_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE, 'last_used_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Replaces the token (the old one becomes the previous one) and the sealed blob; `$expiresAt` renews the entry.
     * False when `$currentHash` is no longer the entry's token (another request rotated it first).
     */
    public function rotate(int $id, string $currentHash, string $newHash, ?string $sealed, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt = null): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET previous_token_hash = token_hash, token_hash = :new, sealed = :sealed, rotated_at = :now, last_used_at = :now, expires_at = COALESCE(:expires, expires_at) WHERE id = :id AND token_hash = :current',
            ['new' => $newHash, 'sealed' => $sealed, 'now' => $now, 'expires' => $expiresAt, 'id' => $id, 'current' => $currentHash],
            ['now' => Types::DATETIME_IMMUTABLE, 'expires' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        ) === 1;
    }

    /**
     * @param list<int> $ids
     */
    public function delete(array $ids): void
    {
        if ($ids !== []) {
            $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
        }
    }

    /**
     * Deletes the entries of a user in every browser (signed out everywhere, removed); returns how many.
     */
    public function deleteForUser(string $userIdentifier): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE user_identifier = :identifier', ['identifier' => $userIdentifier]);
    }

    /**
     * Deletes the entries that expired before `$before`; returns how many.
     */
    public function purge(DateTimeImmutable $before): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE expires_at < :before', ['before' => $before], ['before' => Types::DATETIME_IMMUTABLE]);
    }

    private function table(): string
    {
        return $this->entityManager()->getClassMetadata(AccountVaultEntry::class)->getTableName();
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManagerForClass(AccountVaultEntry::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The AuthKit multi-account entity is not mapped (doctrine.register_mapping).');
        }

        return $em;
    }
}
