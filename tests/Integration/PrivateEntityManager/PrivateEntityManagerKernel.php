<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\PrivateEntityManager;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Turns `doctrine.private_entity_manager` on in an existing test kernel (1.46.0), with its own cache directory (and
 * SQLite file), so the flow tests of that kernel run unchanged against the private entity manager.
 */
trait PrivateEntityManagerKernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-private-em';
    }

    public function getLogDir(): string
    {
        return parent::getLogDir() . '-private-em';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['private_entity_manager' => true]]);
    }
}
