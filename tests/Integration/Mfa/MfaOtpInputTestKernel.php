<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

use Nowo\OtpInputBundle\NowoOtpInputBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The MFA kernel with nowo-tech/otp-input-bundle registered and `otp_input.enabled` (1.51): the authenticator code of
 * the challenge, setup and account forms renders as OTP boxes, with autosubmit on.
 */
final class MfaOtpInputTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-otp-input';

    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new NowoOtpInputBundle();
    }

    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);
        $container->loadFromExtension('nowo_auth_kit', [
            'otp_input' => ['enabled' => true, 'mfa_code_autosubmit' => true],
        ]);
    }
}
