<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

/**
 * Passkeys only as a second factor (2.4.0): `passwordless: false`.
 */
final class WebauthnNoPasswordlessTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-no-passwordless';

    protected const WEBAUTHN = ['passwordless' => false];
}
