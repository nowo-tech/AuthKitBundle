<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\RemovedPorts;

use Nowo\AuthKitBundle\QrLogin\QrLoginStepUpPolicyInterface;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

use function dirname;
use function strtolower;

/**
 * 2.0 (W1-1): the auth pages kernel plus an application that autodiscovers its services (`App\: resource: ../src/`)
 * from one directory of tests/Fixtures/RemovedPorts. AliasedToThePort registers its class explicitly and aliases the
 * port's id to it. One environment (and container class) per fixture.
 */
final class RemovedPortsTestKernel extends AuthKitTestKernel
{
    public function __construct(private readonly string $fixture)
    {
        parent::__construct('test_rp_' . strtolower($fixture), false);
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $namespace = 'Nowo\\AuthKitBundle\\Tests\\Fixtures\\RemovedPorts\\' . $this->fixture . '\\';
        if ($this->fixture === 'AliasedToThePort') {
            $container->register($namespace . 'OldStepUp', $namespace . 'OldStepUp');
            $container->setAlias(QrLoginStepUpPolicyInterface::class, $namespace . 'OldStepUp');

            return;
        }
        $directory = dirname(__DIR__, 2) . '/Fixtures/RemovedPorts/' . $this->fixture;
        (new PhpFileLoader($container, new FileLocator($directory)))
            ->registerClasses((new Definition())->setAutowired(true)->setAutoconfigured(true), $namespace, $directory . '/*');
    }
}
