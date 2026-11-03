<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The MFA kernel without CSRF protection: every MFA POST must fail closed (403).
 */
final class MfaNoCsrfTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-nocsrf';

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('framework', ['csrf_protection' => false, 'form' => ['csrf_protection' => false]]);
        $container->loadFromExtension('nowo_auth_kit', ['qr_login' => ['mode' => 'disabled']]);
        $container->loadFromExtension('security', ['firewalls' => ['main' => ['form_login' => ['enable_csrf' => false]]]]);
    }
}
