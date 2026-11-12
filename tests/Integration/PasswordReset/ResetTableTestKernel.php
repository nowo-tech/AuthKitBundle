<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PasswordReset;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with `password_reset.storage: table` (1.46.0) and a table prefix.
 */
final class ResetTableTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/reset-table/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/reset-table/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'password_reset' => ['storage' => 'table'],
            'doctrine'       => ['table_prefix' => 'app_', 'register_mapping' => 'auto'],
        ]);
    }
}
