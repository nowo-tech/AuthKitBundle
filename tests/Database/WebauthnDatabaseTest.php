<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitEntityManager;
use Nowo\AuthKitBundle\Doctrine\AuthKitManagerRegistry;
use Nowo\AuthKitBundle\Doctrine\AuthKitRows;
use Nowo\AuthKitBundle\Entity\WebauthnCredential;
use Nowo\AuthKitBundle\Mfa\MfaOwner;
use Nowo\AuthKitBundle\Tests\Database\Support\DatabaseTestKernel;
use Nowo\AuthKitBundle\Tests\Database\Support\LoadDatabaseTestCase;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\Entity\KernelUser;
use Nowo\AuthKitBundle\Tests\Support\KernelListeners;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\WebauthnSqlScript;
use Nowo\AuthKitBundle\Webauthn\Base64Url;
use Nowo\AuthKitBundle\Webauthn\Event\WebauthnCredentialRenamedEvent;
use Nowo\AuthKitBundle\Webauthn\WebauthnCredentialRecord;
use Nowo\AuthKitBundle\Webauthn\WebauthnManager;
use Nowo\AuthKitBundle\Webauthn\WebauthnStore;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function microtime;
use function sprintf;

/**
 * Passkeys on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 (2.4.0, REQ-RUNTIME-003 and the shipped SQL scripts):
 *
 * - the sign counter moves once: an assertion that waits on a peer's counter update follows its outcome (refused when
 *   the peer committed the same counter, recorded when it rolled back), and a stale counter is refused;
 * - a rename's conditional UPDATE follows a peer's rename it waited on, and a manager rename inside a REPEATABLE READ
 *   transaction ends with a defined answer after a peer's committed rename (2.5.0, audit ZCH-M1);
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

    /**
     * 2.5.0 (audit ZCH-M1): WebauthnManager::rename() inside the host's REPEATABLE READ transaction, after a peer
     * committed a rename of the same passkey. The plain read answers from the transaction's snapshot (the old label)
     * while the UPDATE sees the peer's label: the rename must end with a defined answer, never loop under the owner
     * lock. MySQL / MariaDB: the locking re-read sees the peer's label and the rename replaces it. PostgreSQL: the
     * UPDATE of a row changed after the snapshot is a serialization failure (a retryable conflict, 409 on the page).
     */
    public function testARenameInARepeatableReadTransactionEndsAfterAPeerCommittedARename(): void
    {
        $this->useWebauthnKernel();
        $record  = $this->passkey('ada@example.com', 'cred-ada');
        $manager = $this->get('load.webauthn', WebauthnManager::class);
        $events  = [];
        KernelListeners::add($this->get('event_dispatcher', EventDispatcherInterface::class), WebauthnCredentialRenamedEvent::class, static function (WebauthnCredentialRenamedEvent $event) use (&$events): void {
            $events[] = [$event->oldLabel, $event->newLabel];
        });
        $user       = (new KernelUser())->setEmail('ada@example.com')->setPassword('unused-password');
        $connection = $this->connection();
        $connection->setTransactionIsolation(TransactionIsolationLevel::REPEATABLE_READ);
        $connection->beginTransaction();
        // The first read takes the transaction's snapshot.
        self::assertSame('Key', $connection->fetchOne(sprintf('SELECT label FROM %s WHERE id = %d', WebauthnCredential::TABLE, $record->id)));
        self::assertSame('OK', $this->otherSession()->execute(sprintf("UPDATE %s SET label = 'Peer' WHERE id = %d", WebauthnCredential::TABLE, $record->id)));

        $started  = microtime(true);
        $conflict = null;
        $answer   = null;
        try {
            $answer = $manager->rename($user, $record->publicId, 'Mine');
        } catch (RetryableException|TransientStorageFailure $failure) {
            $conflict = $failure;
        }
        if (!$conflict instanceof Throwable) {
            $connection->commit();
            self::assertTrue($this->mysql(), 'PostgreSQL refuses the update of a row changed after the snapshot');
            self::assertTrue($answer);
            self::assertSame([['Peer', 'Mine']], $events, 'the label really replaced');
            self::assertSame('Mine', $this->label($record->id));
        } else {
            $connection->rollBack();
            self::assertFalse($this->mysql(), 'MySQL / MariaDB re-read with a locking read: ' . $conflict->getMessage());
            self::assertSame([], $events);
            self::assertSame('Peer', $this->label($record->id));
        }
        self::assertLessThan(10.0, microtime(true) - $started, 'a defined answer, no loop');
        $connection->setTransactionIsolation($this->mysql() ? TransactionIsolationLevel::REPEATABLE_READ : TransactionIsolationLevel::READ_COMMITTED);
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

    /**
     * 2.6.0, audit ZCQ-M3 (REQ-RUNTIME-003 lost savepoint): the passkey insert runs in AuthKit's savepoint inside the
     * host's transaction and deadlocks with a peer. MySQL / MariaDB roll the whole transaction back and drop the
     * savepoint: the caller gets the original deadlock (never "SAVEPOINT does not exist") and DBAL is back at level 0
     * (or closed), so nothing the host does next commits silently. PostgreSQL keeps the transaction: the savepoint rolls
     * back and the host's transaction goes on. If the peer is the victim instead, the insert succeeds.
     */
    public function testADeadlockInsideThePasskeyInsertRethrowsTheDeadlockAndLeavesNoStaleTransaction(): void
    {
        $this->useWebauthnKernel();
        $doctrine = $this->get('doctrine', ManagerRegistry::class);
        $private  = new AuthKitEntityManager($doctrine);
        $private->live();
        $store      = new WebauthnStore(new AuthKitManagerRegistry($doctrine, $private));
        $connection = $private->getConnection();
        $rawId      = 'cred-deadlock';
        $peer       = $this->otherSession();
        self::assertSame('OK', $peer->execute('BEGIN'));
        $this->ballast();
        // An explicit id: with DBAL 3 the PostgreSQL id comes from a sequence the raw INSERT would not call.
        self::assertSame('OK', $peer->execute(sprintf(
            "INSERT INTO %s (id, public_id, profile, user_type, user_id, credential_id_hash, credential_id, user_handle, public_key, sign_count, transports, label, created_at) VALUES (900001, 'peer', 'default', 'x', 'peer', '%s', '%s', 'h', 'k', 0, '[]', 'Peer', '2026-11-27 10:00:00')",
            WebauthnCredential::TABLE,
            WebauthnStore::credentialIdHash($rawId),
            Base64Url::encode($rawId),
        )));

        $connection->beginTransaction();
        $connection->executeStatement('INSERT INTO load_ballast (id) VALUES (1000)');
        // The peer then wants the ballast key the host transaction holds, while the insert waits on the peer's key.
        $peer->send($this->sleepStatement(1.0));
        $peer->send('INSERT INTO load_ballast (id) VALUES (1000)');

        $failure = null;
        try {
            $stored = $store->insert('p-deadlock', 'default', new MfaOwner(KernelUser::class, 'ada@example.com'), $rawId, 'handle', 'key', 0, [], null, null, null, 'Key', new DateTimeImmutable(), 'example.com');
            self::assertTrue($stored, 'the peer lost the deadlock and rolled back');
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $level = $connection->isConnected() ? $connection->getTransactionNestingLevel() : 0;
        // The host ends its transaction (PostgreSQL: still open, and the peer waits on its ballast key).
        if ($connection->isConnected() && $connection->isTransactionActive()) {
            $connection->rollBack();
        }
        self::assertSame('OK', $peer->await(), 'the sleep');
        $peerAnswer = $peer->await();
        if ($failure instanceof Throwable) {
            self::assertTrue($this->causedByDeadlock($failure), 'the original deadlock, never the lost savepoint: ' . $failure->getMessage());
            self::assertStringStartsWith('OK', $peerAnswer, 'the peer won');
            self::assertSame($this->mysql() ? 0 : 1, $level, $this->mysql() ? 'no stale transaction level after the lost savepoint' : 'PostgreSQL kept the host transaction');
        } else {
            self::assertMatchesRegularExpression('/^ERR (1213|\\d+ 40P01)/', $peerAnswer);
        }
        self::assertSame('OK', $peer->execute('ROLLBACK'));
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM load_ballast WHERE id = 1000'), 'nothing of the host transaction was committed');
        $this->assertHealthy();
    }

    private function useWebauthnKernel(): void
    {
        $this->kernel->shutdown();
        $this->kernel = new DatabaseTestKernel('test_webauthn', true);
        $this->kernel->boot();
        $this->em = $this->get('doctrine.orm.entity_manager', EntityManagerInterface::class);
    }

    private function label(int $id): string
    {
        return (string) $this->connection()->fetchOne(sprintf('SELECT label FROM %s WHERE id = %d', WebauthnCredential::TABLE, $id));
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
