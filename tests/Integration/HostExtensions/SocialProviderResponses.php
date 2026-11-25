<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\HostExtensions;

use Closure;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function json_encode;
use function str_contains;
use function strpos;
use function substr;

use const JSON_THROW_ON_ERROR;

/**
 * Answers of the fake identity provider; `requests` counts them, so a test can prove no code was exchanged.
 */
final class SocialProviderResponses
{
    public int $requests = 0;

    public bool $failTokenExchange = false;

    public string $email = 'new.person@example.com';

    public string $name = 'New Person';

    /** Runs while the provider answers (a revocation arriving during the callback). */
    public ?Closure $whileAnswering = null;

    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        ++$this->requests;
        if ($this->whileAnswering instanceof Closure) {
            ($this->whileAnswering)();
            $this->whileAnswering = null;
        }
        // 2.3.0: an OpenID Connect issuer's discovery document (the invitation tests start an OIDC authorization).
        if (str_contains($url, '/.well-known/openid-configuration')) {
            $issuer = substr($url, 0, (int) strpos($url, '/.well-known/'));

            return new MockResponse(json_encode([
                'issuer'                 => $issuer,
                'authorization_endpoint' => $issuer . '/authorize',
                'token_endpoint'         => $issuer . '/token',
                'jwks_uri'               => $issuer . '/jwks',
            ], JSON_THROW_ON_ERROR));
        }
        if (str_contains($url, 'token')) {
            return $this->failTokenExchange
                ? new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])
                : new MockResponse(json_encode(['access_token' => 'at-placeholder', 'expires_in' => 3600], JSON_THROW_ON_ERROR));
        }

        return new MockResponse(json_encode(['sub' => 'subject-1', 'email' => $this->email, 'email_verified' => true, 'name' => $this->name], JSON_THROW_ON_ERROR));
    }
}
