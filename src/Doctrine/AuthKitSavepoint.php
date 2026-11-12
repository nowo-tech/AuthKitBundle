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
 * application's manager (default mode), the write runs exactly as before.
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

        $name = 'nowo_auth_kit_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            $connection->rollbackSavepoint($name);

            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }
}
