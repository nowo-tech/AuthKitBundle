<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * A host listener on `LoginSuccessEvent` using a common idiom: insert, and ignore a duplicate key.
 */
final class SwallowingDatabaseListener
{
    public const TABLE = 'swallow_probe';

    public bool $armed = false;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function onLoginSuccess(): void
    {
        if (!$this->armed) {
            return;
        }

        try {
            $this->connection->executeStatement('INSERT INTO ' . self::TABLE . ' (id) VALUES (1)');
        } catch (UniqueConstraintViolationException) {
            // Already there: fine, says the host.
        }
    }
}
