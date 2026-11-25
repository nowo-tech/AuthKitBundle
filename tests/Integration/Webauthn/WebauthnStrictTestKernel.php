<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

/**
 * Passkeys with tight limits (2.4.0): two options and one assertion per IP and window, three registration options per account and
 * window, `attestation: direct`, explicit allowed origins and a fixed RP id.
 */
final class WebauthnStrictTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-strict';

    protected const WEBAUTHN = [
        'rp_id'           => 'localhost',
        'allowed_origins' => ['http://localhost'],
        'attestation'     => 'direct',
        'rate_limit'      => [
            'options'  => ['limit' => 2, 'window' => 60],
            'assert'   => ['limit' => 1, 'window' => 60],
            'register' => ['limit' => 3, 'window' => 900],
        ],
    ];
}
