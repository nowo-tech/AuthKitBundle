<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Audit QV-L1: `otp_input.enabled` with nowo-tech/otp-input-bundle installed (its classes load) but NowoOtpInputBundle
 * not registered: no `nowo_otp_input` asset package and no OtpInput form theme.
 */
final class MfaOtpInputUnregisteredTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-otp-input-unregistered';

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'otp_input' => ['enabled' => true, 'password_reset_code' => false, 'mfa_code_autosubmit' => true],
        ]);
    }
}
