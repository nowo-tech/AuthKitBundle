<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\LoadReadiness;

use Nowo\AuthKitBundle\Tests\Integration\Invitation\InvitationsTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * 1.50.0 (Beacon spec 239): the invitations kernel plus a refusing grant. Environment `*_private` turns
 * `doctrine.private_entity_manager` on.
 */
final class LoadReadinessTestKernel extends InvitationsTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/load-readiness/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/load-readiness/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(RefusingGrant::class)->setAutoconfigured(true);
    }
}
