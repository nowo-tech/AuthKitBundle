<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Invitation;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: the invitations kernel with only the Symfony default `routing.controllers` import.
 */
final class HostControllersInvitationsKernel extends InvitationsTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/invitations-host-' . $this->environment . '/log';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
