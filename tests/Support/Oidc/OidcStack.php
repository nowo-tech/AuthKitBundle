<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Oidc;

use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\Oidc\AppleClientSecret;
use Nowo\AuthKitBundle\SocialLogin\Oidc\IdTokenValidator;
use Nowo\AuthKitBundle\SocialLogin\Oidc\JwksProvider;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcDiscovery;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcHttpClient;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcProviderCatalog;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The OIDC services wired over a fake provider, an in-memory cache, a fixed clock and a fake resolver (tests only).
 */
final class OidcStack
{
    public readonly MockHttpClient $httpClient;
    public readonly ArrayAdapter $cache;
    public readonly MockClock $clock;
    public readonly FakeResolver $resolver;
    public readonly OidcHttpClient $http;
    public readonly OidcDiscovery $discovery;
    public readonly JwksProvider $jwks;
    public readonly IdTokenValidator $validator;
    public readonly OidcClient $client;

    public function __construct(callable $idp, public readonly OidcOptions $options = new OidcOptions())
    {
        $this->httpClient = new MockHttpClient($idp);
        $this->cache      = new ArrayAdapter();
        $this->clock      = new MockClock('2026-11-25 10:00:00');
        $this->resolver   = new FakeResolver();
        $this->http       = new OidcHttpClient($this->httpClient, $options, $this->resolver);
        $this->discovery  = new OidcDiscovery($this->http, $this->cache, $options);
        $this->jwks       = new JwksProvider($this->http, $this->cache, $options, $this->clock);
        $this->validator  = new IdTokenValidator($this->jwks, $options, $this->clock);
        $this->client     = new OidcClient(
            new OidcProviderCatalog(),
            $this->discovery,
            $this->validator,
            $this->http,
            new OAuth2Client($this->httpClient, new ProviderEndpointCatalog()),
            new AppleClientSecret($this->clock),
        );
        if ($idp instanceof FakeIdp) {
            $idp->now = $this->clock->now()->getTimestamp();
        }
    }
}
