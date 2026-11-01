<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * SEC-004 CD regression kernel: the invitations kernel with `registration_mode: disabled` with invitations enabled.
 */
final class InvitationsClosedTestKernel extends InvitationsTestKernel
{
    protected const SHOW_LINK = true;

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-closed-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-closed-' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', ['registration_mode' => 'disabled']);
    }
}
