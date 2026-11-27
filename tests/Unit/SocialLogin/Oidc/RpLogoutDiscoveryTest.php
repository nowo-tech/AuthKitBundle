<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\Entity\SocialLoginCredential;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcDiscovery;
use Nowo\AuthKitBundle\Tests\Support\Oidc\FakeIdp;
use Nowo\AuthKitBundle\Tests\Support\Oidc\OidcStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 2.8.0: `end_session_endpoint` of the discovery document (OpenID Connect RP-Initiated Logout 1.0).
 */
final class RpLogoutDiscoveryTest extends TestCase
{
    public function testTheEndSessionEndpointIsReadCachedAndExported(): void
    {
        $idp            = new FakeIdp();
        $idp->discovery = ['end_session_endpoint' => 'https://idp.example.com/logout?tenant=acme'];
        $stack          = new OidcStack($idp);

        $metadata = $stack->discovery->metadata('https://idp.example.com');
        self::assertSame('https://idp.example.com/logout?tenant=acme', $metadata->endSessionEndpoint);
        self::assertSame('https://idp.example.com/logout?tenant=acme', $metadata->toArray()['end_session_endpoint']);
        // Served from the cache pool the second time, still with the endpoint.
        self::assertSame('https://idp.example.com/logout?tenant=acme', $stack->discovery->metadata('https://idp.example.com')->endSessionEndpoint);
        self::assertCount(1, $idp->requests);
    }

    public function testNoEndSessionEndpointIsNull(): void
    {
        self::assertNull((new OidcStack(new FakeIdp()))->discovery->metadata('https://idp.example.com')->endSessionEndpoint);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unsafeEndpoints(): iterable
    {
        yield 'not a string' => [['https://idp.example.com/logout']];
        yield 'empty' => [''];
        yield 'plain http' => ['http://idp.example.com/logout'];
        yield 'private address' => ['https://10.0.0.5/logout'];
        yield 'localhost' => ['https://localhost/logout'];
        yield 'fragment' => ['https://idp.example.com/logout#x'];
        yield 'credentials' => ['https://user:pass@idp.example.com/logout'];
        yield 'unparsable' => ['https:///logout'];
        yield 'too long' => ['https://idp.example.com/' . str_repeat('a', OidcDiscovery::MAX_OPTIONAL_ENDPOINT_LENGTH)];
    }

    /**
     * SECURITY: an unsafe endpoint is dropped (no RP-initiated logout); the sign-in endpoints still work as in 2.7.0.
     */
    #[DataProvider('unsafeEndpoints')]
    public function testAnUnsafeEndSessionEndpointIsDroppedWithoutFailingTheDiscovery(mixed $endpoint): void
    {
        $idp            = new FakeIdp();
        $idp->discovery = ['end_session_endpoint' => $endpoint];

        $metadata = (new OidcStack($idp))->discovery->metadata('https://idp.example.com');
        self::assertNull($metadata->endSessionEndpoint);
        self::assertSame('https://idp.example.com/token', $metadata->tokenEndpoint);
    }

    public function testTheClientAnswersTheEndpointOrNullWithoutThrowing(): void
    {
        $idp            = new FakeIdp();
        $idp->discovery = ['end_session_endpoint' => 'https://idp.example.com/logout'];
        $client         = (new OidcStack($idp))->client;
        $generic        = (new SocialLoginCredential())->setProvider('okta')->setClientId('client-placeholder')->setClientSecret('secret-placeholder')->setIssuer('https://idp.example.com');

        self::assertSame('https://idp.example.com/logout', $client->endSessionEndpoint($generic));
        // Not an OpenID Connect credential (Google uses the OAuth2 client).
        self::assertNull($client->endSessionEndpoint((new SocialLoginCredential())->setProvider('google')->setClientId('client-placeholder')));

        $down                  = new FakeIdp();
        $down->discoveryStatus = 503;
        self::assertNull((new OidcStack($down))->client->endSessionEndpoint($generic), 'A failed discovery is no RP-initiated logout, never an error.');
    }

    public function testTheCredentialOptInDefaultsToOff(): void
    {
        $credential = new SocialLoginCredential();
        self::assertFalse($credential->isRpInitiatedLogout());
        $before = $credential->getUpdatedAt();
        self::assertTrue($credential->setRpInitiatedLogout(true)->isRpInitiatedLogout());
        self::assertGreaterThanOrEqual($before, $credential->getUpdatedAt());
        self::assertFalse($credential->setRpInitiatedLogout(false)->isRpInitiatedLogout());
        self::assertFalse($credential->setRpInitiatedLogout(null)->isRpInitiatedLogout());
    }
}
