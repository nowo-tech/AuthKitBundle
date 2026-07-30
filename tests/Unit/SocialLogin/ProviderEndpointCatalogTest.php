<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProviderEndpointCatalogTest extends TestCase
{
    public function testResolvesGoogleDefaults(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setClientId('id')
            ->setClientSecret('secret');

        $resolved = (new ProviderEndpointCatalog())->resolve($credential);

        self::assertStringContainsString('accounts.google.com', $resolved['authorize_url']);
        self::assertContains('email', $resolved['scopes']);
    }

    public function testUsesCredentialOverrides(): void
    {
        $credential = (new SocialLoginCredential())
            ->setProvider('custom')
            ->setAuthorizeUrl('https://idp.example/auth')
            ->setTokenUrl('https://idp.example/token')
            ->setUserinfoUrl('https://idp.example/me')
            ->setScopes(['openid']);

        $resolved = (new ProviderEndpointCatalog())->resolve($credential);

        self::assertSame('https://idp.example/auth', $resolved['authorize_url']);
        self::assertSame(['openid'], $resolved['scopes']);
    }

    public function testResolvesGithubAndMicrosoftDefaults(): void
    {
        $catalog = new ProviderEndpointCatalog();

        $github = $catalog->resolve((new SocialLoginCredential())->setProvider('github'));
        self::assertStringContainsString('github.com', $github['authorize_url']);

        $microsoft = $catalog->resolve((new SocialLoginCredential())->setProvider('microsoft'));
        self::assertStringContainsString('microsoftonline.com', $microsoft['authorize_url']);
    }

    public function testThrowsWhenCustomMissingUrls(): void
    {
        $this->expectException(RuntimeException::class);

        (new ProviderEndpointCatalog())->resolve(
            (new SocialLoginCredential())->setProvider('custom'),
        );
    }
}
