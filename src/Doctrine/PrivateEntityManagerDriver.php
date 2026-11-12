<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Wraps the driver connections of the private entity manager's connection ({@see PrivateEntityManagerMiddleware}).
 *
 * @internal
 */
final class PrivateEntityManagerDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly AuthKitEntityManager $entityManager,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        return new PrivateEntityManagerConnection(parent::connect($params), $this->entityManager);
    }
}
