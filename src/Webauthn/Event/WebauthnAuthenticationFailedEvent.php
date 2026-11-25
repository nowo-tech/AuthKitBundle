<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

use Nowo\AuthKitBundle\Webauthn\WebauthnFailure;

/**
 * A refused passkey ceremony (2.4.0): `context` is `login`, `mfa` or `register`, `reason` one of the
 * {@see WebauthnFailure} constants (`counter` may mean a cloned authenticator). The owner and the public id are set
 * when the passkey (or the signed-in / pending user) is known, else empty strings. The browser never learns the
 * reason. Not an {@see WebauthnEvent}: there may be no passkey.
 */
final class WebauthnAuthenticationFailedEvent
{
    public function __construct(
        public readonly string $profile,
        public readonly string $context,
        public readonly string $reason,
        public readonly string $userType = '',
        public readonly string $userId = '',
        public readonly string $publicId = '',
    ) {
    }
}
