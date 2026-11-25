<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Webauthn;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function method_exists;
use function preg_match;
use function preg_replace;
use function str_contains;
use function trim;

/**
 * The shipped passkey SQL scripts (2.4.0, `src/Resources/sql/webauthn/<platform>.{up,down}.sql`) for the tests: the
 * statements of a script, the script of a connection's platform, and the schema differences left for the passkey
 * table once it ran.
 */
final class WebauthnSqlScript
{
    public static function platform(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof MariaDBPlatform    => 'mariadb',
            $platform instanceof MySQLPlatform      => 'mysql',
            $platform instanceof PostgreSQLPlatform => 'postgresql',
            $platform instanceof SQLitePlatform     => 'sqlite',
            default                                 => throw new RuntimeException('No passkey script for ' . $platform::class),
        };
    }

    /**
     * Runs a script on the connection.
     */
    public static function run(Connection $connection, string $direction): void
    {
        foreach (self::statements(self::platform($connection), $direction) as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * @return list<string>
     */
    public static function statements(string $platform, string $direction): array
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Resources/sql/webauthn/' . $platform . '.' . $direction . '.sql');
        $sql = (string) preg_replace('/^--.*$/m', '', $sql);

        return array_values(array_filter(array_map(trim(...), explode(";\n", $sql)), static fn (string $statement): bool => $statement !== ''));
    }

    /**
     * The statements the schema tool still wants for the passkey table (none when the script matches the mapping).
     */
    public static function differences(EntityManagerInterface $em): string
    {
        $sql = array_values(array_filter(
            (new SchemaTool($em))->getUpdateSchemaSql($em->getMetadataFactory()->getAllMetadata()),
            static fn (string $statement): bool => str_contains($statement, 'webauthn'),
        ));
        if (method_exists(Type::class, 'requiresSQLCommentHint')) {
            // DBAL 3 only: its `(DC2Type:…)` column comments (and, on PostgreSQL, the type restatement that comes with
            // them) are bookkeeping of DBAL 3, not a schema difference; DBAL 4 has none.
            $commented = [];
            foreach ($sql as $statement) {
                if (preg_match('/COMMENT ON COLUMN \S+\.(\w+) IS \'\(DC2Type:/', $statement, $match) === 1) {
                    $commented[] = $match[1];
                }
            }
            $sql = array_filter($sql, static fn (string $statement): bool => !str_contains($statement, 'DC2Type')
                && !(preg_match('/ALTER (\w+) TYPE /', $statement, $match) === 1 && in_array($match[1], $commented, true)));
        }

        return implode("\n", $sql);
    }
}
