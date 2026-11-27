<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\Webauthn;

/**
 * The passkey kernel of the worker test (debug off): 2.6.0 accepts `http://localhost` origins only with kernel.debug or
 * `webauthn.allow_http_localhost: true`.
 */
final class WebauthnWorkerTestKernel extends WebauthnTestKernel
{
    public const NAME = 'webauthn-worker';

    protected const WEBAUTHN = ['allow_http_localhost' => true];
}
