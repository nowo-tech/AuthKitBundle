<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Mfa\MfaStore;
use Nowo\AuthKitBundle\MultiAccount\AccountVaultStore;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use PHPUnit\Framework\Attributes\Group;

use function sprintf;

/**
 * 2.2.1: the rows AuthKit writes through a DBAL `insert()` (MFA recovery codes and trusted devices, multi-account vault
 * entries) get an id on every server. On PostgreSQL with DBAL 3 the id column is fed by a sequence and has no
 * default: up to 2.2.0 these inserts failed there with a not-null violation, and the vault read a last insert id the
 * connection did not have.
 */
#[Group('database')]
final class SequenceIdInsertTest extends LoadDatabaseTestCase
{
    public function testMfaRecoveryCodesAndTrustedDevicesAreStored(): void
    {
        $store      = new MfaStore($this->get('doctrine', ManagerRegistry::class));
        $now        = new DateTimeImmutable();
        $credential = $store->credentialOrNew('default', new MfaOwner(KernelUser::class, 'ada@example.com'), $now);
        $credential->enable('JBSWY3DPEHPK3PXP', 100, $now);
        $store->save($credential);

        $store->replaceRecoveryCodes($credential, ['hash-1', 'hash-2', 'hash-3'], $now);
        self::assertTrue($store->insertDevice($credential, 'device-public-id', 'device-hash', 'Laptop', null, $now, $now->modify('+30 days')));

        self::assertSame(3, $store->unusedRecoveryCodes($credential));
        self::assertSame(3, $this->rows(sprintf('SELECT COUNT(DISTINCT id) FROM %s', MfaRecoveryCode::TABLE)));
        self::assertSame(1, $this->rows(sprintf('SELECT COUNT(*) FROM %s WHERE id IS NOT NULL', MfaTrustedDevice::TABLE)));
        $this->assertHealthy();
    }

    public function testVaultEntriesAreStoredAndTheirIdsReturned(): void
    {
        $store  = new AccountVaultStore($this->get('doctrine', ManagerRegistry::class));
        $now    = new DateTimeImmutable();
        $first  = $store->insert('public-1', 'default', 'ada@example.com', 'token-hash-1', null, $now, $now->modify('+1 day'));
        $second = $store->insert('public-2', 'default', 'bob@example.com', 'token-hash-2', null, $now, $now->modify('+1 day'));

        self::assertNotSame($first, $second);
        $table = $this->em->getClassMetadata(AccountVaultEntry::class)->getTableName();
        self::assertSame('public-1', $this->connection()->fetchOne(sprintf('SELECT public_id FROM %s WHERE id = %d', $table, $first)));
        self::assertSame('public-2', $this->connection()->fetchOne(sprintf('SELECT public_id FROM %s WHERE id = %d', $table, $second)));
        $this->assertHealthy();
    }
}
