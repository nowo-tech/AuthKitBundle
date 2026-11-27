<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;

use function array_diff;
use function array_unique;
use function array_values;
use function filter_var;
use function get_debug_type;
use function implode;
use function is_string;
use function preg_match;
use function sprintf;
use function strtolower;

use const FILTER_VALIDATE_IP;

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
    public const DEFAULT_DISCOVERY_FAILURE_TTL = 30;

    /** 2.9.0: an exact DNS host name (lower case, no wildcard, no IP literal, no trailing dot). */
    private const HOST_NAME_PATTERN = '/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D';

    /** @var list<string> */
    public array $allowedAlgorithms;

    /**
     * 2.9.0 (`oidc.trusted_private_hosts`, test environment only): exact host names, lower case, whose private or
     * loopback addresses the OIDC fetches accept. Empty everywhere else (the extension refuses a non-empty list outside
     * kernel.environment "test").
     *
     * @var list<string>
     */
    public array $trustedPrivateHosts;

    /**
     * @param list<string> $allowedAlgorithms
     * @param float $timeout idle timeout of every OIDC request (nowo_auth_kit.http_client.timeout)
     * @param float $maxDuration total time limit of every OIDC request (nowo_auth_kit.http_client.max_duration)
     * @param list<string> $trustedPrivateHosts 2.9.0, test environment only: see {@see self::$trustedPrivateHosts}
     * @param string|null $environment kernel.environment; a non-empty $trustedPrivateHosts is refused unless it is "test"
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
        public int $discoveryFailureTtl = self::DEFAULT_DISCOVERY_FAILURE_TTL,
        array $trustedPrivateHosts = [],
        ?string $environment = null,
    ) {
        $unsupported = array_diff($allowedAlgorithms, self::SUPPORTED_ALGORITHMS);
        if ($allowedAlgorithms === [] || $unsupported !== []) {
            throw new InvalidArgumentException(sprintf('The OIDC algorithm allow-list must be a non-empty subset of %s ("none" and HS* are never accepted); got: %s.', implode(', ', self::SUPPORTED_ALGORITHMS), implode(', ', $allowedAlgorithms)));
        }
        $this->allowedAlgorithms = $allowedAlgorithms;

        // Defence in depth (audit ZDO-AK-I3): the extension already refuses the list outside "test", but a host could
        // still set the internal parameter directly; the list is applied only with kernel.environment "test".
        if ($trustedPrivateHosts !== [] && $environment !== 'test') {
            throw new InvalidArgumentException(sprintf('"nowo_auth_kit.oidc.trusted_private_hosts" is honoured only when kernel.environment is "test" (got %s).', $environment === null ? 'none' : '"' . $environment . '"'));
        }

        $hosts = [];
        foreach ($trustedPrivateHosts as $host) {
            if (($error = self::trustedPrivateHostError($host)) !== null) {
                throw new InvalidArgumentException($error);
            }
            $hosts[] = strtolower((string) $host);
        }
        $this->trustedPrivateHosts = array_values(array_unique($hosts));
    }

    /**
     * 2.9.0: why an `oidc.trusted_private_hosts` entry is refused (null when it is an exact host name). Wildcards, IP
     * literals and ranges, ports, and anything that is not a plain DNS name are refused; letter case is ignored.
     */
    public static function trustedPrivateHostError(mixed $host): ?string
    {
        if (!is_string($host) || filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match(self::HOST_NAME_PATTERN, strtolower($host)) !== 1) {
            return sprintf('Invalid "nowo_auth_kit.oidc.trusted_private_hosts" entry %s: exact host names only (no wildcard, no IP address or range, no port).', is_string($host) ? '"' . $host . '"' : get_debug_type($host));
        }

        return null;
    }
}
