<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;

use function method_exists;

/**
 * Gives the identity columns of {@see IdentityCollationListener::FIELDS} a folding collation, like a table created
 * before 1.46.1 on the server default (`utf8mb4_unicode_ci` on MySQL / MariaDB, `NOCASE` on SQLite).
 */
final readonly class FoldingCollationListener
{
    /**
     * @param non-empty-string $collation
     */
    public function __construct(private string $collation)
    {
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $table    = $args->getClassTable();
        foreach (IdentityCollationListener::FIELDS[$metadata->getName()] ?? [] as $field) {
            /** @var non-empty-string $column */
            $column = $metadata->getColumnName($field);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = PasswordResetCollationListener::collate($table, $column, $this->collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }
}
