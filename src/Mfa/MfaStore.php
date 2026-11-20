<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Doctrine\AuthKitSavepoint;
use Nowo\AuthKitBundle\Doctrine\HostFlushReporter;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use SortDirection;

use function array_filter;
use function array_map;
use function array_values;

/**
 * Persistence of the second factor (1.34). The credential goes through the ORM (its secret is `#[Encrypted]`);
 * every security-relevant check-and-set (replay step, single-use recovery codes, trusted-device tokens) is one
 * conditional DBAL statement, so concurrent requests cannot use a code twice. Table names come from the (prefixed)
 * metadata. Stateless (worker safe).
 */
final readonly class MfaStore
{
    private const COLLISION = 'The MFA credential cannot be created: another owner\'s row matches it on the folding unique key uniq_auth_kit_mfa_owner. Apply the binary collation of the AuthKit 1.47.0 UPGRADING notes.';

    /**
     * 1.50.0: the optional EntityManagerRecovery argument is gone (writes go through AuthKit's transaction runner; a
     * manager closed by a failed write is replaced at the next unit-of-work boundary).
     *
     * @param HostFlushReporter|null $hostFlush 1.54.0: reports the application's pending changes a host-mode flush writes
     *                                          (wired only without `doctrine.private_entity_manager: true`)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?HostFlushReporter $hostFlush = null,
    ) {
    }

    /**
     * The credential of the owner in the profile. The SQL equality only pre-selects: on a folding collation (MySQL /
     * MariaDB `*_ci`, PAD SPACE) it also returns a look-alike owner's row (`josé@` for `jose@`, `bob ` for `bob`), which
     * the byte-for-byte re-check drops (REQ-SEC-006, 1.47.0).
     */
    public function credential(string $profile, MfaOwner $owner): ?MfaCredential
    {
        return $this->exactCredential($profile, $owner->type, $owner->id);
    }

    /**
     * Whether an enabled credential is stored under exactly this (raw) type and id. For a reference {@see MfaOwner}
     * refuses (1.47.0): a second factor enrolled under it before 1.47.0 must keep the sign-in closed.
     */
    public function hasEnabledCredentialFor(string $profile, string $type, string $id): bool
    {
        return $this->exactCredential($profile, $type, $id)?->isEnabled() === true;
    }

    /**
     * Every credential of an owner, in every profile (1.36, personal data: also a profile whose MFA was turned off
     * after enrolment, or that the user class no longer resolves to). Byte-for-byte re-check, see {@see credential()}.
     *
     * @return list<MfaCredential>
     */
    public function credentialsOf(MfaOwner $owner): array
    {
        $credentials = $this->entityManager()->getRepository(MfaCredential::class)->findBy([
            'userType' => $owner->type,
            'userId'   => $owner->id,
        ], ['id' => 'ASC']);

        // GY-I8 / REQ-SEC-006 item 7: a folding collation also returns look-alikes (jose@ for josé@, Bob for bob); case
        // and normalisation forms are different ids.
        return array_values(array_filter($credentials, static fn (MfaCredential $credential): bool => $credential->getUserType() === $owner->type && $credential->getUserId() === $owner->id));
    }

    /**
     * The credential of the owner, created when there is none. When the unique key `uniq_auth_kit_mfa_owner` still folds
     * (a table created before 1.47.0 and not altered, see UPGRADING) and a look-alike owner's row exists, this fails
     * closed BEFORE any write (audit OG-1): no insert that would collide and close the entity manager, and never a
     * fall back to that row. 1.50.0: a concurrent insert of the same owner is settled in a savepoint (the other request's
     * row is returned); the entity manager is never closed by it.
     *
     * @throws MfaUnavailableException when a look-alike owner's credential blocks the insert
     */
    public function credentialOrNew(string $profile, MfaOwner $owner, DateTimeImmutable $now): MfaCredential
    {
        [$credential, $lookAlikes] = $this->lookup($profile, $owner->type, $owner->id);
        if ($credential instanceof MfaCredential) {
            return $credential;
        }
        if ($lookAlikes) {
            throw new MfaUnavailableException(self::COLLISION);
        }

        // 1.50.0 (Beacon spec 239): the row is written in a savepoint (AuthKitRows): when a concurrent request of the
        // same owner wrote it first, only the savepoint is rolled back and that row is read again with a lock (the
        // latest committed version, whatever the transaction's snapshot). The entity manager is never closed by it.
        return $this->transactional(function () use ($profile, $owner, $now): MfaCredential {
            $lost         = AuthKitRows::insert($this->entityManager(), new MfaCredential($profile, $owner->type, $owner->id, $now));
            [$credential] = $this->lookup($profile, $owner->type, $owner->id, true);

            return $credential ?? throw new MfaUnavailableException(self::COLLISION, 0, $lost);
        });
    }

    /**
     * 1.50.0 (Beacon spec 239): runs `$change` on the credential in one unit of work of AuthKit's transaction runner,
     * the credential row locked first and read again (the latest committed version): every change of a second factor
     * (enrolment, disabling, administrator reset, recovery codes, trusted devices) takes this lock first, so two of
     * them never interleave (lock order: credential, recovery codes, trusted devices). Null, without calling
     * `$change`, when the row no longer exists.
     *
     * @template T
     *
     * @param callable(MfaCredential): T $change
     *
     * @return T|null
     */
    public function locked(MfaCredential $credential, callable $change): mixed
    {
        $id = $this->id($credential);

        return $this->transactional(function () use ($id, $credential, $change): mixed {
            $em = $this->entityManager();
            // A locking read by id: the row as stored (the managed instance refreshed with it), or null when it is gone.
            $managed = $em->createQueryBuilder()
                ->select('c')
                ->from(MfaCredential::class, 'c')
                ->where('c.id = :id')
                ->setParameter('id', $id)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->setHint(Query::HINT_REFRESH, true)
                ->getOneOrNullResult();
            if (!$managed instanceof MfaCredential) {
                if ($em->contains($credential)) {
                    $em->detach($credential);
                }

                return null;
            }

            return $change($managed);
        });
    }

    private function exactCredential(string $profile, string $type, string $id): ?MfaCredential
    {
        return $this->lookup($profile, $type, $id)[0];
    }

    /**
     * The byte-identical credential, and whether the SQL equality also returned look-alike rows.
     *
     * @return array{0: MfaCredential|null, 1: bool}
     */
    private function lookup(string $profile, string $type, string $id, bool $lock = false): array
    {
        $criteria = ['profile' => $profile, 'userType' => $type, 'userId' => $id];
        if ($lock) {
            // 1.50.0: a locking read, the latest committed rows whatever the transaction's snapshot.
            $query = $this->entityManager()->createQueryBuilder()
                ->select('c')
                ->from(MfaCredential::class, 'c')
                ->where('c.profile = :profile AND c.userType = :userType AND c.userId = :userId')
                ->setParameter('profile', $profile)
                ->setParameter('userType', $type)
                ->setParameter('userId', $id)
                ->orderBy('c.id', SortDirection::Ascending)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_READ)
                ->setHint(Query::HINT_REFRESH, true);
            /** @var list<MfaCredential> $candidates */
            $candidates = $query->getResult();
        } else {
            $candidates = $this->entityManager()->getRepository(MfaCredential::class)->findBy($criteria, ['id' => 'ASC']);
        }
        foreach ($candidates as $candidate) {
            if ($candidate->getProfile() === $profile && $candidate->getUserType() === $type && $candidate->getUserId() === $id) {
                return [$candidate, true];
            }
        }

        return [null, $candidates !== []];
    }

    public function save(MfaCredential $credential): void
    {
        $em = $this->entityManager();
        // 1.50.0: a unit of work of AuthKit's runner (a closed manager is replaced at the next boundary).
        $this->transactional(static fn () => $em->persist($credential));
    }

    /**
     * Accepts a TOTP time step once: true only when it is greater than the last accepted step (replay protection).
     */
    public function claimStep(MfaCredential $credential, int $step): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE ' . $this->table(MfaCredential::class) . ' SET last_used_step = :step WHERE id = :id AND totp_secret IS NOT NULL AND (last_used_step IS NULL OR last_used_step < :step)',
            ['step' => $step, 'id' => $this->id($credential)],
            ['step' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        )) === 1;
    }

    /**
     * Replaces every recovery code of a credential (one transaction).
     *
     * @param list<string> $hashes
     */
    public function replaceRecoveryCodes(MfaCredential $credential, array $hashes, DateTimeImmutable $now): void
    {
        $id    = $this->id($credential);
        $table = $this->table(MfaRecoveryCode::class);
        $this->transactional(function () use ($id, $table, $hashes, $now): void {
            $this->write(static function (Connection $connection) use ($id, $table, $hashes, $now): void {
                $connection->executeStatement('DELETE FROM ' . $table . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
                foreach ($hashes as $hash) {
                    $connection->insert($table, ['mfa_id' => $id, 'code_hash' => $hash, 'created_at' => $now], ['mfa_id' => ParameterType::INTEGER, 'created_at' => Types::DATETIME_IMMUTABLE]);
                }
            });
        });
    }

    /**
     * Consumes a recovery code: true when it existed and was unused.
     */
    public function useRecoveryCode(MfaCredential $credential, string $hash, DateTimeImmutable $now): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE ' . $this->table(MfaRecoveryCode::class) . ' SET used_at = :now WHERE mfa_id = :id AND code_hash = :hash AND used_at IS NULL',
            ['now' => $now, 'id' => $this->id($credential), 'hash' => $hash],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        )) === 1;
    }

    public function unusedRecoveryCodes(MfaCredential $credential): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table(MfaRecoveryCode::class) . ' WHERE mfa_id = :id AND used_at IS NULL',
            ['id' => $this->id($credential)],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * 1.50.0: stores a trusted device only while the credential is enabled, under its lock ({@see self::locked()}): a
     * device remembered while the factor is being disabled or re-enrolled never survives that change. False when it
     * was not stored.
     */
    public function insertDevice(MfaCredential $credential, string $publicId, string $tokenHash, string $label, ?string $deviceId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return $this->locked($credential, fn (MfaCredential $locked): bool => $locked->isEnabled() && $this->storeDevice($locked, $publicId, $tokenHash, $label, $deviceId, $now, $expiresAt)) === true;
    }

    private function storeDevice(MfaCredential $credential, string $publicId, string $tokenHash, string $label, ?string $deviceId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): bool
    {
        return (bool) $this->write(fn (Connection $connection): int|string => $connection->insert($this->table(MfaTrustedDevice::class), [
            'public_id'    => $publicId,
            'mfa_id'       => $this->id($credential),
            'token_hash'   => $tokenHash,
            'label'        => $label,
            'device_id'    => $deviceId,
            'created_at'   => $now,
            'last_used_at' => $now,
            'expires_at'   => $expiresAt,
        ], ['mfa_id' => ParameterType::INTEGER, 'created_at' => Types::DATETIME_IMMUTABLE, 'last_used_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]));
    }

    /**
     * Swaps the token of a live trusted device of the credential: true when `$tokenHash` was its current token.
     */
    public function rotateDevice(MfaCredential $credential, string $tokenHash, string $newHash, DateTimeImmutable $now): bool
    {
        return $this->write(fn (Connection $connection): int|string => $connection->executeStatement(
            'UPDATE ' . $this->table(MfaTrustedDevice::class) . ' SET token_hash = :new, last_used_at = :now WHERE mfa_id = :id AND token_hash = :hash AND expires_at > :now',
            ['new' => $newHash, 'now' => $now, 'id' => $this->id($credential), 'hash' => $tokenHash],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        )) === 1;
    }

    /**
     * The live trusted devices of a credential, the most recently used first.
     *
     * @return list<array{public_id: string, label: string, device_id: string|null, created_at: DateTimeImmutable, last_used_at: DateTimeImmutable, expires_at: DateTimeImmutable}>
     */
    public function devices(MfaCredential $credential, DateTimeImmutable $now): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT public_id, label, device_id, created_at, last_used_at, expires_at FROM ' . $this->table(MfaTrustedDevice::class) . ' WHERE mfa_id = :id AND expires_at > :now ORDER BY last_used_at DESC, id DESC',
            ['id' => $this->id($credential), 'now' => $now],
            ['id' => ParameterType::INTEGER, 'now' => Types::DATETIME_IMMUTABLE],
        );
        $connection = $this->connection();

        return array_map(static function (array $row) use ($connection): array {
            /** @var DateTimeImmutable $created */
            $created = $connection->convertToPHPValue($row['created_at'], Types::DATETIME_IMMUTABLE);
            /** @var DateTimeImmutable $used */
            $used = $connection->convertToPHPValue($row['last_used_at'], Types::DATETIME_IMMUTABLE);
            /** @var DateTimeImmutable $expires */
            $expires = $connection->convertToPHPValue($row['expires_at'], Types::DATETIME_IMMUTABLE);

            return [
                'public_id'    => (string) $row['public_id'],
                'label'        => (string) $row['label'],
                'device_id'    => $row['device_id'] !== null ? (string) $row['device_id'] : null,
                'created_at'   => $created,
                'last_used_at' => $used,
                'expires_at'   => $expires,
            ];
        }, $rows);
    }

    /**
     * Deletes trusted devices of a credential: one (`$publicId`), or all. Returns the UserKit device ids they had.
     *
     * @return list<string|null> one entry per deleted device
     */
    public function deleteDevices(MfaCredential $credential, ?string $publicId = null): array
    {
        $table  = $this->table(MfaTrustedDevice::class);
        $where  = 'mfa_id = :id' . ($publicId !== null ? ' AND public_id = :public' : '');
        $params = ['id' => $this->id($credential)] + ($publicId !== null ? ['public' => $publicId] : []);
        $ids    = $this->connection()->fetchFirstColumn('SELECT device_id FROM ' . $table . ' WHERE ' . $where, $params, ['id' => ParameterType::INTEGER]);
        $this->write(static fn (Connection $connection): int|string => $connection->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $where, $params, ['id' => ParameterType::INTEGER]));

        return array_map(static fn (mixed $id): ?string => $id !== null ? (string) $id : null, $ids);
    }

    /**
     * Deletes the credential with its recovery codes and trusted devices by DBAL statements only, never flushing
     * (1.36, personal-data eraser: runs inside UserKit's erasure transaction, which flushes once). The credential is
     * detached from the unit of work so the final flush does not write it back.
     */
    public function deleteWithoutFlush(MfaCredential $credential): void
    {
        $id = $this->id($credential);
        $this->write(function (Connection $connection) use ($id): void {
            foreach ([MfaRecoveryCode::class, MfaTrustedDevice::class] as $class) {
                $connection->executeStatement('DELETE FROM ' . $this->table($class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
            }
            $connection->executeStatement('DELETE FROM ' . $this->table(MfaCredential::class) . ' WHERE id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
        });
        $em = $this->entityManager();
        if ($em->contains($credential)) {
            // @igor-ignore - Unit of work of the entity manager (cleared per request by Doctrine's reset), not worker state.
            $em->detach($credential);
        }
    }

    /**
     * Deletes the credential with its recovery codes and trusted devices (user removal / personal data).
     */
    public function delete(MfaCredential $credential): void
    {
        $id = $this->id($credential);
        $em = $this->entityManager();
        // 1.50.0: one unit of work of AuthKit's runner (the credential row is flushed inside its transaction).
        $this->transactional(function () use ($id, $em, $credential): void {
            $this->write(function (Connection $connection) use ($id): void {
                $connection->executeStatement('DELETE FROM ' . $this->table(MfaRecoveryCode::class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
                $connection->executeStatement('DELETE FROM ' . $this->table(MfaTrustedDevice::class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
            });
            // @igor-ignore - Unit of work of the entity manager (cleared per request by Doctrine's reset), not worker state.
            $em->remove($credential);
        });
    }

    private function id(MfaCredential $credential): int
    {
        return $credential->getId() ?? throw new LogicException('The MFA credential is not persisted.');
    }

    /**
     * Whether the MFA tables exist: a host that never enabled MFA may not have created them (GY-L5).
     */
    public function hasTables(): bool
    {
        return $this->connection()->createSchemaManager()->tablesExist([$this->table(MfaCredential::class), $this->table(MfaRecoveryCode::class), $this->table(MfaTrustedDevice::class)]);
    }

    private function table(string $class): string
    {
        return $this->entityManager()->getClassMetadata($class)->getTableName();
    }

    /**
     * A DBAL write: in a savepoint inside an open transaction with the private entity manager (1.46.0), exactly as
     * before otherwise (the statement runs on the same connection).
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

    /**
     * 1.50.0: a unit of work of AuthKit's transaction runner on the manager of the MFA entities.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        return (new AuthKitTransactionRunner($this->entityManager(), hostFlush: $this->hostFlush))->run($work);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        $em = $this->doctrine->getManagerForClass(MfaCredential::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new LogicException('The AuthKit MFA entities are not mapped (doctrine.register_mapping).');
        }

        return $em;
    }
}
