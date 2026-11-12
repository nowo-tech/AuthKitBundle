<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\XmlMapping;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * {@see MfaTestKernel} with `doctrine.mapping_type: xml` (1.46.0) on an `auto_mapping` default manager: the XML driver
 * replaces the attribute driver auto_mapping adds for the AuthKit entities.
 */
final class XmlMfaTestKernel extends MfaTestKernel
{
    public function getCacheDir(): string
    {
        return parent::getCacheDir() . '-xml';
    }

    public function getLogDir(): string
    {
        return parent::getLogDir() . '-xml';
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('doctrine', ['orm' => ['auto_mapping' => true]]);
        $container->loadFromExtension('nowo_auth_kit', ['doctrine' => ['mapping_type' => 'xml', 'private_entity_manager' => true]]);
    }
}
