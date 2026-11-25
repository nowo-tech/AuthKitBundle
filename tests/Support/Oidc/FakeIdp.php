<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Oidc;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function array_merge;
use function json_encode;
use function parse_url;
use function rtrim;
use function str_ends_with;
use function time;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_PATH;

/**
 * A fake OpenID Connect provider for the tests: discovery, JWKS, token and userinfo endpoints under one issuer, an ID
 * token signed with a key generated in the test run. Every field can be bent to drive one failure path.
 */
final class FakeIdp
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, mixed> merged into the discovery document (null removes a member) */
    public array $discovery = [];

    /** @var array<string, mixed>|null the whole JWKS document; null: the signing key's JWK */
    public ?array $jwks = null;

    /** @var array<string, mixed> merged into the ID token claims (null removes a claim) */
    public array $claims = [];

    /** @var array<string, mixed> merged into the ID token header */
    public array $header = [];

    /** @var array<string, mixed> merged into the token response (null removes a member) */
    public array $tokenResponse = [];

    public int $tokenStatus = 200;

    /** @var array<string, mixed>|null userinfo answer; null: the endpoint is not in the discovery document */
    public ?array $userinfo = [];

    public int $userinfoStatus = 200;

    public int $discoveryStatus = 200;

    public int $jwksStatus = 200;

    public ?string $nonce = null;

    public ?string $rawIdToken = null;

    public ?int $now = null;

    public function __construct(
        public string $issuer = 'https://idp.example.com',
        public string $clientId = 'client-placeholder',
        public string $kid = 'key-1',
        public string $email = 'ada@example.com',
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
        $path             = (string) parse_url($url, PHP_URL_PATH);

        if (str_ends_with($path, '/.well-known/openid-configuration')) {
            return $this->json($this->discoveryDocument(), $this->discoveryStatus);
        }
        if (str_ends_with($path, '/jwks')) {
            return $this->json($this->jwks ?? ['keys' => [TestKeys::jwk(TestKeys::rsa(), $this->kid, ['use' => 'sig', 'alg' => 'RS256'])]], $this->jwksStatus);
        }
        if (str_ends_with($path, '/token')) {
            return $this->json($this->clean(array_merge([
                'access_token' => 'at-placeholder',
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
                'id_token'     => $this->rawIdToken ?? $this->idToken(),
            ], $this->tokenResponse)), $this->tokenStatus);
        }

        return $this->json(array_merge(['sub' => 'subject-1'], $this->userinfo ?? []), $this->userinfoStatus);
    }

    public function idToken(): string
    {
        $now = $this->now ?? time();

        return TestKeys::sign(
            $this->clean(array_merge(['alg' => 'RS256', 'kid' => $this->kid, 'typ' => 'JWT'], $this->header)),
            $this->clean(array_merge([
                'iss'            => $this->issuer,
                'sub'            => 'subject-1',
                'aud'            => $this->clientId,
                'exp'            => $now + 300,
                'iat'            => $now,
                'nonce'          => $this->nonce,
                'email'          => $this->email,
                'email_verified' => true,
                'name'           => 'Ada Lovelace',
            ], $this->claims)),
            TestKeys::rsa(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function discoveryDocument(): array
    {
        $base = rtrim($this->issuer, '/');

        return $this->clean(array_merge([
            'issuer'                                => $this->issuer,
            'authorization_endpoint'                => $base . '/authorize',
            'token_endpoint'                        => $base . '/token',
            'userinfo_endpoint'                     => $this->userinfo === null ? null : $base . '/userinfo',
            'jwks_uri'                              => $base . '/jwks',
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
        ], $this->discovery));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value === null) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): MockResponse
    {
        return new MockResponse(json_encode($data, JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }
}
