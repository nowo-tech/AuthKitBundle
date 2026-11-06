<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Nowo\AuthKitBundle\Tests\Integration\Mfa\MfaTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * MFA kernel with `web_ui.templates.mfa_account` and `mfa_account_codes` pointing at host templates.
 */
final class MfaHostTemplatesTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-host-templates';

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('twig', ['paths' => ['%kernel.project_dir%/tests/Integration/HostExtensions/templates' => null]]);
        $container->loadFromExtension('nowo_auth_kit', ['web_ui' => ['templates' => [
            'mfa_account'       => 'host/mfa_account.html.twig',
            'mfa_account_codes' => 'host/mfa_account_codes.html.twig',
        ]]]);
    }
}
