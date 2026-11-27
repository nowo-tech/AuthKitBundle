<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Webauthn;

use RuntimeException;
use Throwable;

/**
 * A refused WebAuthn ceremony (2.4.0). The reason is for logs and the host's audit
 * ({@see Event\WebauthnAuthenticationFailedEvent}); the browser always gets the same generic answer for every reason
 * of a sign-in, so an unknown credential and another account's credential cannot be told apart.
 */
final class WebauthnFailure extends RuntimeException
{
    /** The response could not be parsed (not a WebAuthn credential of the expected kind). */
    public const MALFORMED = 'malformed';

    /** No live challenge in this session (missing, expired, already used, or used by a parallel request). */
    public const CHALLENGE = 'challenge';

    /** No passkey with this credential id in the profile (or not one of the expected user's). */
    public const UNKNOWN_CREDENTIAL = 'unknown_credential';

    /** The ceremony failed a check (origin, RP id, user verification, signature, user handle, attestation …). */
    public const INVALID = 'invalid';

    /** The sign counter did not move forward: a replay, a parallel use or a cloned authenticator. */
    public const COUNTER = 'counter';

    /** The credential id is already registered. */
    public const DUPLICATE = 'duplicate';

    /** The account already has `webauthn.max_credentials` passkeys. */
    public const LIMIT = 'limit';

    /** Too many attempts in the window. */
    public const RATE_LIMITED = 'rate_limited';

    /** The account cannot be signed in (unknown or disabled user, no usable owner id, firewall without a programmatic login). */
    public const USER = 'user';

    /**
     * 2.6.0: the relying party is unusable (the {@see WebAuthnRelyingPartyProviderInterface} answered an invalid RP id
     * or origins, or failed): passkeys are disabled until it is fixed; the page asks for the password.
     */
    public const RELYING_PARTY = 'relying_party';

    /**
     * 2.6.0: the passkey, or the challenge of this ceremony, belongs to another RP id than the current one (the RP id
     * changed since): the passkey no longer works and must be registered again.
     */
    public const RP_ID_MISMATCH = 'rp_id_mismatch';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : 'The WebAuthn ceremony was refused (' . $reason . ').', 0, $previous);
    }
}
