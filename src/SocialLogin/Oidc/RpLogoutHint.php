<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use SensitiveParameter;

/**
 * What an RP-initiated logout needs (2.8.0), kept sealed in the session from an OpenID Connect sign-in until the
 * sign-out ({@see RpLogoutSession}): the credential (provider key and client id it was issued for), the user it signed
 * in, the provider's `end_session_endpoint` and the raw ID token for `id_token_hint` (null when it was larger than
 * {@see RpLogoutSession::MAX_ID_TOKEN_BYTES}; the logout then sends `client_id` only).
 */
final readonly class RpLogoutHint
{
    public function __construct(
        public string $provider,
        public string $clientId,
        public string $userIdentifier,
        public string $endSessionEndpoint,
        #[SensitiveParameter]
        public ?string $idToken,
    ) {
    }
}
