<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\AuthKitBundle\Entity\Invitation;
use Nowo\AuthKitBundle\Entity\SocialLoginAccount;

use function dirname;
use function getenv;
use function is_string;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * An entity manager mapping the AuthKit entities, with fresh `auth_kit_invitation` and `auth_kit_social_account`
 * tables, on the database of `AUTH_KIT_TEST_DB_URL` (the CI job `real-databases`), else on in-memory SQLite.
 */
final class PersonalDataDatabase
{
    public static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 2) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir() . '/nowo-personal-data-tests/proxies'); // @codeCoverageIgnore
            $config->setProxyNamespace('NowoPersonalDataTestProxies'); // @codeCoverageIgnore
        }
        $url    = getenv('AUTH_KIT_TEST_DB_URL');
        $params = is_string($url) && $url !== ''
            ? (new DsnParser(['mysql' => 'pdo_mysql', 'postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url)
            : ['driver' => 'pdo_sqlite', 'memory' => true];
        $em      = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $classes = [$em->getClassMetadata(Invitation::class), $em->getClassMetadata(SocialLoginAccount::class)];
        $schema  = new SchemaTool($em);
        $schema->dropSchema($classes);
        $schema->createSchema($classes);

        return $em;
    }

    public static function isRealServer(): bool
    {
        $url = getenv('AUTH_KIT_TEST_DB_URL');

        return is_string($url) && $url !== '';
    }
}
