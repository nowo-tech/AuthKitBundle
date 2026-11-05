<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Mfa;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;

use function array_map;

/**
 * Persistence of the second factor (1.34). The credential goes through the ORM (its secret is `#[Encrypted]`);
 * every security-relevant check-and-set (replay step, single-use recovery codes, trusted-device tokens) is one
 * conditional DBAL statement, so concurrent requests cannot use a code twice. Table names come from the (prefixed)
 * metadata. Stateless (worker safe).
 */
final readonly class MfaStore
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function credential(string $profile, MfaOwner $owner): ?MfaCredential
    {
        return $this->entityManager()->getRepository(MfaCredential::class)->findOneBy([
            'profile'  => $profile,
            'userType' => $owner->type,
            'userId'   => $owner->id,
        ]);
    }

    /**
     * Every credential of an owner, in every profile (1.36, personal data: also a profile whose MFA was turned off
     * after enrolment, or that the user class no longer resolves to).
     *
     * @return list<MfaCredential>
     */
    public function credentialsOf(MfaOwner $owner): array
    {
        return $this->entityManager()->getRepository(MfaCredential::class)->findBy([
            'userType' => $owner->type,
            'userId'   => $owner->id,
        ], ['id' => 'ASC']);
    }

    public function credentialOrNew(string $profile, MfaOwner $owner, DateTimeImmutable $now): MfaCredential
    {
        $credential = $this->credential($profile, $owner);
        if ($credential instanceof MfaCredential) {
            return $credential;
        }
        $credential = new MfaCredential($profile, $owner->type, $owner->id, $now);
        $this->save($credential);

        return $credential;
    }

    public function save(MfaCredential $credential): void
    {
        $em = $this->entityManager();
        $em->persist($credential);
        $em->flush();
    }

    /**
     * Accepts a TOTP time step once: true only when it is greater than the last accepted step (replay protection).
     */
    public function claimStep(MfaCredential $credential, int $step): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(MfaCredential::class) . ' SET last_used_step = :step WHERE id = :id AND totp_secret IS NOT NULL AND (last_used_step IS NULL OR last_used_step < :step)',
            ['step' => $step, 'id' => $this->id($credential)],
            ['step' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        ) === 1;
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
        $this->connection()->transactional(static function (Connection $connection) use ($id, $table, $hashes, $now): void {
            $connection->executeStatement('DELETE FROM ' . $table . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
            foreach ($hashes as $hash) {
                $connection->insert($table, ['mfa_id' => $id, 'code_hash' => $hash, 'created_at' => $now], ['mfa_id' => ParameterType::INTEGER, 'created_at' => Types::DATETIME_IMMUTABLE]);
            }
        });
    }

    /**
     * Consumes a recovery code: true when it existed and was unused.
     */
    public function useRecoveryCode(MfaCredential $credential, string $hash, DateTimeImmutable $now): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(MfaRecoveryCode::class) . ' SET used_at = :now WHERE mfa_id = :id AND code_hash = :hash AND used_at IS NULL',
            ['now' => $now, 'id' => $this->id($credential), 'hash' => $hash],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        ) === 1;
    }

    public function unusedRecoveryCodes(MfaCredential $credential): int
    {
        return (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->table(MfaRecoveryCode::class) . ' WHERE mfa_id = :id AND used_at IS NULL',
            ['id' => $this->id($credential)],
            ['id' => ParameterType::INTEGER],
        );
    }

    public function insertDevice(MfaCredential $credential, string $publicId, string $tokenHash, string $label, ?string $deviceId, DateTimeImmutable $now, DateTimeImmutable $expiresAt): void
    {
        $this->connection()->insert($this->table(MfaTrustedDevice::class), [
            'public_id'    => $publicId,
            'mfa_id'       => $this->id($credential),
            'token_hash'   => $tokenHash,
            'label'        => $label,
            'device_id'    => $deviceId,
            'created_at'   => $now,
            'last_used_at' => $now,
            'expires_at'   => $expiresAt,
        ], ['mfa_id' => ParameterType::INTEGER, 'created_at' => Types::DATETIME_IMMUTABLE, 'last_used_at' => Types::DATETIME_IMMUTABLE, 'expires_at' => Types::DATETIME_IMMUTABLE]);
    }

    /**
     * Swaps the token of a live trusted device of the credential: true when `$tokenHash` was its current token.
     */
    public function rotateDevice(MfaCredential $credential, string $tokenHash, string $newHash, DateTimeImmutable $now): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . $this->table(MfaTrustedDevice::class) . ' SET token_hash = :new, last_used_at = :now WHERE mfa_id = :id AND token_hash = :hash AND expires_at > :now',
            ['new' => $newHash, 'now' => $now, 'id' => $this->id($credential), 'hash' => $tokenHash],
            ['now' => Types::DATETIME_IMMUTABLE, 'id' => ParameterType::INTEGER],
        ) === 1;
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
        $platform = $this->connection()->getDatabasePlatform();
        $date     = Type::getType(Types::DATETIME_IMMUTABLE);

        return array_map(static function (array $row) use ($date, $platform): array {
            /** @var DateTimeImmutable $created */
            $created = $date->convertToPHPValue($row['created_at'], $platform);
            /** @var DateTimeImmutable $used */
            $used = $date->convertToPHPValue($row['last_used_at'], $platform);
            /** @var DateTimeImmutable $expires */
            $expires = $date->convertToPHPValue($row['expires_at'], $platform);

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
        $this->connection()->executeStatement('DELETE FROM ' . $table . ' WHERE ' . $where, $params, ['id' => ParameterType::INTEGER]);

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
        foreach ([MfaRecoveryCode::class, MfaTrustedDevice::class] as $class) {
            $this->connection()->executeStatement('DELETE FROM ' . $this->table($class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
        }
        $this->connection()->executeStatement('DELETE FROM ' . $this->table(MfaCredential::class) . ' WHERE id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
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
        $this->connection()->executeStatement('DELETE FROM ' . $this->table(MfaRecoveryCode::class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
        $this->connection()->executeStatement('DELETE FROM ' . $this->table(MfaTrustedDevice::class) . ' WHERE mfa_id = :id', ['id' => $id], ['id' => ParameterType::INTEGER]);
        $em = $this->entityManager();
        // @igor-ignore - Unit of work of the entity manager (cleared per request by Doctrine's reset), not worker state.
        $em->remove($credential);
        $em->flush();
    }

    private function id(MfaCredential $credential): int
    {
        return $credential->getId() ?? throw new LogicException('The MFA credential is not persisted.');
    }

    /**
     * @param class-string $class
     */
    private function table(string $class): string
    {
        return $this->entityManager()->getClassMetadata($class)->getTableName();
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
