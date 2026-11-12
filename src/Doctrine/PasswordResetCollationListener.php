<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;

use function method_exists;

/**
 * Exact-match collation for the identity and credential columns of `auth_kit_password_reset` on MySQL / MariaDB
 * (1.46.0, audit NV-M1, house rule LQ): `user_id`, `user_identifier`, `token_hash`, `code_hash`.
 *
 * The default MySQL collations are case-, accent- and (PAD SPACE) trailing-space-insensitive, so `jose` = `JOSÉ` =
 * `jose `. The repository already re-checks every row with `===`; the binary collation keeps the database itself exact:
 * MariaDB `utf8mb4_nopad_bin`, MySQL 8.0+ `utf8mb4_0900_bin`, MySQL 5.7 `utf8mb4_bin`. PostgreSQL and SQLite compare
 * exactly and are untouched (they reject MySQL collation names, so it cannot live in the mapping). Applied to the
 * generated schema (schema tool, `doctrine:migrations:diff`). Registered only when a profile uses the reset table.
 */
final readonly class PasswordResetCollationListener
{
    /** @var list<string> */
    public const FIELDS = ['userId', 'userIdentifier', 'tokenHash', 'codeHash'];

    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    /**
     * The exact-match collation on that platform, null when its default is already exact.
     *
     * @return non-empty-string|null
     */
    public static function collationFor(AbstractPlatform $platform): ?string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform       => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform       => 'utf8mb4_0900_bin',
            $platform instanceof AbstractMySQLPlatform => 'utf8mb4_bin',
            default                                    => null,
        };
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if ($metadata->getName() !== PasswordResetRequest::class) {
            return;
        }
        $manager   = $this->doctrine->getManagerForClass(PasswordResetRequest::class);
        $collation = $manager instanceof EntityManagerInterface ? self::collationFor($manager->getConnection()->getDatabasePlatform()) : null;
        if ($collation === null) {
            return;
        }

        $table = $args->getClassTable();
        foreach (self::FIELDS as $field) {
            /** @var non-empty-string $column mapped column names are never empty */
            $column = $metadata->getColumnName($field);
            // @phpstan-ignore function.alreadyNarrowedType (Table::edit() exists only from DBAL 4.5)
            $edited = self::collate($table, $column, $collation, method_exists(Table::class, 'edit'));
            if ($edited instanceof Table) {
                $table = $edited;
            }
        }
        if ($table !== $args->getClassTable()) {
            $args->setClassTable($table);
        }
    }

    /**
     * Gives `$column` the utf8mb4 charset and `$collation`: through a table editor (DBAL 4.5+; returns the new table) or
     * in place (older DBAL; returns null).
     *
     * @param non-empty-string $column
     * @param non-empty-string $collation
     */
    public static function collate(Table $table, string $column, string $collation, bool $editor): ?Table
    {
        if ($editor) {
            return $table->edit()->modifyColumnByUnquotedName($column, static function (ColumnEditor $columnEditor) use ($collation): void {
                $columnEditor->setCharset('utf8mb4')->setCollation($collation);
            })->create();
        }

        $table->getColumn($column)
            ->setPlatformOption('charset', 'utf8mb4')
            ->setPlatformOption('collation', $collation);

        return null;
    }
}
