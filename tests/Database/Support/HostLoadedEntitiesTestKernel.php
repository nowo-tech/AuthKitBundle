<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support;

use Composer\InstalledVersions;
use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationsTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function md5;
use function sys_get_temp_dir;
use function version_compare;

use const PHP_VERSION_ID;

/**
 * 1.55.0 (Q-W2-1): the invitations kernel (QR login on) with `doctrine.private_entity_manager: true`, on the server of
 * `AUTH_KIT_LOAD_DATABASE_URL` when it is set (MySQL, MariaDB, PostgreSQL), else on its own SQLite file. The AuthKit
 * entities are mapped in the application's manager too, so a test can load a row through it as a host does.
 */
final class HostLoadedEntitiesTestKernel extends InvitationsTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-loaded/' . $this->environment . '/' . md5((string) DatabaseTestKernel::databaseUrl()) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-loaded/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $url = DatabaseTestKernel::databaseUrl();
        if ($url !== null) {
            $container->loadFromExtension('doctrine', ['dbal' => ['url' => $url] + (DatabaseTestKernel::dbal3() ? ['use_savepoints' => true] : [])]);
            if (version_compare((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '3.0.0', '<') && PHP_VERSION_ID >= 80400) {
                $container->loadFromExtension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
            }
        }
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['private_entity_manager' => true]]);
    }
}
