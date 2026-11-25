<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\PublicIpChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublicIpCheckerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['93.184.215.14', true];
        yield 'public v4 next to cgnat' => ['100.128.0.1', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'private 10' => ['10.1.2.3', false];
        yield 'private 172.16/12' => ['172.31.255.255', false];
        yield 'private 192.168' => ['192.168.1.1', false];
        yield 'cgnat' => ['100.64.0.1', false];
        yield 'link local / metadata' => ['169.254.169.254', false];
        yield 'benchmarking' => ['198.19.0.1', false];
        yield 'multicast' => ['224.0.0.1', false];
        yield 'unspecified' => ['0.0.0.0', false];
        yield 'public v6' => ['2606:4700:4700::1111', true];
        yield 'v6 loopback' => ['::1', false];
        yield 'v6 unspecified' => ['::', false];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'v4-mapped public' => ['::ffff:93.184.215.14', true];
        yield 'v4-translated loopback' => ['::ffff:0:7f00:1', false];
        yield 'nat64' => ['64:ff9b::a00:1', false];
        yield '6to4' => ['2002:a00:1::1', false];
        yield 'teredo' => ['2001::1', false];
        yield 'documentation v6' => ['2001:db8::1', false];
        yield 'unique local' => ['fd00::1', false];
        yield 'link local v6' => ['fe80::1', false];
        yield 'multicast v6' => ['ff02::1', false];
        yield 'not an address' => ['example.com', false];
    }

    #[DataProvider('addresses')]
    public function testIsPublic(string $ip, bool $public): void
    {
        self::assertSame($public, (new PublicIpChecker())->isPublic($ip));
    }
}
