<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use RuntimeException;
use Throwable;

use function sprintf;

/**
 * An OpenID Connect sign-in that cannot go on (2.3.0). A RuntimeException, so the social callback takes its failure
 * path (flash `social.error.failed`, the host handler's handleFailure()) and logs the class only.
 *
 * The message names the failed check and never carries a token, a secret, a claim value or a URL query: it is safe in
 * a log. {@see self::$reason} is one of the REASON_* constants, for tests and host diagnostics.
 */
final class OidcException extends RuntimeException
{
    public const REASON_CONFIGURATION    = 'configuration';
    public const REASON_ENDPOINT         = 'endpoint_not_allowed';
    public const REASON_HTTP             = 'http';
    public const REASON_RESPONSE_TOO_BIG = 'response_too_large';
    public const REASON_DISCOVERY        = 'discovery';
    public const REASON_JWKS             = 'jwks';
    public const REASON_TOKEN_RESPONSE   = 'token_response';
    public const REASON_ID_TOKEN         = 'id_token';
    public const REASON_SIGNATURE        = 'signature';
    public const REASON_CLAIMS           = 'claims';
    public const REASON_NONCE            = 'nonce';
    public const REASON_USERINFO         = 'userinfo';
    public const REASON_CLIENT_SECRET    = 'client_secret';

    public function __construct(
        public readonly string $reason,
        string $detail,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('OpenID Connect sign-in refused (%s): %s', $reason, $detail), 0, $previous);
    }
}
