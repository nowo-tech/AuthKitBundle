<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\AppleClientSecret;
use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use Nowo\AuthKitBundle\SocialLogin\Oidc\JwkConverter;
use Nowo\AuthKitBundle\SocialLogin\Oidc\OidcException;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function explode;
use function json_decode;
use function openssl_verify;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;

final class AppleClientSecretTest extends TestCase
{
    public function testAnEs256JwtSignedWithTheTeamKey(): void
    {
        $key    = TestKeys::ec();
        $clock  = new MockClock('2026-11-25 10:00:00');
        $secret = (new AppleClientSecret($clock))->generate('TEAM123456', 'KEY1234567', 'com.example.web', TestKeys::pem($key));

        [$header, $payload, $signature] = explode('.', $secret);
        self::assertSame(['alg' => 'ES256', 'kid' => 'KEY1234567'], json_decode((string) Base64Url::decode($header), true, 4, JSON_THROW_ON_ERROR));
        self::assertSame([
            'iss' => 'TEAM123456',
            'iat' => 1795600800,
            'exp' => 1795600800 + AppleClientSecret::LIFETIME,
            'aud' => 'https://appleid.apple.com',
            'sub' => 'com.example.web',
        ], json_decode((string) Base64Url::decode($payload), true, 4, JSON_THROW_ON_ERROR));

        $der    = JwkConverter::joseToDer((string) Base64Url::decode($signature), 32);
        $public = (new JwkConverter())->toPublicKey(TestKeys::jwk($key, 'k'));
        self::assertNotNull($public);
        self::assertSame(1, openssl_verify($header . '.' . $payload, (string) $der, $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wrongKeys(): iterable
    {
        yield 'not a key' => ['-----BEGIN PRIVATE KEY-----' . "\nnot-a-key\n" . '-----END PRIVATE KEY-----'];
        yield 'empty' => [''];
        yield 'rsa key' => [TestKeys::pem(TestKeys::rsa())];
        yield 'P-384 key' => [TestKeys::pem(TestKeys::ec('secp384r1'))];
    }

    #[DataProvider('wrongKeys')]
    public function testAnythingButAP256PrivateKeyIsRefusedWithoutLeakingIt(string $pem): void
    {
        try {
            (new AppleClientSecret(new MockClock()))->generate('TEAM123456', 'KEY1234567', 'com.example.web', $pem);
            self::fail('Expected a refusal.');
        } catch (OidcException $e) {
            self::assertSame(OidcException::REASON_CLIENT_SECRET, $e->reason);
            self::assertStringNotContainsString('BEGIN', $e->getMessage());
        }
    }
}
