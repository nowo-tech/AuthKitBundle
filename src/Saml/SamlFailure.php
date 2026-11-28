<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Saml;

use RuntimeException;

/**
 * A refused SAML sign-in (2.10.0). `reason` is one of the constants (logged and dispatched by code); the message never
 * holds response, assertion, NameID or attribute data, and no library exception is chained (theirs quote values).
 */
final class SamlFailure extends RuntimeException
{
    /** No pending request of this session for the provider: an IdP-initiated or foreign response. */
    public const UNSOLICITED = 'unsolicited';

    /** RelayState does not match the pending request (the pending request is left untouched). */
    public const INVALID_STATE = 'invalid_state';

    public const EXPIRED_REQUEST = 'expired_request';

    /** The pending request was already used by a parallel request. */
    public const REPLAYED = 'replayed';

    public const MALFORMED = 'malformed';

    /** The IdP answered a status other than Success. */
    public const STATUS = 'status';

    /** No signature on the Response or the Assertion, an invalid one, a wrapped document or a SHA-1 algorithm. */
    public const SIGNATURE = 'signature';

    public const IN_RESPONSE_TO = 'in_response_to';

    public const DESTINATION = 'destination';

    /** No bearer SubjectConfirmationData with this request id, the ACS as Recipient and a live NotOnOrAfter. */
    public const SUBJECT_CONFIRMATION = 'subject_confirmation';

    public const AUDIENCE = 'audience';

    public const ISSUER = 'issuer';

    /** Outside NotBefore / NotOnOrAfter / SessionNotOnOrAfter (with the clock skew). */
    public const TIMING = 'timing';

    public const ENCRYPTION_REQUIRED = 'encryption_required';

    public const DECRYPTION = 'decryption';

    /** No subject attribute and a missing, transient or unusable NameID. */
    public const UNUSABLE_SUBJECT = 'unusable_subject';

    public const MISSING_EMAIL = 'missing_email';

    /** SAML off for the profile, or the provider unknown, disabled or incomplete. */
    public const UNAVAILABLE = 'unavailable';

    public const REASONS = [
        self::UNSOLICITED, self::INVALID_STATE, self::EXPIRED_REQUEST, self::REPLAYED, self::MALFORMED, self::STATUS,
        self::SIGNATURE, self::IN_RESPONSE_TO, self::DESTINATION, self::SUBJECT_CONFIRMATION, self::AUDIENCE,
        self::ISSUER, self::TIMING, self::ENCRYPTION_REQUIRED, self::DECRYPTION, self::UNUSABLE_SUBJECT,
        self::MISSING_EMAIL, self::UNAVAILABLE,
    ];

    public function __construct(
        public readonly string $reason,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'SAML sign-in refused: ' . $reason . '.');
    }
}
