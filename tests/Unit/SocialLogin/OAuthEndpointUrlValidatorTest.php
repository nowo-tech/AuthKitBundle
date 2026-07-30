<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use InvalidArgumentException;
use Nowo\AuthKitBundle\SocialLogin\OAuthEndpointUrlValidator;
use PHPUnit\Framework\TestCase;

final class OAuthEndpointUrlValidatorTest extends TestCase
{
    public function testAcceptsPublicHttpsUrl(): void
    {
        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('https://idp.example/oauth', 'authorize');
        $this->addToAssertionCount(1);
    }

    public function testRejectsHttp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');

        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('http://idp.example/oauth', 'token');
    }

    public function testRejectsPrivateIp(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('https://10.0.0.1/oauth', 'userinfo');
    }

    public function testRejectsLocalhost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('https://localhost/oauth', 'authorize');
    }

    public function testRejectsInvalidUrl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid');

        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('not-a-url', 'authorize');
    }

    public function testAcceptsPublicIpHost(): void
    {
        (new OAuthEndpointUrlValidator())->assertSafeHttpsUrl('https://8.8.8.8/oauth', 'authorize');
        $this->addToAssertionCount(1);
    }
}
