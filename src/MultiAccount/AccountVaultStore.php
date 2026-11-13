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
     * @return list<array{id: int, public_id: string, user_identifier: string, token_hash: string, previous_token_hash: string|null, rotated_at: DateTimeImmutable, sealed: string|null, previous_sealed: string|null, rotated_by: string|null, expires_at: DateTimeImmutable}>
     */
    public function find(string $profile, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, public_id, user_identifier, token_hash, previous_token_hash, rotated_at, sealed, previous_sealed, rotated_by, expires_at FROM ' . $this->table() . ' WHERE profile = :profile AND (token_hash IN (:hashes) OR previous_token_hash IN (:hashes))',
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
                'previous_sealed'     => $row['previous_sealed'] !== null ? (string) $row['previous_sealed'] : null,
                'rotated_by'          => $row['rotated_by'] !== null ? (string) $row['rotated_by'] : null,
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
            'previous_sealed'     => null,
            'rotated_by'          => null,
            'created_at'          => $now,
            'last_used_at'        => $now,
            'expires_at'          => $expiresAt,
        ], ['rotated_at' => Types::DATETIME_IMMUTABLE, 'created_at' => Types::DATETIME_IMMUTABLE, 'last_used_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);

        return (int) $this->connection()->lastInsertId();
    }

    /**
     * Replaces the token (the old one becomes the previous one) and the sealed blob. `$previousSealed` is the new
     * token sealed under the previous one and `$rotatedBy` the keyed hash of the rotating browser's session: a
     * concurrent request of that browser still holding the previous token learns the current one during
     * `rotation_grace`. `$expiresAt` renews the entry. False when `$currentHash` is no longer the entry's token
     * (another request rotated it first).
     */
    public function rotate(int $id, string $currentHash, string $newHash, ?string $sealed, string $previousSealed, ?string $rotatedBy, DateTimeImmutable $now, ?DateTimeImmutable $expiresAt = null): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table() . ' SET previous_token_hash = token_hash, previous_sealed = :previous_sealed, rotated_by = :rotated_by, token_hash = :new, sealed = :sealed, rotated_at = :now, last_used_at = :now, expires_at = COALESCE(:expires, expires_at) WHERE id = :id AND token_hash = :current',
            ['new' => $newHash, 'sealed' => $sealed, 'previous_sealed' => $previousSealed, 'rotated_by' => $rotatedBy, 'now' => $now, 'expires' => $expiresAt, 'id' => $id, 'current' => $currentHash],
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
     * Deletes the entries of a user of a profile in every browser (signed out everywhere, removed), but `$exceptId`;
     * returns how many.
     */
    public function deleteForUser(string $profile, string $userIdentifier, ?int $exceptId = null): int
    {
        $rows = $this->connection()->fetchAllAssociative('SELECT id, user_identifier FROM ' . $this->table() . ' WHERE profile = :profile AND user_identifier = :identifier', ['profile' => $profile, 'identifier' => $userIdentifier]);
        $ids  = [];
        foreach ($rows as $row) {
            // GY-I8 / REQ-SEC-006 item 7: a folding collation also returns look-alikes of the identifier (jose@ for josé@,
            // Jose@ for jose@, `jose@ ` on PAD SPACE): only the byte-identical identifier is this user's.
            if ((int) $row['id'] !== $exceptId && (string) $row['user_identifier'] === $userIdentifier) {
                $ids[] = (int) $row['id'];
            }
        }
        if ($ids === []) {
            return 0;
        }

        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE id IN (:ids)', ['ids' => $ids], ['ids' => ArrayParameterType::INTEGER]);
    }

    /**
     * Deletes the entries that expired before `$before`; returns how many.
     */
    public function purge(DateTimeImmutable $before): int
    {
        return (int) $this->connection()->executeStatement('DELETE FROM ' . $this->table() . ' WHERE expires_at < :before', ['before' => $before], ['before' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Whether the vault table exists: a host that never enabled the switcher may not have created it (GY-L5).
     */
    public function hasTable(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([$this->table()]);
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
