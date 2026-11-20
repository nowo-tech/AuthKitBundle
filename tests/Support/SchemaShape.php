<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Name;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;

use function array_map;
use function dirname;
use function implode;
use function ksort;
use function method_exists;
use function sort;
use function str_replace;
use function strtolower;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * Audit XI (1.54.1): the foreign keys and indexes of a schema, per table, to compare a schema generated with the
 * collation listeners against one generated without them.
 */
final class SchemaShape
{
    /**
     * An entity manager over the bundle entities (or `$paths`) on a connection that is never opened.
     *
     * @param array<string, mixed> $params
     * @param list<object> $listeners postGenerateSchema listeners
     * @param list<string>|null $paths entity directories (null: the bundle's)
     */
    public static function entityManager(array $params, array $listeners = [], ?array $paths = null): EntityManagerInterface
    {
        $root   = dirname(__DIR__, 2);
        $config = ORMSetup::createAttributeMetadataConfig($paths ?? [$root . '/src/Entity', $root . '/src/PasswordReset/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-schema-shape-tests/proxies'); // @codeCoverageIgnore
            $config->setProxyNamespace('NowoSchemaShapeTestProxies'); // @codeCoverageIgnore
        }
        $em = new EntityManager(DriverManager::getConnection($params, $config), $config);
        foreach ($listeners as $listener) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchema, $listener);
        }

        return $em;
    }

    public static function generate(EntityManagerInterface $em): Schema
    {
        return (new SchemaTool($em))->getSchemaFromMetadata($em->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Foreign keys (local columns, foreign table and columns, ON DELETE) and indexes (columns, uniqueness) per table,
     * through the non-deprecated accessors of the installed DBAL.
     *
     * @return array<string, array{fks: list<string>, indexes: list<string>}>
     */
    public static function of(Schema $schema): array
    {
        $shape = [];
        foreach ($schema->getTables() as $table) {
            $fks = [];
            foreach ($table->getForeignKeys() as $fk) {
                $fks[] = self::foreignKey($fk);
            }
            $indexes = [];
            foreach ($table->getIndexes() as $index) {
                $indexes[] = self::index($table, $index);
            }
            // Introspected names come quoted ("user_id", `user_id`); generated ones do not.
            $fks     = str_replace(['"', '`'], '', $fks);
            $indexes = str_replace(['"', '`'], '', $indexes);
            sort($fks);
            sort($indexes);
            // @phpstan-ignore function.alreadyNarrowedType (getObjectName() exists from DBAL 4.3)
            $name                                                  = method_exists($table, 'getObjectName') ? $table->getObjectName()->toString() : $table->getName();
            $shape[strtolower(str_replace(['"', '`'], '', $name))] = ['fks' => $fks, 'indexes' => $indexes];
        }
        ksort($shape);

        return $shape;
    }

    private static function foreignKey(ForeignKeyConstraint $fk): string
    {
        // @phpstan-ignore function.alreadyNarrowedType (getReferencingColumnNames() and friends exist from DBAL 4.3)
        if (method_exists($fk, 'getReferencingColumnNames')) {
            $name = static fn (Name $name): string => $name->toString();
            $on   = $fk->getOnDeleteAction()->value;

            return implode(',', array_map($name, $fk->getReferencingColumnNames())) . '->' . $fk->getReferencedTableName()->toString() . '(' . implode(',', array_map($name, $fk->getReferencedColumnNames())) . ') ' . ($on === 'NO ACTION' ? '-' : $on);
        }

        // @codeCoverageIgnoreStart
        return implode(',', $fk->getLocalColumns()) . '->' . $fk->getForeignTableName() . '(' . implode(',', $fk->getForeignColumns()) . ') ' . ($fk->getOption('onDelete') ?? '-');
        // @codeCoverageIgnoreEnd
    }

    /** `primary `, `unique ` or nothing, then the lower-cased columns. */
    private static function index(Table $table, Index $index): string
    {
        // @phpstan-ignore function.alreadyNarrowedType (getIndexedColumns(), getType() and getPrimaryKeyConstraint() exist from DBAL 4.3)
        if (!method_exists($index, 'getIndexedColumns')) {
            // @codeCoverageIgnoreStart
            return ($index->isPrimary() ? 'primary ' : ($index->isUnique() ? 'unique ' : '')) . implode(',', array_map(strtolower(...), $index->getColumns()));
            // @codeCoverageIgnoreEnd
        }
        $name    = static fn (Name $name): string => strtolower(str_replace(['"', '`'], '', $name->toString()));
        $columns = array_map(static fn (IndexedColumn $column): string => $name($column->getColumnName()), $index->getIndexedColumns());
        $primary = $table->getPrimaryKeyConstraint();
        $unique  = $index->getType() === IndexType::UNIQUE;
        $kind    = $unique && $primary instanceof PrimaryKeyConstraint && array_map($name, $primary->getColumnNames()) === $columns ? 'primary ' : ($unique ? 'unique ' : '');

        return $kind . implode(',', $columns);
    }
}
