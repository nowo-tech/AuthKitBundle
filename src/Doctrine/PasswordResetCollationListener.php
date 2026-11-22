<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Schema\ColumnEditor;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Psr\Log\LoggerInterface;

use function array_keys;
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
 *
 * Since 1.54.1 (audit XI): applied on `postGenerateSchema`, once the schema tool has added every foreign key, by table
 * name. 1.46.0–1.54.0 replaced the table on `postGenerateSchemaTable` (`setClassTable()`, DBAL 4.5+), and ORM 3.7–3.8
 * then added the table's foreign keys to the replaced object, so any foreign key starting on it (and its index) was
 * lost on MySQL / MariaDB (none exist today: latent). Now:
 *
 * - DBAL 4.5+: the table is rebuilt with its table editor (foreign keys and indexes included) and swapped into a new
 *   schema given back with `GenerateSchemaEventArgs::setSchema()`;
 * - older DBAL: the column's platform options are set in place (no editor exists there).
 *
 * The platform is the one of the schema tool's entity manager; a manager that does not map the entity is left alone.
 * Stateless (worker safe).
 */
final readonly class PasswordResetCollationListener
{
    /** @var list<string> */
    public const FIELDS = ['userId', 'userIdentifier', 'tokenHash', 'codeHash'];

    /** The MySQL 5.7 fallback: binary, but PAD SPACE (`bob` = `bob `). */
    public const PAD_SPACE_FALLBACK = 'utf8mb4_bin';

    /**
     * @param ManagerRegistry|null $doctrine unused since 1.54.1 (the platform comes from the schema tool's entity
     *                                       manager); kept for BC
     */
    public function __construct(
        ?ManagerRegistry $doctrine = null,
        private ?LoggerInterface $logger = null,
    ) {
        unset($doctrine);
    }

    /**
     * REQ-SEC-006 item 8 (1.47.0): `utf8mb4_bin` is chosen for MySQL 5.7, and also when `server_version` lacks the patch
     * number (`8`, `8.0`: Doctrine then cannot pick the MySQL 8 platform). It still ignores trailing spaces, so the
     * fallback is logged with the fix.
     */
    public static function warnOnPadSpace(string $collation, string $table, ?LoggerInterface $logger): void
    {
        if ($collation === self::PAD_SPACE_FALLBACK) {
            $logger?->warning('AuthKit falls back to the PAD SPACE collation utf8mb4_bin for the identity columns of {table}: MySQL 5.7, or a doctrine.dbal server_version without the patch number (set the full version, e.g. "8.4.0", to get utf8mb4_0900_bin).', ['table' => $table]);
        }
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
            $platform instanceof AbstractMySQLPlatform => self::PAD_SPACE_FALLBACK,
            default                                    => null,
        };
    }

    /**
     * Gives the identity and hash columns their exact-match collation in the generated schema, keeping every foreign key
     * and index of the table.
     */
    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $manager = $args->getEntityManager();
        $columns = self::mappedColumns($manager, $args->getSchema(), [PasswordResetRequest::class => self::FIELDS]);
        if ($columns === []) {
            return;
        }
        $collation = self::collationFor($manager->getConnection()->getDatabasePlatform());
        if ($collation === null) {
            return;
        }
        foreach (array_keys($columns) as $table) {
            self::warnOnPadSpace($collation, $table, $this->logger);
        }
        self::collateSchema($args, $columns, $collation);
    }

    /**
     * The columns of `$fields` per table of `$schema`, for the entities `$manager` maps (others are skipped).
     *
     * @param array<class-string, list<string>> $fields entity => fields
     *
     * @return array<string, list<non-empty-string>> table name => columns
     *
     * @internal
     */
    public static function mappedColumns(EntityManagerInterface $manager, Schema $schema, array $fields): array
    {
        $columns = [];
        foreach ($fields as $class => $classFields) {
            if ($manager->getMetadataFactory()->isTransient($class)) {
                continue;
            }
            $metadata = $manager->getClassMetadata($class);
            $table    = $metadata->getTableName();
            if (!$schema->hasTable($table)) {
                continue;
            }
            foreach ($classFields as $field) {
                /** @var non-empty-string $column mapped column names are never empty */
                $column            = $metadata->getColumnName($field);
                $columns[$table][] = $column;
            }
        }

        return $columns;
    }

    /**
     * Gives `$columns` (table name => columns) `$collation` in the schema of `$args`, keeping every foreign key and index:
     * on DBAL 4.5+ each table is rebuilt with its editor and swapped into a new schema ({@see GenerateSchemaEventArgs::setSchema()}),
     * on older DBAL the columns are changed in place.
     *
     * @param array<string, list<non-empty-string>> $columns
     * @param non-empty-string $collation
     *
     * @internal
     */
    public static function collateSchema(GenerateSchemaEventArgs $args, array $columns, string $collation): void
    {
        $schema = $args->getSchema();
        // Table::edit() and Schema::edit() exist only from DBAL 4.5.
        // @phpstan-ignore function.alreadyNarrowedType, function.alreadyNarrowedType
        $editor = method_exists(Table::class, 'edit') && method_exists(Schema::class, 'edit');
        $edited = [];
        foreach ($columns as $name => $tableColumns) {
            $original = $table = $schema->getTable($name);
            foreach ($tableColumns as $column) {
                if ($table->hasColumn($column)) {
                    $table = self::collate($table, $column, $collation, $editor) ?? $table;
                }
            }
            if ($table !== $original) {
                $edited[] = [$original, $table];
            }
        }
        if ($edited === []) {
            return;
        }
        $schemaEditor = $schema->edit();
        foreach ($edited as [$original, $table]) {
            $schemaEditor->dropTable($original->getObjectName())->addTable($table);
        }
        $args->setSchema($schemaEditor->create());
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
