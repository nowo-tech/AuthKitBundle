<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Audit ZCC-L1: an `allowed_origins` entry with an env placeholder whose name has upper-case letters
 * (`https://%env(…)%`); the env var is unset, so the `default:` parameter (an upper-case host) applies.
 */
final class WebauthnEnvOriginTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-env-origin';

    protected const WEBAUTHN = ['allowed_origins' => ['https://%env(default:webauthn_test_host:AUTH_KIT_TEST_WEBAUTHN_HOST)%']];

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        $container->setParameter('webauthn_test_host', 'Login.Example.com:8443');
        parent::configureContainer($container, $loader);
    }
}
