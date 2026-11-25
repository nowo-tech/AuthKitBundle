<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

/**
 * A parent-domain RP id without allowed origins (audit ZBX-M1): `rp_id: example.com`, so the only expected origin is
 * `https://example.com`, whatever `Host` a request names.
 */
final class WebauthnParentDomainTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-parent-domain';

    protected const WEBAUTHN = ['rp_id' => 'example.com'];
}
