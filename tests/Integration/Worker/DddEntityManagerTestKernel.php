<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Worker;

use Nowo\AuthKitBundle\QrLogin\QrLoginChallengeManager;
use Nowo\AuthKitBundle\Tests\Integration\Kernel\AuthKitTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function sys_get_temp_dir;

/**
 * Audit LD-AK-M1 layout: `auto_mapping: true` on the default manager, a second manager ("bundle") onto which the host
 * also maps the AuthKit entities, `nowo_auth_kit.doctrine.entity_manager: bundle`, and a host-owned "reporting"
 * manager.
 */
final class DddEntityManagerTestKernel extends AuthKitTestKernel
{
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/ddd-em/' . $this->environment . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-auth-kit-tests/ddd-em/' . $this->environment . '/log';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('doctrine', [
            'dbal' => ['connections' => [
                'bundle'    => ['url' => 'sqlite:///%kernel.cache_dir%/bundle.sqlite'],
                'reporting' => ['url' => 'sqlite:///%kernel.cache_dir%/reporting.sqlite'],
            ]],
            'orm' => ['entity_managers' => [
                'default' => ['auto_mapping' => true],
                'bundle'  => ['connection' => 'bundle', 'mappings' => ['AuthKitEntities' => [
                    'type'      => 'attribute',
                    'is_bundle' => false,
                    'dir'       => '%kernel.project_dir%/src/Entity',
                    'prefix'    => 'Nowo\\AuthKitBundle\\Entity',
                ]]],
                'reporting' => ['connection' => 'reporting'],
            ]],
        ]);
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['entity_manager' => 'bundle']]);
        $container->setAlias('test.ddd.qr_login_manager', QrLoginChallengeManager::class)->setPublic(true);
    }
}
