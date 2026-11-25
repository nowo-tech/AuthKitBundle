<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Unit\SocialLogin\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use Nowo\AuthKitBundle\SocialLogin\Oidc\JwkConverter;
use Nowo\AuthKitBundle\Tests\Support\Oidc\TestKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function openssl_sign;
use function openssl_verify;
use function str_repeat;

use const OPENSSL_ALGO_SHA256;

final class JwkConverterTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function curves(): iterable
    {
        yield 'P-256' => ['prime256v1'];
        yield 'P-384' => ['secp384r1'];
        yield 'P-521' => ['secp521r1'];
    }

    #[DataProvider('curves')]
    public function testEcKeysRoundTrip(string $curve): void
    {
        $private = TestKeys::ec($curve);
        $public  = (new JwkConverter())->toPublicKey(TestKeys::jwk($private, 'k'));
        self::assertNotNull($public);

        $signature = '';
        openssl_sign('data', $signature, $private, OPENSSL_ALGO_SHA256);
        self::assertSame(1, openssl_verify('data', (string) $signature, $public, OPENSSL_ALGO_SHA256));
    }

    public function testRsaKeyRoundTrip(): void
    {
        $private = TestKeys::rsa();
        $public  = (new JwkConverter())->toPublicKey(TestKeys::jwk($private, 'k'));
        self::assertNotNull($public);

        $signature = '';
        openssl_sign('data', $signature, $private, OPENSSL_ALGO_SHA256);
        self::assertSame(1, openssl_verify('data', (string) $signature, $public, OPENSSL_ALGO_SHA256));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        $rsa = TestKeys::jwk(TestKeys::rsa(), 'k');
        $ec  = TestKeys::jwk(TestKeys::ec(), 'k');

        yield 'rsa below 2048 bits' => [TestKeys::jwk(TestKeys::rsa('small', 1024), 'k')];
        yield 'private rsa member' => [$rsa + ['d' => 'AQAB']];
        yield 'symmetric key' => [['kty' => 'oct', 'k' => 'c2VjcmV0']];
        yield 'unknown kty' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'AA']];
        yield 'no kty' => [[]];
        yield 'rsa modulus not base64url' => [['kty' => 'RSA', 'n' => 'a+b', 'e' => 'AQAB']];
        yield 'rsa empty exponent' => [['kty' => 'RSA', 'n' => $rsa['n'], 'e' => 'AA']];
        yield 'rsa missing modulus' => [['kty' => 'RSA', 'e' => 'AQAB']];
        yield 'ec unknown curve' => [['crv' => 'secp256k1'] + $ec];
        yield 'ec short coordinate' => [['x' => Base64Url::encode(str_repeat("\1", 31))] + $ec];
        yield 'ec missing y' => [['kty' => 'EC', 'crv' => 'P-256', 'x' => $ec['x']]];
        yield 'ec point not on the curve' => [['x' => Base64Url::encode(str_repeat("\1", 32)), 'y' => Base64Url::encode(str_repeat("\2", 32))] + $ec];
    }

    /**
     * @param array<string, mixed> $jwk
     */
    #[DataProvider('refused')]
    public function testRefusedKeys(array $jwk): void
    {
        self::assertNull((new JwkConverter())->toPublicKey($jwk));
    }

    public function testSignatureEncodings(): void
    {
        $jose = str_repeat("\x80", 32) . "\0\0" . str_repeat("\x01", 30);
        $der  = JwkConverter::joseToDer($jose, 32);
        self::assertNotNull($der);
        self::assertSame($jose, JwkConverter::derToJose($der, 32));
        self::assertNull(JwkConverter::joseToDer('short', 32));

        // Long-form lengths (P-521) round trip as well.
        $long = str_repeat("\x81", 66) . str_repeat("\x7f", 66);
        self::assertSame($long, JwkConverter::derToJose((string) JwkConverter::joseToDer($long, 66), 66));

        self::assertNull(JwkConverter::derToJose('', 32), 'empty');
        self::assertNull(JwkConverter::derToJose("\x31\x00", 32), 'not a sequence');
        self::assertNull(JwkConverter::derToJose("\x30\x05\x02\x01\x01", 32), 'sequence length past the end');
        self::assertNull(JwkConverter::derToJose("\x30\x03\x02\x01\x01", 32), 'only one integer');
        self::assertNull(JwkConverter::derToJose("\x30\x06\x02\x01\x01\x03\x01\x01", 32), 'second item not an integer');
        self::assertNull(JwkConverter::derToJose("\x30\x06\x02\x05\x01\x02\x01\x01", 32), 'integer past the end');
        self::assertNull(JwkConverter::derToJose("\x30\x80\x02\x01\x01", 32), 'indefinite length');
        self::assertNull(JwkConverter::derToJose("\x30\x83\x00\x00\x06", 32), 'length of more than two bytes');
        self::assertNull(JwkConverter::derToJose("\x30\x82\x00", 32), 'truncated long length');
        self::assertNull(JwkConverter::derToJose("\x30\x26\x02\x21" . str_repeat("\x01", 33) . "\x02\x01\x01", 32), 'integer longer than the curve');
        self::assertNull(JwkConverter::derToJose("\x30\x07\x02\x01\x01\x02\x01\x01\x00", 32), 'trailing bytes');
        self::assertSame(48, JwkConverter::curveLength('ES384'));
        self::assertSame(66, JwkConverter::curveLength('ES512'));
        self::assertSame(32, JwkConverter::curveLength('ES256'));
    }
}
