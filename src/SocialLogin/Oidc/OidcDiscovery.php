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
use function rtrim;
use function sprintf;

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
    private const CACHE_PREFIX = 'nowo_auth_kit.oidc.metadata.';

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
        );
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
