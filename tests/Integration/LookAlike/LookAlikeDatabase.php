<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LookAlike;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Nowo\AuthKitBundle\Doctrine\IdentityCollationListener;
use Nowo\AuthKitBundle\Entity\AccountVaultEntry;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\MfaCredential;
use Nowo\AuthKitBundle\Entity\MfaRecoveryCode;
use Nowo\AuthKitBundle\Entity\MfaTrustedDevice;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;
use Nowo\AuthKitBundle\Tests\Support\SingleManagerRegistry;

use function array_map;
use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The MFA, social, vault and invitation tables on the database of `AUTH_KIT_TEST_DB_URL` (CI job `real-databases`:
 * MySQL 8.4, MariaDB 11.4, PostgreSQL), else in-memory SQLite. `folding`: the identity columns keep a folding collation
 * (a table created before 1.46.1: `utf8mb4_unicode_ci` on MySQL / MariaDB, `NOCASE` on SQLite; PostgreSQL compares
 * exactly anyway). Otherwise the schema comes from the bundle's {@see IdentityCollationListener}.
 */
final class LookAlikeDatabase
{
    /** @var list<class-string> */
    public const CLASSES = [MfaCredential::class, MfaRecoveryCode::class, MfaTrustedDevice::class, SocialLoginAccount::class, AccountVaultEntry::class, Invitation::class];

    public static function entityManager(bool $folding): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-look-alike-tests/proxies'); // @codeCoverageIgnore
            $config->setProxyNamespace('NowoLookAlikeTestProxies'); // @codeCoverageIgnore
        }
        $url    = getenv('AUTH_KIT_TEST_DB_URL');
        $params = is_string($url) && $url !== ''
            ? (new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url)
            : ['driver' => 'pdo_sqlite', 'memory' => true];
        $em       = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $platform = $em->getConnection()->getDatabasePlatform();
        $classes  = array_map($em->getClassMetadata(...), self::CLASSES);
        $tool     = new SchemaTool($em);
        $tool->dropSchema($classes);
        if (!$folding) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new IdentityCollationListener(new SingleManagerRegistry($em)));
        } elseif ($platform instanceof AbstractMySQLPlatform) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new FoldingCollationListener('utf8mb4_unicode_ci')); // @codeCoverageIgnore
        } elseif ($platform instanceof SQLitePlatform) {
            $em->getEventManager()->addEventListener(ToolEvents::postGenerateSchemaTable, new FoldingCollationListener('NOCASE'));
        }
        $tool->createSchema($classes);

        return $em;
    }

    public static function isMysql(EntityManagerInterface $em): bool
    {
        return $em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    /** A new entity manager on the same connection (a failed flush closes the old one). */
    public static function reconnect(EntityManagerInterface $em): EntityManagerInterface
    {
        return new EntityManager($em->getConnection(), $em->getConfiguration());
    }
}
