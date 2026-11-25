<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\HostResolverInterface;

/**
 * Resolves every host to a public documentation-free address unless told otherwise (tests only).
 */
final class FakeResolver implements HostResolverInterface
{
    /** @var array<string, list<string>> */
    public array $answers = [];

    /** @var list<string> */
    public array $default = ['93.184.215.14'];

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? $this->default;
    }
}
