<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn\Event;

/**
 * A passkey signed in (`context: login`) or passed a second step (`context: mfa`), 2.4.0. Dispatched after the sign
 * counter was recorded and before the session is signed in.
 */
final class WebauthnCredentialUsedEvent extends WebauthnEvent
{
    public const CONTEXT_LOGIN = 'login';

    public const CONTEXT_MFA = 'mfa';

    public function __construct(
        string $profile,
        string $userType,
        string $userId,
        string $publicId,
        public readonly string $context,
    ) {
        parent::__construct($profile, $userType, $userId, $publicId);
    }
}
