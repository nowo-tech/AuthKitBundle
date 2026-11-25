<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcOptions;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\TestCase;

final class JwksProviderTest extends TestCase
{
    private const URI = 'https://idp.example.com/jwks';

    public function testKeysAreCachedAndFilteredByKeyId(): void
    {
        $idp       = new FakeIdp();
        $idp->jwks = ['keys' => [TestKeys::jwk(TestKeys::rsa(), 'a'), TestKeys::jwk(TestKeys::ec(), 'b'), 'not a key', ['no' => 'kty']]];
        $stack     = new OidcStack($idp);

        self::assertCount(1, $stack->jwks->keys(self::URI, 'a'));
        self::assertCount(2, $stack->jwks->keys(self::URI, null), 'No key id: every usable key of the set.');
        self::assertCount(1, $idp->requests);
    }

    public function testAnUnknownKeyIdRefetchesOnlyAfterTheRefreshInterval(): void
    {
        $idp       = new FakeIdp();
        $idp->jwks = ['keys' => [TestKeys::jwk(TestKeys::rsa(), 'old')]];
        $stack     = new OidcStack($idp, new OidcOptions(jwksRefreshInterval: 60));

        self::assertCount(1, $stack->jwks->keys(self::URI, 'old'));
        $idp->jwks = ['keys' => [TestKeys::jwk(TestKeys::rsa(), 'new')]];

        self::assertSame([], $stack->jwks->keys(self::URI, 'new'), 'Within the interval: no refetch.');
        self::assertCount(1, $idp->requests);

        $stack->clock->sleep(61);
        self::assertCount(1, $stack->jwks->keys(self::URI, 'new'), 'Rotation picked up after the interval.');
        self::assertCount(2, $idp->requests);
    }

    public function testRefusedDocuments(): void
    {
        foreach ([[503, ['keys' => []]], [200, ['keys' => 'x']], [200, ['keys' => ['a' => []]]], [200, ['other' => 1]]] as [$status, $document]) {
            $idp             = new FakeIdp();
            $idp->jwksStatus = $status;
            $idp->jwks       = $document;

            try {
                (new OidcStack($idp))->jwks->keys(self::URI, 'k');
                self::fail('Expected a refusal.');
            } catch (OidcException $e) {
                self::assertSame(OidcException::REASON_JWKS, $e->reason);
            }
        }
    }
}
