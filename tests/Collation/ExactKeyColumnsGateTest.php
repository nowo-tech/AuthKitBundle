<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Collation;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\AuthKitBundle\Doctrine\AuthKitExactKeyColumns;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Doctrine\PasswordResetCollationListener;
use Nowo\AuthKitBundle\Doctrine\TablePrefixListener;
use Nowo\MigrationsKitBundle\Test\ExactKeyColumnsTestCase;

use function dirname;

use const PHP_VERSION_ID;

/**
 * REQ-SEC-006 on a real server (AUTH_KIT_LOAD_DATABASE_URL: MySQL 8.4 and MariaDB 11.4 in CI): the bundle's own gate
 * over its own declaration.
 *
 * The tables are created by the schema tool with the listeners the bundle registers, under a non-empty table prefix,
 * and every declared column is then read back from `information_schema`. An identity column that lost its binary
 * NO PAD collation — or a new one nobody collated — fails *this bundle's* pipeline, long before a host installs it,
 * and the prefix proves the declaration follows the configured table name instead of a literal.
 *
 * Skips cleanly on PostgreSQL and SQLite, which compare exactly by default, and without the variable; it is therefore
 * kept out of `tests/Database`, which CI runs with `--fail-on-skipped`.
 */
final class ExactKeyColumnsGateTest extends ExactKeyColumnsTestCase
{
    /** A non-empty prefix: the declaration must follow the configured table name. */
    private const PREFIX = 'exactkey_';

    /**
     * {@inheritdoc}
     */
    protected function databaseUrlVariable(): string
    {
        return 'AUTH_KIT_LOAD_DATABASE_URL';
    }

    /**
     * {@inheritdoc}
     */
    protected function exactKeyColumnProviders(): array
    {
        return [new AuthKitExactKeyColumns(self::PREFIX, true, true)];
    }

    /**
     * {@inheritdoc}
     */
    protected function createSchema(Connection $connection): void
    {
        $base   = dirname(__DIR__, 2) . '/src';
        $config = ORMSetup::createAttributeMetadataConfiguration([$base . '/Entity', $base . '/PasswordReset/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }

        $events = new EventManager();
        $events->addEventListener(Events::loadClassMetadata, new TablePrefixListener(self::PREFIX));
        $events->addEventListener(ToolEvents::postGenerateSchema, new IdentityCollationListener());
        $events->addEventListener(ToolEvents::postGenerateSchema, new PasswordResetCollationListener());

        $manager = new EntityManager($connection, $config, $events);
        /** @var list<ClassMetadata<object>> $classes */
        $classes = $manager->getMetadataFactory()->getAllMetadata();

        $tool = new SchemaTool($manager);
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
