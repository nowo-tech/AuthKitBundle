<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\DnsHostResolver;
use PHPUnit\Framework\TestCase;

final class DnsHostResolverTest extends TestCase
{
    public function testResolvesLocalhostWithoutTheNetwork(): void
    {
        self::assertContains('127.0.0.1', (new DnsHostResolver())->resolve('localhost'));
    }

    public function testAnUnknownNameResolvesToNothing(): void
    {
        self::assertSame([], (new DnsHostResolver())->resolve('nothing.invalid'));
    }

    public function testIpv6AddressesFromTheAaaaLookup(): void
    {
        $resolver = new DnsHostResolver(static fn (string $host): array => [['ipv6' => '2606:4700:4700::1111'], ['ipv6' => ''], ['type' => 'AAAA']]);
        self::assertSame(['127.0.0.1', '2606:4700:4700::1111'], $resolver->resolve('localhost'));
    }
}
