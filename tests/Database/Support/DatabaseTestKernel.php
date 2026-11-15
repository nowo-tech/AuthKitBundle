<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Database\Support;

use Composer\InstalledVersions;
use Nowo\AuthKitBundle\Admin\AdminUserCreator;
use Nowo\AuthKitBundle\Doctrine\PrivateEntityManagerSubscriber;
use Nowo\AuthKitBundle\Invitation\InvitationAcceptor;
use Nowo\AuthKitBundle\Repository\InvitationRepository;
use Nowo\AuthKitBundle\Security\UserRegistrar;
use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationsTestKernel;
use Nowo\AuthKitBundle\Transaction\AuthKitTransactionRunner;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function getenv;
use function is_string;
use function md5;
use function str_ends_with;
use function sys_get_temp_dir;
use function version_compare;

use const PHP_VERSION_ID;

/**
 * 1.50.0 (Beacon spec 239): the invitations kernel on the server of `AUTH_KIT_LOAD_DATABASE_URL` (MySQL 8.4 or
 * PostgreSQL 16 in CI, job "Load readiness"), with public ids for the services the load tests and the concurrent
 * writers drive. Environment `*_private` turns `doctrine.private_entity_manager` on.
 */
final class DatabaseTestKernel extends InvitationsTestKernel
{
    /** @var array<string, string> public id => service */
    public const SERVICES = [
        'load.registrar'   => UserRegistrar::class,
        'load.acceptor'    => InvitationAcceptor::class,
        'load.admin'       => AdminUserCreator::class,
        'load.invitations' => InvitationRepository::class,
        'load.runner'      => AuthKitTransactionRunner::SERVICE_ID,
        'load.boundary'    => PrivateEntityManagerSubscriber::class,
    ];

    public static function databaseUrl(): ?string
    {
        $url = getenv('AUTH_KIT_LOAD_DATABASE_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Whether doctrine/dbal 3 is installed (DBAL 4 always nests transactions with savepoints). */
    public static function dbal3(): bool
    {
        return version_compare((string) InstalledVersions::getVersion('doctrine/dbal'), '4.0.0', '<');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/load-database/' . $this->environment . '/' . md5((string) self::databaseUrl()) . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/load-database/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('doctrine', ['dbal' => ['url' => (string) self::databaseUrl()] + (self::dbal3() ? ['use_savepoints' => true] : [])]);
        if (version_compare((string) InstalledVersions::getVersion('doctrine/doctrine-bundle'), '3.0.0', '<') && PHP_VERSION_ID >= 80400) {
            // DoctrineBundle 2 (the DBAL 3 legs): native lazy objects, as DoctrineBundle 3 always uses them.
            $container->loadFromExtension('doctrine', ['orm' => ['enable_native_lazy_objects' => true]]);
        }
        if (str_ends_with($this->environment, '_private')) {
            $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['private_entity_manager' => true]]);
        }
        foreach (self::SERVICES as $id => $service) {
            $container->setAlias($id, $service)->setPublic(true);
        }
    }
}
