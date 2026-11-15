<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Nowo\AuthKitBundle\Transaction\AuthKitNamedLock;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_shift;
use function str_starts_with;
use function strlen;

/**
 * 1.50.0 (Beacon spec 239): the named lock of the account-creating units, per platform (the real-server behaviour is
 * covered by tests/Database).
 */
final class AuthKitNamedLockTest extends TestCase
{
    /** @var list<string> */
    private array $statements = [];

    public function testWithoutNamedLocksTheWorkRunsAsItIs(): void
    {
        $connection = $this->connection(new SQLitePlatform(), []);

        self::assertSame('done', AuthKitNamedLock::hold($connection, 'key', static fn (): string => 'done'));
        self::assertSame([], $this->statements);
    }

    public function testMySqlTakesAndReleasesASessionLockAroundTheWork(): void
    {
        $connection = $this->connection(new MySQL80Platform(), ['1', '1']);

        $result = AuthKitNamedLock::hold($connection, 'account:App\\User' . "\0" . 'ada@example.com', function (): string {
            self::assertSame(['SELECT GET_LOCK(?, ?)'], $this->statements, 'locked before the unit');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'], $this->statements);
    }

    public function testMySqlLockNamesAreShortAndStable(): void
    {
        $names      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(static function (string $sql, array $params) use (&$names): string {
            $names[] = $params[0];

            return '1';
        });

        AuthKitNamedLock::hold($connection, 'a', static fn (): null => null);
        AuthKitNamedLock::hold($connection, 'a', static fn (): null => null);
        AuthKitNamedLock::hold($connection, 'b', static fn (): null => null);

        self::assertSame($names[0], $names[2], 'the same key, the same lock');
        self::assertNotSame($names[0], $names[4]);
        self::assertTrue(str_starts_with((string) $names[0], 'nowo_auth_kit:'));
        self::assertLessThanOrEqual(64, strlen((string) $names[0]), 'MySQL lock names are at most 64 characters');
    }

    public function testAMySqlLockNotObtainedInTimeIsTransient(): void
    {
        $connection = $this->connection(new MySQL80Platform(), ['0']);

        try {
            AuthKitNamedLock::hold($connection, 'key', static fn (): string => 'must not run', 1);
            self::fail('the lock is held elsewhere');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertSame(['SELECT GET_LOCK(?, ?)'], $this->statements, 'nothing to release');
    }

    public function testTheLockIsReleasedWhenTheWorkFailsAndAFailingReleaseNeverHidesTheOutcome(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());
        $connection->method('fetchOne')->willReturnCallback(function (string $sql): string {
            $this->statements[] = $sql;
            if (str_starts_with($sql, 'SELECT RELEASE_LOCK')) {
                throw new RuntimeException('connection closed');
            }

            return '1';
        });

        $message = null;
        try {
            AuthKitNamedLock::hold($connection, 'key', static function (): never {
                throw new RuntimeException('the unit failed');
            });
        } catch (RuntimeException $failure) {
            $message = $failure->getMessage();
        }
        self::assertSame('the unit failed', $message, 'the unit\'s own failure, not the release\'s');
        self::assertSame(['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'], $this->statements);
    }

    /**
     * Audit QG-M2: a release that fails on a live session would keep the lock held by a worker (kernel reset off) into
     * its next requests; the connection is closed instead, which drops the session's locks.
     */
    public function testAFailingMySqlReleaseClosesTheConnection(): void
    {
        $connection = $this->failingRelease(new MySQL80Platform(), 'SELECT RELEASE_LOCK', '1');
        $connection->expects(self::once())->method('close');

        self::assertSame('done', AuthKitNamedLock::hold($connection, 'key', static fn (): string => 'done'), 'the unit\'s outcome stands');
        self::assertSame(['SELECT GET_LOCK(?, ?)', 'SELECT RELEASE_LOCK(?)'], $this->statements);
    }

    public function testAFailingPostgreSqlUnlockClosesTheConnectionAndKeepsTheUnitsFailure(): void
    {
        $connection = $this->failingRelease(new PostgreSQLPlatform(), 'SELECT pg_advisory_unlock', true);
        $connection->expects(self::once())->method('close');

        $retryAfter = null;
        try {
            AuthKitNamedLock::hold($connection, 'key', static function (): never {
                throw new TransientStorageFailure(1);
            });
        } catch (TransientStorageFailure $failure) {
            $retryAfter = $failure->retryAfterSeconds();
        }
        self::assertSame(1, $retryAfter, 'the unit\'s own failure');
        self::assertSame(['SELECT pg_try_advisory_lock(?)', 'SELECT pg_advisory_unlock(?)'], $this->statements);
    }

    public function testASuccessfulReleaseKeepsTheConnection(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), [true, true]);
        $connection->expects(self::never())->method('close');

        self::assertSame('done', AuthKitNamedLock::hold($connection, 'key', static fn (): string => 'done'));
    }

    private function failingRelease(object $platform, string $release, mixed $locked): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use ($release, $locked): mixed {
            $this->statements[] = $sql;
            if (str_starts_with($sql, $release)) {
                throw new RuntimeException('statement timeout');
            }

            return $locked;
        });

        return $connection;
    }

    public function testPostgreSqlPollsAnAdvisoryLockAndUnlocksIt(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), [false, 't', true]);

        self::assertSame(7, AuthKitNamedLock::hold($connection, 'key', static fn (): int => 7));
        self::assertSame(['SELECT pg_try_advisory_lock(?)', 'SELECT pg_try_advisory_lock(?)', 'SELECT pg_advisory_unlock(?)'], $this->statements);
    }

    public function testAPostgreSqlLockNotObtainedInTimeIsTransient(): void
    {
        $connection = $this->connection(new PostgreSQLPlatform(), [false, false, false, false, false, false, false, false, false, false]);

        $this->expectException(TransientStorageFailure::class);
        AuthKitNamedLock::hold($connection, 'key', static fn (): string => 'must not run', 0);
    }

    public function testAdvisoryKeysAreStableSigned64BitIntegers(): void
    {
        self::assertSame(AuthKitNamedLock::advisoryKey('a'), AuthKitNamedLock::advisoryKey('a'));
        self::assertNotSame(AuthKitNamedLock::advisoryKey('a'), AuthKitNamedLock::advisoryKey('b'));
    }

    /**
     * @param list<mixed> $answers
     */
    private function connection(object $platform, array $answers): Connection&MockObject
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('fetchOne')->willReturnCallback(function (string $sql) use (&$answers): mixed {
            $this->statements[] = $sql;

            return array_shift($answers);
        });

        return $connection;
    }
}
