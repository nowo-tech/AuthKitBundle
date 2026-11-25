<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;

use function array_diff;
use function implode;
use function sprintf;

/**
 * `nowo_auth_kit.oidc` (2.3.0): verification and fetch limits of the OpenID Connect providers. Immutable.
 */
final readonly class OidcOptions
{
    /**
     * Signature algorithms the bundle can verify. `none` and the HMAC family (HS*) are never accepted: an ID token is
     * verified with the provider's published public keys only, so a token "signed" with a public key as an HMAC
     * secret, or not signed at all, can never pass.
     *
     * @var list<string>
     */
    public const SUPPORTED_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'ES256', 'ES384', 'ES512'];

    public const DEFAULT_CLOCK_SKEW            = 60;
    public const DEFAULT_MAX_ID_TOKEN_AGE      = 600;
    public const DEFAULT_METADATA_CACHE_TTL    = 3600;
    public const DEFAULT_JWKS_CACHE_TTL        = 3600;
    public const DEFAULT_JWKS_REFRESH_INTERVAL = 60;
    public const DEFAULT_MAX_RESPONSE_BYTES    = 524288;

    /** @var list<string> */
    public array $allowedAlgorithms;

    /**
     * @param list<string> $allowedAlgorithms
     * @param float $timeout idle timeout of every OIDC request (nowo_auth_kit.http_client.timeout)
     * @param float $maxDuration total time limit of every OIDC request (nowo_auth_kit.http_client.max_duration)
     */
    public function __construct(
        array $allowedAlgorithms = self::SUPPORTED_ALGORITHMS,
        public int $clockSkew = self::DEFAULT_CLOCK_SKEW,
        public int $maxIdTokenAge = self::DEFAULT_MAX_ID_TOKEN_AGE,
        public int $metadataCacheTtl = self::DEFAULT_METADATA_CACHE_TTL,
        public int $jwksCacheTtl = self::DEFAULT_JWKS_CACHE_TTL,
        public int $jwksRefreshInterval = self::DEFAULT_JWKS_REFRESH_INTERVAL,
        public int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
        public float $timeout = 10.0,
        public float $maxDuration = 20.0,
    ) {
        $unsupported = array_diff($allowedAlgorithms, self::SUPPORTED_ALGORITHMS);
        if ($allowedAlgorithms === [] || $unsupported !== []) {
            throw new InvalidArgumentException(sprintf('The OIDC algorithm allow-list must be a non-empty subset of %s ("none" and HS* are never accepted); got: %s.', implode(', ', self::SUPPORTED_ALGORITHMS), implode(', ', $allowedAlgorithms)));
        }
        $this->allowedAlgorithms = $allowedAlgorithms;
    }
}
