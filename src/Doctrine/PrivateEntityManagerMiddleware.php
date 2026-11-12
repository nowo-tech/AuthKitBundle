<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\AuthKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;

/**
 * DBAL middleware on the connection of the private entity manager (1.46.0): tells {@see AuthKitEntityManager} when a
 * transaction or a savepoint is rolled back, so a private identity map that may describe rolled-back rows is replaced
 * before the next write (otherwise a second identical save would compute no change and the row would stay lost).
 *
 * Registered only with doctrine/dbal 4 and only on that connection ({@see PrivateEntityManagerPass}).
 *
 * @internal
 */
final readonly class PrivateEntityManagerMiddleware implements Middleware
{
    public function __construct(
        private AuthKitEntityManager $entityManager,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new PrivateEntityManagerDriver($driver, $this->entityManager);
    }
}
