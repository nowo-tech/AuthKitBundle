<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Mfa;

/**
 * The MFA kernel with another password field (`mfa.trusted_devices.password_field`, audit DD-I6).
 */
final class MfaPasswordFieldTestKernel extends MfaTestKernel
{
    public const NAME = 'mfa-password-field';

    protected const MFA = ['trusted_devices' => ['password_field' => 'nickname']];
}
