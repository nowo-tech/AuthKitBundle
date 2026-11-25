<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function count;

final class OidcDiscoveryTest extends TestCase
{
    public function testDiscoversChecksAndCaches(): void
    {
        $idp   = new FakeIdp();
        $stack = new OidcStack($idp);

        $metadata = $stack->discovery->metadata('https://idp.example.com');
        self::assertSame('https://idp.example.com', $metadata->issuer);
        self::assertSame('https://idp.example.com/authorize', $metadata->authorizationEndpoint);
        self::assertSame('https://idp.example.com/token', $metadata->tokenEndpoint);
        self::assertSame('https://idp.example.com/jwks', $metadata->jwksUri);
        self::assertSame('https://idp.example.com/userinfo', $metadata->userinfoEndpoint);
        self::assertTrue($metadata->supportsS256());
        self::assertSame('client_secret_post', $metadata->tokenAuthMethod());
        self::assertSame('https://idp.example.com/.well-known/openid-configuration', $idp->requests[0]['url']);

        $stack->discovery->metadata('https://idp.example.com');
        self::assertCount(1, $idp->requests, 'The second call is served from the cache pool.');
    }

    public function testATrailingSlashIsTheOnlyToleratedIssuerDifference(): void
    {
        $idp   = new FakeIdp('https://idp.example.com/');
        $stack = new OidcStack($idp);

        $metadata = $stack->discovery->metadata('https://idp.example.com');
        self::assertSame('https://idp.example.com/', $metadata->issuer, 'ID tokens are checked against the document\'s spelling.');
        self::assertSame('https://idp.example.com/.well-known/openid-configuration', $idp->requests[0]['url']);
    }

    public function testOptionalMembers(): void
    {
        $idp            = new FakeIdp();
        $idp->userinfo  = null;
        $idp->discovery = ['code_challenge_methods_supported' => 'S256', 'token_endpoint_auth_methods_supported' => ['client_secret_basic', 7]];

        $metadata = (new OidcStack($idp))->discovery->metadata('https://idp.example.com');
        self::assertNull($metadata->userinfoEndpoint);
        self::assertFalse($metadata->supportsS256());
        self::assertSame('client_secret_basic', $metadata->tokenAuthMethod());
        self::assertSame(['client_secret_basic'], $metadata->tokenAuthMethods);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badDocuments(): iterable
    {
        yield 'another issuer (mix-up)' => [['issuer' => 'https://evil.example.com']];
        yield 'no issuer' => [['issuer' => null]];
        yield 'no authorization endpoint' => [['authorization_endpoint' => null]];
        yield 'no token endpoint' => [['token_endpoint' => '']];
        yield 'no jwks' => [['jwks_uri' => null]];
        yield 'plain http token endpoint' => [['token_endpoint' => 'http://idp.example.com/token']];
        yield 'private jwks' => [['jwks_uri' => 'https://192.168.0.10/jwks']];
        yield 'userinfo not a string' => [['userinfo_endpoint' => ['x']]];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('badDocuments')]
    public function testABadDocumentIsRefusedAndNotCached(array $override): void
    {
        $idp            = new FakeIdp();
        $idp->discovery = $override;
        $stack          = new OidcStack($idp);

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $stack->discovery->metadata('https://idp.example.com');
                self::fail('Expected a refusal.');
            } catch (OidcException $e) {
                self::assertSame(OidcException::REASON_DISCOVERY, $e->reason);
            }
        }
        self::assertSame(1, count($idp->requests), 'ZBP-L3: a refused document is remembered as a failure, not fetched again at once.');
    }

    public function testAFailedDiscoveryIsRetriedOnlyAfterTheFailureTtl(): void
    {
        $idp                  = new FakeIdp();
        $idp->discoveryStatus = 503;
        $stack                = new OidcStack($idp);

        foreach ([1, 1] as $expected) {
            try {
                $stack->discovery->metadata('https://idp.example.com');
                self::fail('Expected a refusal.');
            } catch (OidcException $e) {
                self::assertSame(OidcException::REASON_DISCOVERY, $e->reason);
            }
            self::assertCount($expected, $idp->requests);
        }

        // The provider is back; once the negative entry is gone the document is fetched and cached as usual.
        $idp->discoveryStatus = 200;
        $stack->cache->clear();
        self::assertSame('https://idp.example.com', $stack->discovery->metadata('https://idp.example.com')->issuer);
        self::assertCount(2, $idp->requests);
    }

    public function testAZeroFailureTtlRetriesAtOnce(): void
    {
        $idp                  = new FakeIdp();
        $idp->discoveryStatus = 503;
        $stack                = new OidcStack($idp, new OidcOptions(discoveryFailureTtl: 0));

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $stack->discovery->metadata('https://idp.example.com');
                self::fail('Expected a refusal.');
            } catch (OidcException) {
            }
        }
        self::assertCount(2, $idp->requests);
    }

    public function testANon200AnswerIsRefused(): void
    {
        $idp                  = new FakeIdp();
        $idp->discoveryStatus = 404;

        $this->expectException(OidcException::class);
        (new OidcStack($idp))->discovery->metadata('https://idp.example.com');
    }
}
