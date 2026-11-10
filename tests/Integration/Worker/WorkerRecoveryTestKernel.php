<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * The standard test kernel plus a second, host-owned entity manager ("reporting") on its own connection. The AuthKit
 * entities stay on the default manager.
 */
final class WorkerRecoveryTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/worker-recovery/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/worker-recovery/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['connections' => ['reporting' => ['url' => 'sqlite:///%kernel.cache_dir%/reporting.sqlite']]],
            'orm'  => ['entity_managers' => ['reporting' => ['connection' => 'reporting']]],
        ]);
        $container->setAlias('test.worker.qr_login_manager', QrLoginChallengeManager::class)->setPublic(true);
    }
}
