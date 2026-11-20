<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;

/**
 * Gives the identity columns of {@see IdentityCollationListener::FIELDS} a folding collation, like a table created
 * before 1.47.0 on the server default (`utf8mb4_unicode_ci` on MySQL / MariaDB, `NOCASE` on SQLite).
 */
final readonly class FoldingCollationListener
{
    /**
     * @param non-empty-string $collation
     */
    public function __construct(private string $collation)
    {
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $columns = PasswordResetCollationListener::mappedColumns($args->getEntityManager(), $args->getSchema(), IdentityCollationListener::FIELDS);
        PasswordResetCollationListener::collateSchema($args, $columns, $this->collation);
    }
}
