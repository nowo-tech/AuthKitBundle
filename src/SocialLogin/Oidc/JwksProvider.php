<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\SocialLogin\Oidc;

use Psr\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function array_is_list;
use function hash;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The JSON Web Key Set of an issuer (2.3.0), fetched through the SSRF guard and kept in the cache pool for
 * `oidc.jwks_cache_ttl` seconds.
 *
 * A key id the cached set does not hold triggers one refetch (the provider rotated its keys), at most once every
 * `oidc.jwks_refresh_interval` seconds per set, so a stream of tokens with made-up key ids cannot turn into a stream of
 * requests to the provider. State lives only in the cache pool (REQ-RUNTIME-002).
 */
final class JwksProvider
{
    private const CACHE_PREFIX = 'nowo_auth_kit.oidc.jwks.';

    public function __construct(
        private readonly OidcHttpClient $http,
        private readonly CacheInterface $cache,
        private readonly OidcOptions $options,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * The keys of the set that may carry `$kid` (all of them when the token names no key id).
     *
     * @throws OidcException
     *
     * @return list<array<string, mixed>>
     */
    public function keys(string $jwksUri, ?string $kid): array
    {
        $set     = $this->load($jwksUri);
        $matches = $this->matching($set['keys'], $kid);
        if ($matches !== [] || $kid === null) {
            return $matches;
        }

        // Unknown key id: the provider may have rotated. Refetch, but not more often than the refresh interval.
        if ($this->clock->now()->getTimestamp() - $set['fetched_at'] < $this->options->jwksRefreshInterval) {
            return [];
        }
        $this->cache->delete($this->cacheKey($jwksUri));

        return $this->matching($this->load($jwksUri)['keys'], $kid);
    }

    /**
     * @return array{fetched_at: int, keys: list<array<string, mixed>>}
     */
    private function load(string $jwksUri): array
    {
        /** @var array{fetched_at: int, keys: list<array<string, mixed>>} $set */
        $set = $this->cache->get($this->cacheKey($jwksUri), function (ItemInterface $item) use ($jwksUri): array {
            $item->expiresAfter($this->options->jwksCacheTtl);

            return $this->fetch($jwksUri);
        });

        return $set;
    }

    /**
     * @return array{fetched_at: int, keys: list<array<string, mixed>>}
     */
    private function fetch(string $jwksUri): array
    {
        $response = $this->http->requestJson('GET', $jwksUri, 'jwks');
        if ($response['status'] !== 200) {
            throw new OidcException(OidcException::REASON_JWKS, sprintf('the JWKS endpoint answered HTTP %d.', $response['status']));
        }
        $keys = $response['data']['keys'] ?? null;
        if (!is_array($keys) || !array_is_list($keys)) {
            throw new OidcException(OidcException::REASON_JWKS, 'the JWKS document has no "keys" list.');
        }

        $clean = [];
        foreach ($keys as $key) {
            if (is_array($key) && is_string($key['kty'] ?? null)) {
                /** @var array<string, mixed> $typed */
                $typed   = $key;
                $clean[] = $typed;
            }
        }

        return ['fetched_at' => $this->clock->now()->getTimestamp(), 'keys' => $clean];
    }

    /**
     * @param list<array<string, mixed>> $keys
     *
     * @return list<array<string, mixed>>
     */
    private function matching(array $keys, ?string $kid): array
    {
        $matches = [];
        foreach ($keys as $key) {
            if ($kid === null || (isset($key['kid']) && $key['kid'] === $kid)) {
                $matches[] = $key;
            }
        }

        return $matches;
    }

    private function cacheKey(string $jwksUri): string
    {
        return self::CACHE_PREFIX . hash('sha256', $jwksUri);
    }
}
