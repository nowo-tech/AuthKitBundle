<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The auth pages kernel with the firewall's `login_link` on AuthKit's check route.
 */
class MagicLinkTestKernel extends AuthKitTestKernel
{
    protected const MODE = 'enabled';

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/magic-link-' . static::MODE . '-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/magic-link-' . static::MODE . '-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('security', ['firewalls' => ['main' => [
            'login_link' => ['check_route' => 'nowo_auth_kit_magic_login_check', 'signature_properties' => ['email']],
        ]]]);
        $container->loadFromExtension('nowo_auth_kit', ['magic_login' => ['mode' => static::MODE]]);
    }
}
