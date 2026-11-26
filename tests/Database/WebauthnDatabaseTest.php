<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\WebauthnSqlScript;
use Nowo\AuthKitBundle\Webauthn\WebauthnCredentialRecord;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

use function microtime;
use function sprintf;

/**
 * Passkeys on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (2.4.0, REQ-RUNTIME-003 and the shipped SQL scripts):
 *
 * - the sign counter moves once: an assertion that waits on a peer's counter update follows its outcome (refused when
 *   the peer committed the same counter, recorded when it rolled back), and a stale counter is refused;
 * - a rename's conditional UPDATE follows a peer's rename it waited on (2.5.0);
 * - a credential id is registered once (unique digest, `duplicate`);
 * - the exact-key columns tell look-alike owners apart (binary NO PAD collation);
 * - the platform's up script creates exactly the mapped table (collations included) and the down script drops it.
 */
#[Group('database')]
final class WebauthnDatabaseTest extends LoadDatabaseTestCase
{
    /**
     * @return iterable<string, array{bool}>
     */
    public static function outcomes(): iterable
    {
        yield 'the peer commits' => [true];
        yield 'the peer rolls back' => [false];
    }

    #[DataProvider('outcomes')]
    public function testACounterUpdateWaitingOnAPeerFollowsItsOutcome(bool $commit): void
    {
        $record = $this->passkey('ada@example.com', 'cred-ada');
        $peer   = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute(sprintf('UPDATE %s SET sign_count = 5 WHERE id = %d AND sign_count < 5', WebauthnCredential::TABLE, $record->id)));
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $started  = microtime(true);
        $recorded = $this->store()->recordUse($record, 5, true, new DateTimeImmutable());

        self::assertGreaterThan(0.5, microtime(true) - $started, 'the update waited for the peer');
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(!$commit, $recorded);
        self::assertSame(5, $this->rows(sprintf('SELECT sign_count FROM %s WHERE id = %d', WebauthnCredential::TABLE, $record->id)));
        self::assertFalse($this->store()->recordUse($record, 5, true, new DateTimeImmutable()), 'the same counter again');
        self::assertTrue($this->store()->recordUse($record, 6, null, new DateTimeImmutable()));
        $this->assertHealthy();
    }

    /**
     * 2.5.0: a rename whose UPDATE waits on a peer's uncommitted rename follows its outcome. The UPDATE only matches the
     * label it read, so after a committed peer it changes nothing (the manager then reads the row again) and after a
     * rolled-back peer it renames; either way the label it reports replacing is the one that was there.
     */
    #[DataProvider('outcomes')]
    public function testARenameWaitingOnAPeerFollowsItsOutcome(bool $commit): void
    {
        $record = $this->passkey('ada@example.com', 'cred-ada');
        $peer   = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        self::assertSame('OK', $peer->execute(sprintf("UPDATE %s SET label = 'Peer' WHERE id = %d", WebauthnCredential::TABLE, $record->id)));
        $peer->send($this->sleepStatement(1.0));
        $peer->send($commit ? 'COMMIT' : 'ROLLBACK');

        $started = microtime(true);
        $renamed = $this->store()->rename($record, 'Mine');

        self::assertGreaterThan(0.5, microtime(true) - $started, 'the update waited for the peer');
        self::assertSame('OK', $peer->await());
        self::assertSame('OK', $peer->await());
        self::assertSame(!$commit, $renamed);
        $fresh = $this->store()->findByCredentialId('default', 'cred-ada');
        self::assertInstanceOf(WebauthnCredentialRecord::class, $fresh);
        self::assertSame($commit ? 'Peer' : 'Mine', $fresh->label);
        self::assertFalse($this->store()->rename($record, 'Other'), 'a stale label never matches');
        self::assertTrue($this->store()->rename($fresh, 'Other'));
        $this->assertHealthy();
    }

    public function testACredentialIdIsRegisteredOnceAndOwnersCompareExactly(): void
    {
        $this->passkey('ada@example.com', 'cred-ada');
        self::assertFalse($this->store()->insert('p2', 'default', new MfaOwner(KernelUser::class, 'bob@example.com'), 'cred-ada', 'h', 'k', 0, [], null, null, null, 'Key', new DateTimeImmutable()));
        $this->passkey('ADA@example.com', 'cred-upper');
        // A trailing space is not a usable owner id: written directly, as a row from before REQ-SEC-006 would be.
        $this->connection()->insert(WebauthnCredential::TABLE, AuthKitRows::preInsertId($this->em, WebauthnCredential::class) + ['public_id' => 'p-space', 'profile' => 'default', 'user_type' => KernelUser::class, 'user_id' => 'ada@example.com ', 'credential_id_hash' => WebauthnStore::credentialIdHash('cred-space'), 'credential_id' => 'x', 'user_handle' => 'h', 'public_key' => 'k', 'sign_count' => 0, 'transports' => '[]', 'label' => 'Key', 'created_at' => '2026-11-25 10:00:00']);
        self::assertCount(1, $this->store()->credentialsOf('default', new MfaOwner(KernelUser::class, 'ada@example.com')));
        self::assertSame(1, $this->rows(sprintf("SELECT COUNT(*) FROM %s WHERE user_id = 'ada@example.com'", WebauthnCredential::TABLE)), 'the collation itself is exact');
        self::assertNull($this->store()->findByCredentialId('other', 'cred-ada'));
        self::assertNotNull($this->store()->findByCredentialId('default', 'cred-ada'));
        $this->assertHealthy();
    }

    public function testTheShippedScriptsCreateExactlyTheMappedTable(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE ' . WebauthnCredential::TABLE);
        WebauthnSqlScript::run($connection, 'up');
        self::assertSame('', WebauthnSqlScript::differences($this->em), 'no difference with the mapping on ' . WebauthnSqlScript::platform($connection));
        // The identity sequence also serves DBAL 3 (ids from a sequence).
        $this->passkey('ada@example.com', 'cred-ada');
        WebauthnSqlScript::run($connection, 'down');
        self::assertFalse($connection->createSchemaManager()->tablesExist([WebauthnCredential::TABLE]));
        WebauthnSqlScript::run($connection, 'up');
        $this->assertHealthy();
    }

    private function store(): WebauthnStore
    {
        return new WebauthnStore($this->get('doctrine', ManagerRegistry::class));
    }

    private function passkey(string $user, string $rawId): WebauthnCredentialRecord
    {
        self::assertTrue($this->store()->insert('p-' . $rawId, 'default', new MfaOwner(KernelUser::class, $user), $rawId, 'handle', 'key', 4, ['usb'], null, true, false, 'Key', new DateTimeImmutable()));
        $record = $this->store()->findByCredentialId('default', $rawId);
        self::assertInstanceOf(WebauthnCredentialRecord::class, $record);

        return $record;
    }
}
