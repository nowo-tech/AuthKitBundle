<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Transaction;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Nowo\TransactionContract\TransientStorageFailure;
use Throwable;

use function hash;
use function hrtime;
use function in_array;
use function substr;
use function unpack;
use function usleep;

/**
 * 1.50.0 (Beacon spec 239): a database-wide named lock, held around a whole AuthKit unit of work (taken before its
 * transaction begins, released after it ended), for the check-then-insert races a unique key cannot settle:
 *
 * - two accounts for one identifier: the identifier rules (normaliser, case-variant guard of 1.49.0, invitation guard)
 *   are re-checked under the lock, so a concurrent registration, invitation acceptance, social sign-up or
 *   administrator creation of the same identifier waits for the first one's commit and is then refused, whatever the
 *   column's collation or unique key;
 * - one social identity linked twice.
 *
 * The lock is the first thing such a unit waits for, before any row lock (one lock order). Platforms:
 * - MySQL / MariaDB: `GET_LOCK()` / `RELEASE_LOCK()` (session locks, re-entrant);
 * - PostgreSQL: `pg_try_advisory_lock()` polled until the timeout / `pg_advisory_unlock()` (session locks, re-entrant);
 * - others (SQLite: a writing transaction locks the whole database): no lock.
 *
 * A lock not obtained within the timeout is a {@see TransientStorageFailure} (503 + Retry-After). Releasing never hides
 * the unit's own outcome; a release that fails (a connection the runner closed) loses the lock with the session.
 *
 * Inside a transaction the application opened, the lock is released before that transaction commits: the rows the
 * unit wrote stay invisible to a concurrent unit until then (the identifier's unique key, if any, is the remaining
 * guard). Stateless.
 *
 * @internal
 */
final class AuthKitNamedLock
{
    /** Seconds a unit waits for the lock before a TransientStorageFailure. */
    public const TIMEOUT_SECONDS = 5;

    /** Prefix of the MySQL lock names (64 characters at most in total). */
    private const PREFIX = 'nowo_auth_kit:';

    /** PostgreSQL polling interval (microseconds). */
    private const POLL_MICROSECONDS = 20000;

    /**
     * @template T
     *
     * @param Connection $connection The connection of the unit
     * @param string $key What is locked (e.g. the user class and the folded identifier)
     * @param Closure(): T $work The unit of work (it opens and ends its own transaction)
     * @param int $timeoutSeconds Seconds to wait for the lock
     *
     * @return T What the work returned
     */
    public static function hold(Connection $connection, string $key, Closure $work, int $timeoutSeconds = self::TIMEOUT_SECONDS): mixed
    {
        $platform = $connection->getDatabasePlatform();
        if ($platform instanceof AbstractMySQLPlatform) {
            $name = self::name($key);
            if ((int) $connection->fetchOne('SELECT GET_LOCK(?, ?)', [$name, $timeoutSeconds], [ParameterType::STRING, ParameterType::INTEGER]) !== 1) {
                throw new TransientStorageFailure(1);
            }
            $release = static fn (): mixed => $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$name]);
        } elseif ($platform instanceof PostgreSQLPlatform) {
            $id = self::advisoryKey($key);
            self::waitForAdvisoryLock($connection, $id, $timeoutSeconds);
            $release = static fn (): mixed => $connection->fetchOne('SELECT pg_advisory_unlock(?)', [$id], [ParameterType::INTEGER]);
        } else {
            return $work();
        }

        try {
            return $work();
        } finally {
            try {
                $release();
            } catch (Throwable) {
                // The unit's own outcome is what the caller must see; a closed session already dropped the lock.
            }
        }
    }

    /**
     * The MySQL / MariaDB lock name of a key (64 characters at most).
     */
    public static function name(string $key): string
    {
        return self::PREFIX . substr(hash('sha256', $key), 0, 40);
    }

    /**
     * The signed 64-bit key of a PostgreSQL advisory lock.
     */
    public static function advisoryKey(string $key): int
    {
        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', substr(hash('sha256', self::PREFIX . $key, true), 0, 8));

        return $unpacked[1];
    }

    private static function waitForAdvisoryLock(Connection $connection, int $id, int $timeoutSeconds): void
    {
        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;
        while (!self::isTrue($connection->fetchOne('SELECT pg_try_advisory_lock(?)', [$id], [ParameterType::INTEGER]))) {
            if (hrtime(true) >= $deadline) {
                throw new TransientStorageFailure(1);
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    private static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
