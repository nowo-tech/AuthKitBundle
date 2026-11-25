<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Oidc;

use Nowo\AuthKitBundle\SocialLogin\Oidc\Base64Url;
use Nowo\AuthKitBundle\SocialLogin\Oidc\JwkConverter;
use OpenSSLAsymmetricKey;
use RuntimeException;

use function is_array;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function str_pad;

use const JSON_THROW_ON_ERROR;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_ALGO_SHA384;
use const OPENSSL_ALGO_SHA512;
use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_KEYTYPE_RSA;
use const STR_PAD_LEFT;

/**
 * Key pairs generated for the tests only (never a real secret in the repository), their JWKs, and a JWS signer.
 */
final class TestKeys
{
    /**
     * Generated once per test run (RSA generation is slow); test-only, never loaded by a worker.
     *
     * @var array<string, OpenSSLAsymmetricKey>
     */
    private static array $keys = []; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty

    public static function rsa(string $name = 'rsa', int $bits = 2048): OpenSSLAsymmetricKey
    {
        return self::$keys[$name . $bits] ??= // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
             self::generate(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => $bits]);
    }

    public static function ec(string $curve = 'prime256v1', string $name = 'ec'): OpenSSLAsymmetricKey
    {
        return self::$keys[$name . $curve] ??= // @phpstan-ignore frankenphp.worker.noMutableStaticProperty
             self::generate(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
    }

    public static function pem(OpenSSLAsymmetricKey $key): string
    {
        $pem = '';
        openssl_pkey_export($key, $pem);

        return (string) $pem;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public static function jwk(OpenSSLAsymmetricKey $key, string $kid, array $extra = []): array
    {
        $details = openssl_pkey_get_details($key);
        if (!is_array($details)) {
            throw new RuntimeException('No key details.');
        }
        if (isset($details['rsa'])) {
            return ['kty' => 'RSA', 'kid' => $kid, 'n' => Base64Url::encode($details['rsa']['n']), 'e' => Base64Url::encode($details['rsa']['e'])] + $extra;
        }
        $crv = match ($details['ec']['curve_name']) {
            'secp384r1' => 'P-384',
            'secp521r1' => 'P-521',
            default     => 'P-256',
        };
        $length = match ($crv) {
            'P-384' => 48,
            'P-521' => 66,
            default => 32,
        };

        return [
            'kty' => 'EC',
            'kid' => $kid,
            'crv' => $crv,
            'x'   => Base64Url::encode(str_pad($details['ec']['x'], $length, "\0", STR_PAD_LEFT)),
            'y'   => Base64Url::encode(str_pad($details['ec']['y'], $length, "\0", STR_PAD_LEFT)),
        ] + $extra;
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function sign(array $header, array $claims, OpenSSLAsymmetricKey $key): string
    {
        $input  = Base64Url::encode(json_encode($header, JSON_THROW_ON_ERROR)) . '.' . Base64Url::encode(json_encode($claims, JSON_THROW_ON_ERROR));
        $alg    = (string) ($header['alg'] ?? 'RS256');
        $digest = match (substr($alg, 2)) {
            '384'   => OPENSSL_ALGO_SHA384,
            '512'   => OPENSSL_ALGO_SHA512,
            default => OPENSSL_ALGO_SHA256,
        };
        $signature = '';
        openssl_sign($input, $signature, $key, $digest);
        if (str_starts_with($alg, 'ES')) {
            $signature = (string) JwkConverter::derToJose((string) $signature, JwkConverter::curveLength($alg));
        }

        return $input . '.' . Base64Url::encode((string) $signature);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function generate(array $options): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new($options);
        if ($key === false) {
            throw new RuntimeException('Could not generate a test key.');
        }

        return $key;
    }
}
