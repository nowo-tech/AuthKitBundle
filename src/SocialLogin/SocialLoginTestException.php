<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin;

use RuntimeException;
use Throwable;

/**
 * A test sign-in could not start (2.7.0, {@see SocialLoginTestStart}). The message never holds a secret; `reason` is
 * one of the constants.
 */
final class SocialLoginTestException extends RuntimeException
{
    /** No {@see SocialLoginTestHandlerInterface} is registered: a test would have nowhere to go. */
    public const REASON_NO_HANDLER = 'no_handler';

    /** `social_login.mode` is not `enabled` for the profile. */
    public const REASON_SOCIAL_DISABLED = 'social_disabled';

    /** No credential with this provider key. */
    public const REASON_UNKNOWN_PROVIDER = 'unknown_provider';

    /** 2.10.0: `saml.mode` is not `enabled` for the profile ({@see \Nowo\AuthKitBundle\Saml\SamlTestStart}). */
    public const REASON_SAML_DISABLED = 'saml_disabled';

    /** The provider could not be reached or described (OpenID Connect discovery failed; 2.10.0: an incomplete SAML row). */
    public const REASON_PROVIDER = 'provider';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
