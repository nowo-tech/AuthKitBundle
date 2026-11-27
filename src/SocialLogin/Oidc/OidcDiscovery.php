<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\OAuthEndpointUrlValidator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function hash;
use function is_array;
use function is_string;
use function parse_url;
use function rtrim;
use function sprintf;
use function strlen;

/**
 * OpenID Connect Discovery 1.0 (2.3.0): `<issuer>/.well-known/openid-configuration`, fetched through the SSRF guard,
 * checked, and kept in the cache pool (`oidc.cache_pool`) for `oidc.metadata_cache_ttl` seconds.
 *
 * The document's `issuer` must be the configured issuer (§4.3; a trailing slash is the only difference tolerated, and
 * the document's spelling is the one ID tokens are then checked against): a document served for another issuer is a
 * mix-up and is refused. Only a checked document is cached; a failed discovery is remembered as failed for
 * `oidc.discovery_failure_ttl` seconds (negative cache, per issuer) and not retried meanwhile.
 *
 * No state in the service: the cache pool is the only memory (FrankenPHP worker mode, REQ-RUNTIME-002).
 */
final class OidcDiscovery
{
    /** 2.8.0: `v2` documents carry `end_session_endpoint`; a 2.7.0 entry (without it) is never read back. */
    private const CACHE_PREFIX = 'nowo_auth_kit.oidc.metadata.v2.';

    /** 2.8.0: longest optional endpoint kept (it is stored in the session with the RP-initiated logout hint). */
    public const MAX_OPTIONAL_ENDPOINT_LENGTH = 2048;

    private const FAILURE_PREFIX = 'nowo_auth_kit.oidc.metadata_failed.';

    public function __construct(
        private readonly OidcHttpClient $http,
        private readonly CacheInterface $cache,
        private readonly OidcOptions $options,
        private readonly OAuthEndpointUrlValidator $urlValidator = new OAuthEndpointUrlValidator(),
    ) {
    }

    /**
     * @throws OidcException
     */
    public function metadata(string $issuer): OidcProviderMetadata
    {
        $key = hash('sha256', $issuer);
        // 2.3.0 (audit ZBP-L3): a discovery that just failed is not retried for `oidc.discovery_failure_ttl` seconds, so
        // a loop on the start URL while the provider is down or slow cannot tie up the workers with outbound requests.
        if ($this->recentlyFailed($key)) {
            throw new OidcException(OidcException::REASON_DISCOVERY, 'the discovery of this issuer failed moments ago; retried later.');
        }

        /** @var array<string, mixed> $document */
        $document = $this->cache->get(self::CACHE_PREFIX . $key, function (ItemInterface $item) use ($issuer, $key): array {
            $item->expiresAfter($this->options->metadataCacheTtl);

            try {
                return $this->fetch($issuer)->toArray();
            } catch (OidcException $e) {
                $this->rememberFailure($key);

                throw $e;
            }
        });

        return $this->fromDocument($issuer, $document);
    }

    private function recentlyFailed(string $key): bool
    {
        if ($this->options->discoveryFailureTtl === 0) {
            return false;
        }

        /** @var bool $failed */
        $failed = $this->cache->get(self::FAILURE_PREFIX . $key, static function (ItemInterface $item, bool &$save): bool {
            $save = false;

            return false;
        });

        return $failed;
    }

    private function rememberFailure(string $key): void
    {
        if ($this->options->discoveryFailureTtl === 0) {
            return;
        }
        $this->cache->delete(self::FAILURE_PREFIX . $key);
        $this->cache->get(self::FAILURE_PREFIX . $key, function (ItemInterface $item): bool {
            $item->expiresAfter($this->options->discoveryFailureTtl);

            return true;
        });
    }

    private function fetch(string $issuer): OidcProviderMetadata
    {
        $url      = rtrim($issuer, '/') . '/.well-known/openid-configuration';
        $response = $this->http->requestJson('GET', $url, 'discovery');
        if ($response['status'] !== 200) {
            throw new OidcException(OidcException::REASON_DISCOVERY, sprintf('the discovery document answered HTTP %d.', $response['status']));
        }

        return $this->fromDocument($issuer, $response['data']);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function fromDocument(string $issuer, array $document): OidcProviderMetadata
    {
        $documentIssuer = $document['issuer'] ?? null;
        if (!is_string($documentIssuer) || rtrim($documentIssuer, '/') !== rtrim($issuer, '/')) {
            throw new OidcException(OidcException::REASON_DISCOVERY, 'the discovery document is for another issuer.');
        }

        $userinfo = $document['userinfo_endpoint'] ?? null;

        return new OidcProviderMetadata(
            $documentIssuer,
            $this->endpoint($document, 'authorization_endpoint'),
            $this->endpoint($document, 'token_endpoint'),
            $this->endpoint($document, 'jwks_uri'),
            $userinfo === null ? null : $this->endpoint($document, 'userinfo_endpoint'),
            $this->stringList($document['code_challenge_methods_supported'] ?? null),
            $this->stringList($document['token_endpoint_auth_methods_supported'] ?? null),
            $this->optionalEndpoint($document, 'end_session_endpoint'),
        );
    }

    /**
     * 2.8.0: an optional endpoint the sign-in does not need (`end_session_endpoint`). It passes the same HTTPS / SSRF
     * rules as the others, without fragment or credentials; an absent, malformed or unsafe value is dropped (null: no RP-initiated logout), so it never
     * fails a sign-in that worked in 2.7.0.
     *
     * @param array<string, mixed> $document
     */
    private function optionalEndpoint(array $document, string $name): ?string
    {
        $url = $document[$name] ?? null;
        if (!is_string($url) || $url === '' || strlen($url) > self::MAX_OPTIONAL_ENDPOINT_LENGTH) {
            return null;
        }
        // Parameters are appended to it, and the browser is sent there: no fragment, no credentials.
        $parts = parse_url($url);
        if ($parts === false || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        try {
            $this->urlValidator->assertSafeHttpsUrl($url, $name);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function endpoint(array $document, string $name): string
    {
        $url = $document[$name] ?? null;
        if (!is_string($url) || $url === '') {
            throw new OidcException(OidcException::REASON_DISCOVERY, sprintf('the discovery document has no %s.', $name));
        }

        try {
            $this->urlValidator->assertSafeHttpsUrl($url, $name);
        } catch (InvalidArgumentException $e) {
            throw new OidcException(OidcException::REASON_DISCOVERY, sprintf('the discovery document\'s %s is not a public HTTPS URL.', $name), $e);
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $list = [];
        foreach ($value as $item) {
            if (is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }
}
