<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

/**
 * A passkey that is not a second factor (audit ZBX-L1): `webauthn.mfa: false` with passwordless sign-in on.
 */
final class WebauthnNotMfaTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-not-mfa';

    protected const WEBAUTHN = ['mfa' => false];
}
