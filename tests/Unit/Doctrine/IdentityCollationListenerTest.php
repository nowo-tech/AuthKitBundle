<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\PasswordReset\Entity\PasswordResetRequest;
use Nowo\AuthKitBundle\Tests\Support\SchemaShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

use function array_keys;
use function dirname;

/**
 * REQ-SEC-006 (1.47.0): the identity columns of the MFA, social, vault and invitation tables (and, NV-M1, of the reset
 * table) get the binary NO PAD collation on MySQL / MariaDB, and the PAD SPACE fallback (MySQL 5.7, or a server_version
 * without the patch number) is logged. Audit XI (1.54.1): applied on postGenerateSchema, keeping every foreign key and
 * index of every table.
 */
final class IdentityCollationListenerTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string|null, int}> */
    public static function platforms(): iterable
    {
        yield 'MariaDB 11.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '11.4.0-MariaDB'], 'utf8mb4_nopad_bin', 0];
        yield 'MySQL 8.4' => [['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], 'utf8mb4_0900_bin', 0];
        yield 'SQLite' => [['driver' => 'pdo_sqlite', 'memory' => true], null, 0];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('platforms')]
    public function testEveryColumnIsCollatedAndEveryForeignKeyAndIndexIsKept(array $params, ?string $collation, int $warnings): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly($warnings))->method('warning')
            ->with(self::stringContains('PAD SPACE'), self::arrayHasKey('table'));
        $with    = SchemaShape::entityManager($params, [new IdentityCollationListener(null, $logger), new PasswordResetCollationListener(null, $logger)]);
        $without = SchemaShape::entityManager($params);
        $schema  = SchemaShape::generate($with);

        self::assertSame(SchemaShape::of(SchemaShape::generate($without)), SchemaShape::of($schema));
        foreach (IdentityCollationListener::FIELDS + [PasswordResetRequest::class => PasswordResetCollationListener::FIELDS] as $class => $fields) {
            $metadata = $with->getClassMetadata($class);
            $table    = $schema->getTable($metadata->getTableName());
            foreach ($fields as $field) {
                $column = $table->getColumn($metadata->getColumnName($field));
                self::assertSame($collation, $column->getPlatformOptions()['collation'] ?? null, $class . '::' . $field);
                self::assertSame($collation === null ? null : 'utf8mb4', $column->getPlatformOptions()['charset'] ?? null);
            }
            self::assertArrayNotHasKey('collation', $table->getColumn('id')->getPlatformOptions());
        }
    }

    public function testAManagerWithoutTheBundleEntitiesIsLeftAlone(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $em = SchemaShape::entityManager(
            ['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'],
            [new IdentityCollationListener(null, $logger), new PasswordResetCollationListener(null, $logger)],
            [dirname(__DIR__, 2) . '/Integration/PasswordReset/Entity'],
        );
        $schema = SchemaShape::generate($em);

        self::assertSame(['reset_users'], array_keys(SchemaShape::of($schema)));
        self::assertArrayNotHasKey('collation', $schema->getTable('reset_users')->getColumn('email')->getPlatformOptions());

        // A manager whose mapping does not cover the bundle namespace at all: every entity is transient there.
        $chain = SchemaShape::entityManager(['driver' => 'pdo_sqlite', 'memory' => true]);
        $chain->getConfiguration()->setMetadataDriverImpl(new MappingDriverChain());
        self::assertSame([], PasswordResetCollationListener::mappedColumns($chain, $schema, IdentityCollationListener::FIELDS));
    }

    /**
     * The helper keeps a foreign key and an index starting on the collated table (none of the bundle tables has one;
     * a table replaced on postGenerateSchemaTable lost them on ORM 3.7–3.8 with DBAL 4.5+), and skips unknown columns.
     */
    public function testCollatingKeepsTheForeignKeysAndIndexesOfTheTable(): void
    {
        $em     = SchemaShape::entityManager(['driver' => 'pdo_mysql', 'serverVersion' => '8.4.0'], [], [dirname(__DIR__, 2) . '/Fixtures/SchemaFk']);
        $schema = SchemaShape::generate($em);
        $before = SchemaShape::of($schema);

        $args = new GenerateSchemaEventArgs($em, $schema);
        PasswordResetCollationListener::collateSchema($args, ['xi_child' => ['missing']], 'utf8mb4_bin');
        self::assertSame($schema, $args->getSchema(), 'Nothing to collate: the schema is kept.');

        PasswordResetCollationListener::collateSchema($args, ['xi_child' => ['owner', 'missing']], 'utf8mb4_bin');
        self::assertSame($before, SchemaShape::of($args->getSchema()));
        self::assertContains('parent_id->xi_parent(id) CASCADE', SchemaShape::of($args->getSchema())['xi_child']['fks']);
        self::assertContains('owner', SchemaShape::of($args->getSchema())['xi_child']['indexes']);
        self::assertSame('utf8mb4_bin', $args->getSchema()->getTable('xi_child')->getColumn('owner')->getPlatformOptions()['collation'] ?? null);
    }

    public function testTheFormerTableEventIsGone(): void
    {
        // 2.0: the postGenerateSchemaTable() no-ops deprecated in 1.54.1 are removed; only postGenerateSchema stays.
        foreach ([IdentityCollationListener::class, PasswordResetCollationListener::class] as $listener) {
            $class = new ReflectionClass($listener);
            self::assertFalse($class->hasMethod('postGenerateSchemaTable'), $listener);
            self::assertTrue($class->hasMethod('postGenerateSchema'), $listener);
        }
    }

    public function testThePasswordResetListenerWarnsOnTheFallbackToo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('server_version'), ['table' => 't']);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_bin', 't', $logger);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_0900_bin', 't', $logger);
        PasswordResetCollationListener::warnOnPadSpace('utf8mb4_bin', 't', null);
    }
}
