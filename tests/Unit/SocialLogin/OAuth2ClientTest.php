<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\OAuth2Client;
use Nowo\AuthKitBundle\SocialLogin\ProviderEndpointCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use const JSON_THROW_ON_ERROR;

final class OAuth2ClientTest extends TestCase
{
    public function testBuildAuthorizeUrl(): void
    {
        $client     = new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setClientId('client-123');

        $url = $client->buildAuthorizeUrl($credential, 'https://app.test/callback', 'state-1');

        self::assertStringContainsString('client_id=client-123', $url);
        self::assertStringContainsString('state=state-1', $url);
        self::assertStringContainsString('redirect_uri=', $url);
    }

    public function testExchangeCodeAndFetchProfile(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'access_token'  => 'tok',
                'refresh_token' => 'ref',
                'expires_in'    => 3600,
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            new MockResponse(json_encode([
                'sub'            => 'uid-1',
                'email'          => 'user@example.com',
                'email_verified' => true,
                'name'           => 'User',
            ], JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);

        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())
            ->setProvider('google')
            ->setClientId('id')
            ->setClientSecret('secret');

        $tokens = $client->exchangeCode($credential, 'code', 'https://app.test/callback');
        self::assertSame('tok', $tokens['access_token']);
        self::assertSame('ref', $tokens['refresh_token']);

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertSame('uid-1', $profile->id);
        self::assertSame('user@example.com', $profile->email);
        self::assertTrue($profile->emailVerified);
    }

    public function testExchangeCodeRequiresAccessToken(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['error' => 'invalid'], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('id')->setClientSecret('s');

        $this->expectException(RuntimeException::class);
        $client->exchangeCode($credential, 'code', 'https://app.test/callback');
    }

    public function testFetchProfileRequiresSubject(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['email' => 'a@b.c'], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('id')->setClientSecret('s');

        $this->expectException(RuntimeException::class);
        $client->fetchUserProfile($credential, 'tok');
    }

    public function testFetchGithubEmailsList(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'id'     => 99,
                'login'  => 'octocat',
                'emails' => [
                    ['email' => 'other@ex.com', 'primary' => false],
                    ['email' => 'primary@ex.com', 'primary' => true, 'verified' => true],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('github')->setClientId('id')->setClientSecret('s');

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertSame('99', $profile->id);
        self::assertSame('primary@ex.com', $profile->email);
        self::assertTrue($profile->emailVerified);
    }

    public function testFetchGithubEmailsWithoutPrimaryUsesFirst(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'id'     => 1,
                'emails' => [
                    ['email' => 'first@ex.com'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('github')->setClientId('id')->setClientSecret('s');

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertSame('first@ex.com', $profile->email);
    }

    public function testFetchGithubEmailsSkipsInvalidRowsAndReturnsNull(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'id'     => 2,
                'emails' => [
                    'not-an-array',
                    ['email'    => ''],
                    ['email'    => 123],
                    ['no_email' => true],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('github')->setClientId('id')->setClientSecret('s');

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertNull($profile->email);
    }

    public function testFetchUsesDisplayNameAndSkipsBlankScalars(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'oid'         => 'oid-1',
                'email'       => '',
                'mail'        => 'mail@ex.com',
                'name'        => '   ',
                'displayName' => 'Display',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('microsoft')->setClientId('id')->setClientSecret('s');

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertSame('oid-1', $profile->id);
        self::assertSame('mail@ex.com', $profile->email);
        self::assertSame('Display', $profile->name);
    }

    public function testExchangeCodeHandlesNonStringRefreshAndNonNumericExpires(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'access_token'  => 'tok',
                'refresh_token' => ['x'],
                'expires_in'    => 'nope',
            ], JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('id')->setClientSecret('s');

        $tokens = $client->exchangeCode($credential, 'code', 'https://app.test/callback');
        self::assertNull($tokens['refresh_token']);
        self::assertNull($tokens['expires_in']);
    }

    public function testBuildAuthorizeUrlAppendsWithExistingQuery(): void
    {
        $client     = new OAuth2Client(new MockHttpClient(), new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())
            ->setProvider('custom')
            ->setClientId('id')
            ->setAuthorizeUrl('https://idp.example/auth?foo=1')
            ->setTokenUrl('https://idp.example/token')
            ->setUserinfoUrl('https://idp.example/me');

        $url = $client->buildAuthorizeUrl($credential, 'https://app.test/cb', 'st');
        self::assertStringContainsString('foo=1&', $url);
    }

    /**
     * @dataProvider emailVerifiedProvider
     */
    public function testParsesEmailVerifiedVariants(mixed $raw, ?bool $expected): void
    {
        $payload = ['sub' => '1', 'email' => 'a@b.c', 'email_verified' => $raw];
        $http    = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);
        $client     = new OAuth2Client($http, new ProviderEndpointCatalog());
        $credential = (new SocialLoginCredential())->setProvider('google')->setClientId('id')->setClientSecret('s');

        $profile = $client->fetchUserProfile($credential, 'tok');
        self::assertSame($expected, $profile->emailVerified);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: ?bool}>
     */
    public static function emailVerifiedProvider(): iterable
    {
        yield 'true string' => ['true', true];
        yield 'one string' => ['1', true];
        yield 'one int' => [1, true];
        yield 'false string' => ['false', false];
        yield 'zero string' => ['0', false];
        yield 'zero int' => [0, false];
        yield 'unknown' => ['maybe', null];
    }
}
