<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Mfa\MfaOwner;

use function array_filter;
use function array_map;
use function array_values;
use function hash;
use function is_array;
use function is_string;

/**
 * Persistence of the passkeys (2.4.0), by DBAL statements on the table of {@see WebauthnCredential} (its prefixed name
 * from the metadata). Reads return detached {@see WebauthnCredentialRecord} values, never managed entities, so nothing
 * survives the request in a FrankenPHP worker. Every lookup by an owner or a credential id re-checks the row byte for
 * byte in PHP (REQ-SEC-006): the SQL equality only pre-selects. The sign counter moves by one conditional UPDATE, so
 * two parallel assertions with the same counter cannot both pass (REQ-RUNTIME-003). Stateless (worker safe).
 */
final readonly class WebauthnStore
{
    private const COLUMNS = 'id, public_id, profile, user_type, user_id, credential_id, user_handle, public_key, sign_count, transports, aaguid, backup_eligible, backup_state, label, created_at, last_used_at';

    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    /**
     * Digest a credential id is found by (hex SHA-256 of the raw id).
     */
    public static function credentialIdHash(string $rawId): string
    {
        return hash('sha256', $rawId);
    }

    /**
     * The passkeys of an owner in a profile, oldest first.
     *
     * @return list<WebauthnCredentialRecord>
     */
    public function credentialsOf(string $profile, MfaOwner $owner): array
    {
        return array_values(array_filter(
            $this->select('profile = :profile AND user_type = :type AND user_id = :id', ['profile' => $profile, 'type' => $owner->type, 'id' => $owner->id]),
            static fn (WebauthnCredentialRecord $record): bool => $record->profile === $profile && $record->userType === $owner->type && $record->userId === $owner->id,
        ));
    }

    /**
     * The passkeys stored under exactly this (raw) owner reference, in every profile (personal data: also a reference
     * refused since REQ-SEC-006, see {@see MfaOwner::legacy()}).
     *
     * @return list<WebauthnCredentialRecord>
     */
    public function allOf(string $type, string $id): array
    {
        return array_values(array_filter(
            $this->select('user_type = :type AND user_id = :id', ['type' => $type, 'id' => $id]),
            static fn (WebauthnCredentialRecord $record): bool => $record->userType === $type && $record->userId === $id,
        ));
    }

    /**
     * The passkey with this raw credential id in the profile, or null (unknown, another profile, or a look-alike row).
     */
    public function findByCredentialId(string $profile, string $rawId): ?WebauthnCredentialRecord
    {
        $encoded = Base64Url::encode($rawId);
        foreach ($this->select('credential_id_hash = :hash', ['hash' => self::credentialIdHash($rawId)]) as $record) {
            if ($record->credentialId === $encoded && $record->profile === $profile) {
                return $record;
            }
        }

        return null;
    }

    /**
     * The owner's passkey with this public id, or null (unknown, or another owner's: the same answer).
     */
    public function findForOwner(string $profile, MfaOwner $owner, string $publicId): ?WebauthnCredentialRecord
    {
        foreach ($this->select('public_id = :public', ['public' => $publicId]) as $record) {
            if ($record->publicId === $publicId && $record->profile === $profile && $record->userType === $owner->type && $record->userId === $owner->id) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Stores a new passkey; false when its credential id is already registered (by anyone).
     *
     * @param list<string> $transports
     */
    public function insert(string $publicId, string $profile, MfaOwner $owner, string $rawId, string $userHandle, string $publicKey, int $signCount, array $transports, ?string $aaguid, ?bool $backupEligible, ?bool $backupState, string $label, DateTimeImmutable $now): bool
    {
        $em = $this->entityManager();
        try {
            $this->write(fn (Connection $connection): int|string => $connection->insert($this->table(), AuthKitRows::preInsertId($em, WebauthnCredential::class) + [
                'public_id'          => $publicId,
                'profile'            => $profile,
                'user_type'          => $owner->type,
                'user_id'            => $owner->id,
                'credential_id_hash' => self::credentialIdHash($rawId),
                'credential_id'      => Base64Url::encode($rawId),
                'user_handle'        => $userHandle,
                'public_key'         => $publicKey,
                'sign_count'         => $signCount,
                'transports'         => $transports,
                'aaguid'             => $aaguid,
                'backup_eligible'    => $backupEligible,
                'backup_state'       => $backupState,
                'label'              => $label,
                'created_at'         => $now,
                'last_used_at'       => null,
            ], [
                'sign_count'      => ParameterType::INTEGER,
                'transports'      => Types::JSON,
                'backup_eligible' => Types::BOOLEAN,
                'backup_state'    => Types::BOOLEAN,
                'created_at'      => Types::DATETIME_IMMUTABLE,
                'last_used_at'    => Types::DATETIME_IMMUTABLE,
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Records a successful assertion: true only when the stored counter is still lower than `$signCount` (or both are
     * zero: an authenticator without a counter). A concurrent assertion that already moved the counter, a replayed
     * response or a cloned authenticator gets false.
     */
    public function recordUse(WebauthnCredentialRecord $record, int $signCount, ?bool $backupState, DateTimeImmutable $now): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE ' . $this->table() . ' SET sign_count = :count, backup_state = :backup, last_used_at = :now WHERE id = :id AND (sign_count < :count OR (sign_count = 0 AND :zero = 1))',
            ['count' => $signCount, 'backup' => $backupState, 'now' => $now, 'id' => $record->id, 'zero' => $signCount === 0 ? 1 : 0],
            ['count' => ParameterType::INTEGER, 'backup' => Types::BOOLEAN, 'now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER, 'zero' => ParameterType::INTEGER],
        )) === 1;
    }

    public function rename(WebauthnCredentialRecord $record, string $label): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE ' . $this->table() . ' SET label = :label WHERE id = :id',
            ['label' => $label, 'id' => $record->id],
            ['id' => ParameterType::INTEGER],
        )) === 1;
    }

    public function delete(WebauthnCredentialRecord $record): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'DELETE FROM ' . $this->table() . ' WHERE id = :id',
            ['id' => $record->id],
            ['id' => ParameterType::INTEGER],
        )) === 1;
    }

    /**
     * Whether the passkey table exists: a host that never enabled passkeys may not have created it.
     */
    public function hasTable(): bool
    {
        return $this->entityManager()->getConnection()->createSchemaManager()->tablesExist([$this->table()]);
    }

    /**
     * @param array<string, string> $params
     *
     * @return list<WebauthnCredentialRecord>
     */
    private function select(string $where, array $params): array
    {
        $connection = $this->entityManager()->getConnection();
        $rows       = $connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM ' . $this->table() . ' WHERE ' . $where . ' ORDER BY id ASC', $params);

        return array_map(static function (array $row) use ($connection): WebauthnCredentialRecord {
            /** @var DateTimeImmutable $created */
            $created = $connection->convertToPHPValue($row['created_at'], Types::DATETIME_IMMUTABLE);
            /** @var DateTimeImmutable|null $used */
            $used       = $connection->convertToPHPValue($row['last_used_at'], Types::DATETIME_IMMUTABLE);
            $transports = $connection->convertToPHPValue($row['transports'], Types::JSON);

            return new WebauthnCredentialRecord(
                (int) $row['id'],
                (string) $row['public_id'],
                (string) $row['profile'],
                (string) $row['user_type'],
                (string) $row['user_id'],
                (string) $row['credential_id'],
                (string) $row['user_handle'],
                (string) $row['public_key'],
                (int) $row['sign_count'],
                is_array($transports) ? array_values(array_filter($transports, is_string(...))) : [],
                $row['aaguid'] !== null ? (string) $row['aaguid'] : null,
                $row['backup_eligible'] !== null ? (bool) $connection->convertToPHPValue($row['backup_eligible'], Types::BOOLEAN) : null,
                $row['backup_state'] !== null ? (bool) $connection->convertToPHPValue($row['backup_state'], Types::BOOLEAN) : null,
                (string) $row['label'],
                $created,
                $used,
            );
        }, $rows);
    }

    /**
     * A DBAL write: in a savepoint inside an open transaction of AuthKit's private entity manager, the plain statement
     * otherwise (see {@see AuthKitSavepoint}).
     *
     * @template T
     *
     * @param Closure(Connection): T $statement
     *
     * @return T
     */
    private function write(Closure $statement): mixed
    {
        $em         = $this->entityManager();
        $connection = $em->getConnection();

        return AuthKitSavepoint::write($connection, static fn (): mixed => $statement($connection), $em);
    }

    private function table(): string
    {
        return $this->entityManager()->getClassMetadata(WebauthnCredential::class)->getTableName();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManagerForClass(WebauthnCredential::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The AuthKit WebAuthn entity is not mapped (doctrine.register_mapping).');
        }

        return $em;
    }
}
