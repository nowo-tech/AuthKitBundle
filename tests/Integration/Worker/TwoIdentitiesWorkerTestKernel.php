<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * The standard test kernel plus `/whoami` (the signed-in identifier), for the two-identity worker test.
 */
final class TwoIdentitiesWorkerTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/two-identities/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/two-identities/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(WhoAmIController::class)->setAutowired(true)->setPublic(true)->addTag('controller.service_arguments');
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        parent::configureRoutes($routes);
        $routes->add('worker_whoami', '/whoami')->controller(WhoAmIController::class);
    }
}
