<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Kernel;

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * REQ-SF-004 rule 7: same bundles and configuration as AuthKitTestKernel, but the routing is only the Symfony
 * default `controllers: { resource: routing.controllers }` (no import of the bundle routing.yaml).
 */
final class HostControllersRoutesKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-controllers-' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/host-controllers-' . $this->environment . '/log';
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('routing.controllers');
    }
}
