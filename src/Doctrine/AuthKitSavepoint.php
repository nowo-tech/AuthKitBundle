<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Runs one AuthKit DBAL / bulk DQL write inside a savepoint when a transaction is already open on the connection
 * (1.46.0, private entity manager only, REQ-ARCH-001 rule 6): a failing AuthKit statement then rolls back to the
 * savepoint only, so it never leaves the application's transaction aborted (PostgreSQL) and the application's own COMMIT keeps its rows
 * (audit NC2-1 class). Outside a transaction, on a platform without savepoints, or when the statement goes through the
 * application's manager (default mode), the write runs exactly as before. A lost savepoint (2.6.0, REQ-RUNTIME-003) rolls
 * the entry level back, or closes the connection, and rethrows the original exception.
 *
 * @internal
 */
final class AuthKitSavepoint
{
    /**
     * @template T
     *
     * @param Closure(): T $write
     * @param object|null $manager the manager the statement belongs to: only the private manager gets a savepoint, and it
     *                             records the transaction level the write now waits in (audit NJV-1)
     *
     * @return T
     */
    public static function write(Connection $connection, Closure $write, ?object $manager = null): mixed
    {
        // Default mode (the application's manager): exactly the 1.45 statement, no savepoint.
        if (!$manager instanceof AuthKitEntityManager || !$connection->isTransactionActive()) {
            return $write();
        }

        $result = self::inSavepoint($connection, $write);
        $manager->noteWrite();

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    private static function inSavepoint(Connection $connection, Closure $write): mixed
    {
        $platform = $connection->getDatabasePlatform();
        if (!$platform->supportsSavepoints()) {
            return $write(); // @codeCoverageIgnore
        }

        $name  = 'nowo_auth_kit_' . bin2hex(random_bytes(6));
        $level = $connection->getTransactionNestingLevel();
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            self::rollBackAfterFailure($connection, $name, $level);

            // Always the original exception (a deadlock stays a deadlock for the conflict classifier).
            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }

    /**
     * Rolls back to the savepoint after a failed write. REQ-RUNTIME-003 lost-savepoint pattern (2.6.0, audit ZCQ-M3):
     * when the savepoint is gone (MySQL / MariaDB roll the whole transaction back on a deadlock or a lock-wait timeout),
     * the transaction AuthKit was entered in no longer exists on the server: roll DBAL back out of that level (the
     * entry level) so the caller's later COMMIT cannot silently autocommit what follows, and close the connection when
     * that rollback fails too. The caller then sees the original exception.
     */
    private static function rollBackAfterFailure(Connection $connection, string $name, int $level): void
    {
        try {
            $connection->rollbackSavepoint($name);

            return;
        } catch (Throwable) {
            // The savepoint is lost: below.
        }
        try {
            if ($connection->getTransactionNestingLevel() >= $level) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            $connection->close();
        }
    }
}
