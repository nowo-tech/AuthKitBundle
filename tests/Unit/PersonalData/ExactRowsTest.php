<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\PersonalData;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Nowo\AuthKitBundle\PersonalData\ExactRows;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * The SQL `=` only pre-filters: a case-insensitive collation (SQLite `NOCASE` here, like MySQL's default `*_ci`) also
 * returns look-alikes, which the `===` re-check drops.
 */
final class ExactRowsTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE t (id INTEGER PRIMARY KEY, name VARCHAR(180) COLLATE NOCASE, other VARCHAR(10))');
        foreach ([[1, 'UserA', 'x'], [2, 'usera', 'x'], [3, 'USERA', 'y'], [4, 'usera', 'y']] as [$id, $name, $other]) {
            $this->connection->insert('t', ['id' => $id, 'name' => $name, 'other' => $other]);
        }
    }

    public function testACaseInsensitiveCollationDoesNotWidenTheMatch(): void
    {
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM t WHERE name = 'usera'"), 'The collation itself is case-insensitive.');

        $rows = ExactRows::find($this->connection, 't', ['name' => 'usera'], ['other']);

        self::assertSame([2, 4], ExactRows::ids($rows));
        self::assertSame(['x', 'y'], array_column($rows, 'other'));
        self::assertSame([4], ExactRows::ids(ExactRows::find($this->connection, 't', ['name' => 'usera', 'other' => 'y'])));
        self::assertSame([], ExactRows::find($this->connection, 't', ['name' => 'Usera']));
    }

    public function testWritesGoByPrimaryKey(): void
    {
        $ids = ExactRows::ids(ExactRows::find($this->connection, 't', ['name' => 'UserA']));

        self::assertSame(1, ExactRows::writeByIds($this->connection, 'DELETE FROM t WHERE id IN (:ids)', $ids));
        self::assertSame(0, ExactRows::writeByIds($this->connection, 'DELETE FROM t WHERE id IN (:ids)', []));
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM t'));
    }
}
