<?php

declare(strict_types=1);

namespace Nowo\AuthKitBundle\Tests\Support\Saml;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

use function openssl_csr_new;
use function openssl_csr_sign;
use function openssl_pkey_export;
use function openssl_pkey_new;
use function openssl_x509_export;

use const OPENSSL_KEYTYPE_RSA;

/**
 * Throwaway RSA key pairs and self-signed certificates for the SAML tests (2.10.0), generated at test time: no key is
 * committed. Kept for the test process only (generation is slow); never used outside the tests.
 */
final class SamlKeys
{
    /** @var array<string, array{key: string, cert: string}> */
    private static array $pairs = []; // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (test-only cache of throwaway keys)

    /**
     * @return array{key: string, cert: string} PEM private key and certificate of a named throwaway pair
     */
    public static function pair(string $name, int $days = 365): array
    {
        return self::$pairs[$name . ':' . $days] ??= self::generate($name, $days); // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (test-only cache)
    }

    /**
     * @return array{key: string, cert: string}
     */
    private static function generate(string $name, int $days): array
    {
        $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key     = openssl_pkey_new($options);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('No test key.');
        }
        $csr = openssl_csr_new(['commonName' => $name . '.test'], $key, $options);
        if (!$csr instanceof OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('No test CSR.');
        }
        $cert = openssl_csr_sign($csr, null, $key, $days, $options);
        if (!$cert instanceof OpenSSLCertificate || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem)) {
            throw new RuntimeException('No test certificate.');
        }

        return ['key' => $keyPem, 'cert' => $certPem];
    }
}
