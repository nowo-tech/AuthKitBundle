<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Nowo\AuthKitBundle\Tests\Support\Webauthn\RelyingPartySettings;
use Nowo\AuthKitBundle\Tests\Support\Webauthn\SettingsRelyingPartyProvider;
use Nowo\AuthKitBundle\Webauthn\WebAuthnRelyingPartyProviderInterface;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Passkeys with a host relying party provider (2.6.0): {@see SettingsRelyingPartyProvider} decorates the bundle's
 * {@see WebAuthnRelyingPartyProviderInterface} (the configuration: request host, http://localhost in debug) with the
 * values of a public {@see RelyingPartySettings} service the tests change between requests.
 */
class WebauthnProviderTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-provider';

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->register(RelyingPartySettings::class)->setPublic(true);
        $container->register(SettingsRelyingPartyProvider::class)
            ->setDecoratedService(WebAuthnRelyingPartyProviderInterface::class)
            ->setArguments([new Reference(RelyingPartySettings::class), new Reference('.inner')]);
    }
}
