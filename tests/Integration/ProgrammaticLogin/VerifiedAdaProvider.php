<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Integration\ProgrammaticLogin;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

use function json_encode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * The fake identity provider: a verified `ada@example.com`.
 */
final class VerifiedAdaProvider
{
    /**
     * @param array<string, mixed> $options
     */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        return new MockResponse(json_encode(str_contains($url, 'token')
            ? ['access_token' => 'at-placeholder', 'expires_in' => 3600]
            : ['sub' => 'subject-1', 'email' => 'ada@example.com', 'email_verified' => true, 'name' => 'Ada'], JSON_THROW_ON_ERROR));
    }
}
